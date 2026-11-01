<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

use AgenToolsPlatform\Core\Profiles\{BundleState, UseBoundary};
use AgenToolsPlatform\Postgres\Runtime\StorageFailure;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

require_once __DIR__ . '/Worker.php';

final class RecoveryTest extends DatabaseCase
{
    private function recovered(): Fixture
    {
        return new Fixture((new IsolatedDatabase())->pdo, $this->schema);
    }

    /** Both independent backend connections must actually wait for our lock before release. */
    private function competing(Worker $a, Worker $b): void
    {
        self::assertNotSame($a->backend, $b->backend); $a->go(); $b->go();
        $monitor = (new IsolatedDatabase())->pdo;
        for ($i = 0; $i < 500; ++$i) {
            $s = $monitor->query('SELECT count(*) FROM pg_stat_activity WHERE pid IN (' . $a->backend . ',' . $b->backend . ") AND wait_event_type='Lock'");
            if ($s !== false && (int) $s->fetchColumn() === 2) { self::assertTrue($this->database->pdo->commit()); return; }
            usleep(10000);
        }
        self::fail('Both profile workers must compete on a held database lock');
    }

    public function testConcurrentPublish(): void
    {
        $f = $this->f; $m = $f->core->manifest(); $c = $f->context();
        $f->bundles->draft($c, $m); $f->bundles->validate($c, $m->bundle, 1);
        $this->database->pdo->beginTransaction(); $this->database->pdo->query('SELECT 1 FROM profile_bundles FOR UPDATE');
        $a = new Worker($this->schema, 'publish'); $b = new Worker($this->schema, 'publish'); $this->competing($a, $b);
        $one = $a->read(); $two = $b->read(); $exits = [$a->finish(), $b->finish()]; self::assertSame([0,0], $exits);
        self::assertContains($one['status'], ['committed','retry']); self::assertContains($two['status'], ['committed','retry']);
        self::assertSame(BundleState::Published, $f->bundles->publish($c, $m->bundle, 2)->state);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_bundles'));
        Evidence::put('profiles.concurrent-publish', ['workers' => 2, 'backends' => [$a->backend,$b->backend], 'competed' => true, 'exits' => $exits, 'rows' => 1]);
    }

    public function testConcurrentPin(): void
    {
        $f = $this->f; $f->publish(); $f->assign();
        $this->database->pdo->beginTransaction();
        $s = $this->database->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
        $s->execute(['key' => json_encode([$f->core->tenant->value,'profile-session','session'], JSON_THROW_ON_ERROR)]);
        $a = new Worker($this->schema, 'pin'); $b = new Worker($this->schema, 'pin'); $this->competing($a, $b);
        $one = $a->read(); $two = $b->read(); $exits = [$a->finish(),$b->finish()]; self::assertSame([0,0], $exits);
        self::assertSame($one, $two); self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_session_versions'));
        Evidence::put('profiles.concurrent-pin', ['workers' => 2, 'backends' => [$a->backend,$b->backend], 'competed' => true, 'exits' => $exits, 'same_pin' => $one === $two, 'rows' => 1]);
    }

    public function testPublicationCrashRecovery(): void
    {
        $f = $this->f; $m = $f->core->manifest(); $c = $f->context(); $f->bundles->draft($c, $m); $f->bundles->validate($c, $m->bundle, 1);
        $w = new Worker($this->schema, 'publish-pause'); $w->go(); $saved = $w->read(); $crash = $w->kill();
        self::assertTrue($crash['signaled']); self::assertSame(9, $crash['signal']);
        $r = $this->recovered(); $result = $r->bundles->publish($r->context(), $m->bundle, 2);
        self::assertSame($m->bundle->toArray(), $result->manifest->bundle->toArray()); self::assertSame(3, $result->revision);
        self::assertSame($saved['bundle'], $result->manifest->bundle->toArray());
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_bundles'));
        Evidence::put('profiles.publish-sigkill-recovery', $crash + ['committed' => $saved['status'], 'same_hash' => $saved['bundle'] === $result->manifest->bundle->toArray(), 'rows' => 1]);
    }

    public function testPinCrashRecovery(): void
    {
        $f = $this->f; $f->publish(); $f->publish(8); $f->assign();
        $w = new Worker($this->schema, 'pin-pause'); $w->go(); $saved = $w->read(); $crash = $w->kill();
        self::assertTrue($crash['signaled']); self::assertSame(9, $crash['signal']);
        $f->assign(8, 1, 'assign-new'); $r = $this->recovered(); $pin = $r->sessions->pin($r->context(), $r->instance());
        self::assertSame(7, $pin->bundle->version); self::assertSame(1, $pin->revision);
        self::assertSame($saved['bundle'], $pin->bundle->toArray());
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_session_versions'));
        Evidence::put('profiles.pin-sigkill-recovery', $crash + ['committed' => $saved['status'], 'same_hash' => $saved['bundle'] === $pin->bundle->toArray(), 'rows' => 1]);
    }

    public function testMigrationCrashRecovery(): void
    {
        $f = $this->f; $f->publish(); $f->publish(8); $f->assign(); $f->sessions->pin($f->context(), $f->instance());
        $w = new Worker($this->schema, 'migrate-pause'); $w->go(); $saved = $w->read(); $crash = $w->kill();
        self::assertTrue($crash['signaled']); self::assertSame(9, $crash['signal']);
        $r = $this->recovered(); $pin = $r->sessions->migrate($r->context(), $r->ref('migration-recovery'), 1, $r->core->manifest(8)->bundle, 'recover upgrade');
        self::assertSame(8, $pin->bundle->version); self::assertSame(2, $pin->revision);
        self::assertSame($saved['bundle'], $pin->bundle->toArray());
        $r->sessions->assertPin($r->context(), $pin, UseBoundary::Action);
        self::assertSame(2, $this->scalar('SELECT count(*) FROM profile_session_versions'));
        Evidence::put('profiles.migration-sigkill-recovery', $crash + ['committed' => $saved['status'], 'same_hash' => $saved['bundle'] === $pin->bundle->toArray(), 'rows' => 2]);
    }

    public function testMigrationCommitFailureAtomic(): void
    {
        $f = $this->f; $f->publish(); $f->publish(8); $f->assign(); $c = $f->context(); $f->sessions->pin($c, $f->instance());
        $this->database->pdo->exec('CREATE FUNCTION fail_profile_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'commit fault\'; END $$;
            CREATE CONSTRAINT TRIGGER fail_profile_commit AFTER UPDATE ON profile_session_heads DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_profile_commit()');
        $this->rejects(fn () => $f->sessions->migrate($c, $f->ref('migration'), 1, $f->core->manifest(8)->bundle, 'retry upgrade'), StorageFailure::class);
        self::assertFalse($this->database->pdo->inTransaction());
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_session_versions'));
        self::assertSame(0, $this->scalar("SELECT count(*) FROM profile_commands WHERE id='migration'"));
        self::assertSame(1, $this->scalar('SELECT revision FROM profile_session_heads'));
        $this->database->pdo->exec('DROP TRIGGER fail_profile_commit ON profile_session_heads');
        self::assertSame(2, $f->sessions->migrate($c, $f->ref('migration'), 1, $f->core->manifest(8)->bundle, 'retry upgrade')->revision);
        Evidence::put('profiles.migration-commit-rollback', ['history_rolled_back' => true, 'head_rolled_back' => true, 'receipt_rolled_back' => true, 'retry_revision' => 2]);
    }
}
