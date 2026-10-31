<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Actions;

use AgenTools\Action\{ActionEvent, ActionJournal, EventIdCollision, EffectRecord, VersionConflict};
use AgenTools\Effects\{EffectApplier, EffectCommitter};
use AgenTools\Persistence\PdoPostgresEventStore;
use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Postgres\Actions\Projection;
use AgenToolsPlatform\Postgres\Claims\ClaimRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\StorageFailure;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class ActionJournalTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $fixture;
    private int $ownerCalls = 0;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->fixture = new Fixture($this->database, $this->schema);
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->pdo->inTransaction()) {
                $this->database->pdo->rollBack();
            }
            $this->database->dropSchema($this->schema);
        }
    }

    private function append(): Projection
    {
        $f = $this->fixture;
        return $f->actions->append($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, 0, $f->events(), [$f->intent()]);
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->database->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    private function countRows(string $table): int
    {
        $count = $this->scalar('SELECT count(*) FROM "' . $this->schema . '"."' . $table . '"');
        self::assertIsInt($count);
        return $count;
    }

    /** @return array<string, mixed> */
    private function current(): array
    {
        $row = $this->fixture->runtime->environment->db->row('SELECT * FROM "' . $this->schema . '".action_current');
        self::assertNotNull($row);
        return $row;
    }

    /** @param class-string<\Throwable> $type @param callable(): mixed $operation */
    private function throws(string $type, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected ' . $type);
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    /** Test-only owner composition: the storage gate is checked before reaching owner IO. */
    private function owner(EffectRecord $effect): void
    {
        $f = $this->fixture;
        $saved = $f->actions->committedIntent($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, $effect->effectId);
        self::assertFalse($this->database->pdo->inTransaction());
        self::assertSame($f->intent()->ownerOperationKey, $saved->request->ownerOperationKey);
        self::assertSame($f->intent()->requestHash->value, $saved->request->requestHash->value);
        self::assertSame($f->intent()->requestRef->value, $saved->request->requestRef->value);
        self::assertSame(1, $saved->claimEpoch);
        self::assertArrayHasKey('execution_context', $saved->authorizationContext);
        // Visibility and NOWAIT locks from a new connection prove actual commit,
        // not an in-memory append or an AppendResult inside a caller transaction.
        $observer = new IsolatedDatabase();
        $observer->pdo->beginTransaction();
        try {
            foreach (['agentools_events','action_current','effect_intents','outbox','runs'] as $table) {
                $statement = $observer->pdo->query('SELECT id FROM "' . $this->schema . '"."' . $table . '" FOR UPDATE NOWAIT');
                self::assertNotFalse($statement);
                self::assertIsString($statement->fetchColumn(), $table);
            }
        } finally {
            $observer->pdo->rollBack();
        }
        ++$this->ownerCalls;
    }

    public function testDurableIntentBeforeEffect(): void
    {
        $events = [];
        foreach ($this->fixture->events() as $i => $event) {
            $events[] = new ActionEvent($event->eventId, $this->fixture->action->value, $i+1, $i,
                $event->timestamp, $event->actor, $event->kind, $event->eventVersion, $event->specRevision, $event->payload, $event->epoch);
        }
        $applier = new class(\Closure::fromCallable($this->owner(...))) implements EffectApplier {
            public function __construct(private \Closure $apply) {}
            public function apply(EffectRecord $effect): void { ($this->apply)($effect); }
        };
        $this->throws(IntegrityConflict::class, fn () => (new EffectCommitter())->commit(
            new ActionJournal(array_slice($events, 0, 3)), $events[3], $applier));
        self::assertSame(0, $this->ownerCalls, 'In-memory append is not commit proof');
        $db = $this->fixture->runtime->environment->db;
        $this->throws(ContractViolation::class, fn () => $db->transaction(fn () => $db->journal()->appendInTransaction(
            'tenant-a', $this->fixture->action->value, 0, $this->fixture->events())));
        self::assertSame(0, $this->countRows('agentools_events'), 'Direct participant cannot commit without projection/intent/outbox');
        foreach (['agentools_events','action_current','effect_intents','outbox'] as $table) {
            $this->fault($table);
            try {
                $this->throws(StorageFailure::class, fn () => $this->append());
            } finally {
                $this->dropFault($table);
            }
            foreach (['agentools_events','agentools_event_streams','action_current','effect_intents'] as $atomic) {
                self::assertSame(0, $this->countRows($atomic));
            }
            self::assertSame(1, $this->countRows('outbox'), 'Only upstream intake wake remains');
        }
        // Fail at COMMIT after every statement ran, rather than only at INSERT.
        $this->fault('agentools_events', true);
        try {
            $this->throws(StorageFailure::class, fn () => $this->append());
        } finally {
            $this->dropFault('agentools_events');
        }
        self::assertSame(0, $this->countRows('agentools_events'));
        self::assertSame(0, $this->countRows('effect_intents'));
        self::assertSame(0, $this->countRows('action_current'));
        self::assertSame(1, $this->countRows('outbox'));
        self::assertSame(0, $this->ownerCalls);
    }

    public function testCommittedIntentOutsideLock(): void
    {
        // Deliberately change pool search_path: adapter SQL and trigger paths are explicit/pinned.
        $this->database->pdo->exec('SET search_path TO pg_catalog');
        $projection = $this->append();
        self::assertSame(4, $projection->state->seq);
        self::assertSame(4, $this->countRows('agentools_events'));
        self::assertSame(5, $this->countRows('outbox'));
        $this->owner($projection->state->effects['effect:one']);
        self::assertSame(1, $this->ownerCalls);
    }

    public function testProjectionRebuild(): void
    {
        $original = $this->append();
        $f = $this->fixture;
        $unknown = $f->event('a1000000000000000000000000000005', 'vendor.observed', ['evidence_ref' => 'encrypted:evidence']);
        $observed = $f->actions->append($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, 4, [$unknown]);
        self::assertSame($original->state->status, $observed->state->status);
        self::assertEquals($original->state->effects, $observed->state->effects);
        self::assertSame($original->state->epoch, $observed->state->epoch);
        $resolved = $f->event('a1000000000000000000000000000006', 'effect_confirmed',
            ['effect_id' => 'effect:one', 'external_ref' => 'owner:receipt:one'], 1);
        $projection = $f->actions->append($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, 5, [$resolved]);
        self::assertSame('success', $this->scalar('SELECT outcome FROM "' . $this->schema . '".effect_intents'));
        $this->database->pdo->exec('DELETE FROM "' . $this->schema . '".action_current');
        $rebuilt = $f->actions->rebuild($f->context, $f->action, $projection->digest());
        self::assertSame($projection->json(), $rebuilt->json());
        self::assertSame($projection->digest(), $this->current()['digest']);
        self::assertSame(6, $this->countRows('agentools_events'));
        self::assertSame(1, $this->countRows('action_projection_rebuilds'));
        self::assertSame(0, $this->ownerCalls);
        self::assertSame('vendor.observed', $f->runtime->environment->db->journal()->load('tenant-a', $f->action->value, 4, 1)->events[0]->kind);
    }

    public function testDatedJournalAtomicReplay(): void
    {
        $beforeRun = $this->scalar('SELECT checkpoint::text FROM "' . $this->schema . '".runs');
        $projection = $this->append();
        $saved = $this->current();
        self::assertSame($projection->digest(), $this->append()->digest());
        self::assertSame($saved, $this->current());
        self::assertSame(4, $this->countRows('agentools_events'));
        self::assertSame(1, $this->countRows('effect_intents'));
        self::assertSame(5, $this->countRows('outbox'));
        self::assertSame($beforeRun, $this->scalar('SELECT checkpoint::text FROM "' . $this->schema . '".runs'));
        self::assertSame($this->fixture->action->value, $this->scalar('SELECT action_ref FROM "' . $this->schema . '".run_steps'));
        $page = $this->fixture->runtime->environment->db->journal()->load('tenant-a', $this->fixture->action->value, 0, 2);
        self::assertTrue($page->hasMore);
        self::assertSame([1,2], array_map(static fn (ActionEvent $e): int => $e->seq, $page->events));
        self::assertSame('2026-10-31T14:00:00.1234567+03:00', $page->events[0]->timestamp);
    }

    public function testRejectMutableOrUncommittedState(): void
    {
        $projection = $this->append();
        $f = $this->fixture;
        $db = $f->runtime->environment->db;
        $before = $this->current();
        $this->throws(ContractViolation::class, fn () => $db->execute('UPDATE ' . $db->table('action_current') . '
            SET state=state || CAST(:state AS jsonb),version=version+1', ['state' => '{"status":"completed"}']));
        $this->throws(IntegrityConflict::class, fn () => $db->execute('UPDATE ' . $db->table('agentools_events') . ' SET kind=\'action_completed\''));
        $this->throws(IntegrityConflict::class, fn () => $db->execute('DELETE FROM ' . $db->table('agentools_events')));
        $this->throws(IntegrityConflict::class, fn () => $db->execute('UPDATE ' . $db->table('effect_intents') . ' SET version=version+1,request_ref=\'substituted\''));
        $collision = $f->events();
        $collision[0] = $f->event($collision[0]->eventId, 'action_created', ['changed_ref' => 'encrypted:other']);
        $this->throws(EventIdCollision::class, fn () => $f->actions->append($f->context, $f->runtime->snapshot($f->context),
            $f->claim, $f->action, 0, $collision, [$f->intent()]));
        $this->throws(VersionConflict::class, fn () => $f->actions->append($f->context, $f->runtime->snapshot($f->context),
            $f->claim, $f->action, 0, [$f->event('new-id', 'vendor.observed')]));
        $f->runtime->host->denied = true;
        $this->throws(ContractViolation::class, fn () => $this->owner($projection->state->effects['effect:one']));
        $f->runtime->host->denied = false;
        $this->database->pdo->beginTransaction();
        try {
            $this->throws(\LogicException::class, fn () => $this->owner($projection->state->effects['effect:one']));
            self::assertTrue($this->database->pdo->inTransaction());
        } finally {
            $this->database->pdo->rollBack();
        }
        self::assertSame($before, $this->current());
        self::assertSame(0, $this->ownerCalls);
        // Recovery advances the Action epoch but never repeats the old owner request.
        $db->execute('UPDATE ' . $db->table('runs') . ' SET version=version+1,lease_until=clock_timestamp()-interval \'1 microsecond\'');
        $new = (new ClaimRepository($f->runtime->environment))->claim($f->context, $f->runtime->snapshot($f->context),
            $f->claim->state->id, $f->claim->state->version+1);
        self::assertNotNull($new);
        $f->actions->append($f->context, $f->runtime->snapshot($f->context), $new, $f->action, 4,
            [$f->event('epoch-two', 'epoch_started', [], 2)]);
        $this->throws(\AgenTools\Action\StaleExecutorEpoch::class, fn () => $f->actions->append($f->context,
            $f->runtime->snapshot($f->context), $new, $f->action, 5,
            [$f->event('late-outcome', 'effect_confirmed', ['effect_id' => 'effect:one'], 1)]));
        $this->throws(ContractViolation::class, fn () => $f->actions->committedIntent($f->context,
            $f->runtime->snapshot($f->context), $new, $f->action, 'effect:one'));
        $this->throws(ContractViolation::class, fn () => $f->actions->append($f->context, $f->runtime->snapshot($f->context),
            $f->claim, $f->action, 5, [$f->event('late-worker', 'vendor.observed')]));
        self::assertSame(5, $this->countRows('agentools_events'));
        self::assertSame('pending', $this->scalar('SELECT outcome FROM "' . $this->schema . '".effect_intents'));
    }

    private function fault(string $table, bool $deferred = false): void
    {
        $this->database->pdo->exec('CREATE FUNCTION "' . $this->schema . '".test_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'fault\'; END $$');
        $this->database->pdo->exec('CREATE ' . ($deferred ? 'CONSTRAINT ' : '') . 'TRIGGER test_fault AFTER INSERT ON "'
            . $this->schema . '"."' . $table . '" ' . ($deferred ? 'DEFERRABLE INITIALLY DEFERRED ' : '')
            . 'FOR EACH ROW EXECUTE FUNCTION "' . $this->schema . '".test_fault()');
    }

    private function dropFault(string $table): void
    {
        $this->database->pdo->exec('DROP TRIGGER test_fault ON "' . $this->schema . '"."' . $table . '"');
        $this->database->pdo->exec('DROP FUNCTION "' . $this->schema . '".test_fault()');
    }

    public function testRollbackAndCrashRecovery(): void
    {
        $worker = new WorkerProcess($this->schema, 'crash');
        $worker->go();
        $result = $worker->finish();
        self::assertSame(73, $result['exit'], $result['stderr']);
        self::assertSame(0, $this->ownerCalls);
        $f = $this->fixture;
        $projection = $this->append(); // Receipt loss: identical replay inserts nothing.
        $before = $this->current();
        $this->throws(IntegrityConflict::class, fn () => $f->actions->rebuild($f->context, $f->action, 'sha-256:' . str_repeat('0',64)));
        self::assertSame(0, $this->countRows('action_projection_rebuilds'));
        $this->fault('action_projection_rebuilds');
        try {
            $this->throws(StorageFailure::class, fn () => $f->actions->rebuild($f->context, $f->action, $projection->digest()));
        } finally {
            $this->dropFault('action_projection_rebuilds');
        }
        self::assertSame($before, $this->current());
        self::assertSame(4, $this->countRows('agentools_events'));
        $this->owner($projection->state->effects['effect:one']);
        self::assertSame(1, $this->ownerCalls);
    }

    public function testConcurrentAppendAndScope(): void
    {
        $f = $this->fixture;
        $f->actions->append($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, 0, [$f->events()[0]]);
        $first = new WorkerProcess($this->schema, 'a');
        $second = new WorkerProcess($this->schema, 'b');
        $this->database->pdo->beginTransaction();
        try {
            $this->database->pdo->query('SELECT action_id FROM "' . $this->schema . '".agentools_event_streams FOR UPDATE');
            $first->go(); $second->go();
            $query = $this->database->pdo->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database()
                AND application_name LIKE :prefix AND wait_event_type='Lock'");
            $deadline = hrtime(true)+10_000_000_000;
            do {
                $query->execute(['prefix' => 'journal_' . $this->schema . '%']);
                $waiting = $query->fetchColumn();
                if ($waiting === 2) { break; }
                usleep(1000);
            } while (hrtime(true)<$deadline);
            self::assertSame(2, $waiting, 'Both processes must overlap at SQL barriers');
        } finally {
            $this->database->pdo->rollBack();
        }
        $a = $first->finish(); $b = $second->finish();
        $exits = [$a['exit'],$b['exit']]; sort($exits);
        self::assertSame([0,8], $exits, $a['stderr'] . $b['stderr']);
        self::assertSame(2, $this->countRows('agentools_events'));
        $other = new \AgenToolsPlatform\Postgres\Tests\Runtime\Fixture($this->database->pdo, $this->schema,
            new \AgenToolsPlatform\Core\Common\TenantId('tenant-b'));
        $context = $other->context();
        $digest = $this->current()['digest'];
        self::assertIsString($digest);
        $this->throws(ContractViolation::class, fn () => $f->actions->rebuild($context, $f->action, $digest));
        $foreignSession = $f->runtime->context('foreign-session');
        $this->throws(ContractViolation::class, fn () => $f->actions->rebuild($foreignSession, $f->action, 'unused'));
        $this->throws(ContractViolation::class, fn () => $f->runtime->environment->db->execute('INSERT INTO '
            . $f->runtime->environment->db->table('agentools_event_streams') . '(tenant_id,action_id) VALUES (\'tenant-b\',\'action:one\')'));
    }

    public function testParticipantAndSchemaQualification(): void
    {
        $schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($schema);
        try {
            $sql = file_get_contents(dirname(__DIR__,4) . '/fixtures/hardening/sql/event-store.v1.sql');
            self::assertIsString($sql);
            $this->database->pdo->exec($sql);
            $store = new PdoPostgresEventStore($this->database->pdo);
            $event = $this->fixture->events()[0];
            $store->append('tenant-a','standalone',0,[$event]);
            self::assertFalse($this->database->pdo->inTransaction());
            $this->throws(\LogicException::class, fn () => $store->appendInTransaction('tenant-a','other',0,[$event]));
            $participant = $this->fixture->event('participant', 'action_created');
            $this->database->pdo->beginTransaction();
            $result = $store->appendInTransaction('tenant-a','participant',0,[$participant]);
            self::assertTrue($result->appended());
            self::assertTrue($this->database->pdo->inTransaction());
            $observer = new IsolatedDatabase();
            $query = $observer->pdo->query('SELECT count(*) FROM "' . $schema . '".agentools_events');
            self::assertNotFalse($query);
            self::assertSame(1,$query->fetchColumn(), 'Uncommitted participant result is invisible');
            $this->database->pdo->rollBack();
            self::assertCount(0,$store->load('tenant-a','participant')->events);
            $this->database->pdo->beginTransaction();
            $this->throws(\PDOException::class, fn () => $store->append('tenant-a','nested',0,[$participant]));
            self::assertTrue($this->database->pdo->inTransaction());
            $this->database->pdo->rollBack();
            $this->database->pdo->exec('SET search_path TO pg_catalog');
            $qualified = new PdoPostgresEventStore($this->database->pdo, schema: $schema);
            $qualified->append('tenant-a','qualified',0,[$participant]);
            self::assertCount(1,$qualified->load('tenant-a','qualified')->events);
            $this->throws(\InvalidArgumentException::class, fn () => new PdoPostgresEventStore($this->database->pdo, schema: 'unsafe".public'));
        } finally {
            if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
            $this->database->dropSchema($schema);
            $this->database->pdo->exec('SET search_path TO "' . $this->schema . '",pg_catalog');
        }
    }

    public function testCorruptionAndUnknownRemainUnresolved(): void
    {
        $this->append();
        $f = $this->fixture;
        $db = $f->runtime->environment->db;
        $unknown = $f->event('unknown-evidence', 'vendor.observed', ['evidence_ref' => 'encrypted:evidence']);
        $projection = $f->actions->append($f->context, $f->runtime->snapshot($f->context), $f->claim, $f->action, 4, [$unknown]);
        $db->execute('UPDATE ' . $db->table('effect_intents') . ' SET version=version+1,outcome=\'unknown\'');
        $rebuilt = $f->actions->rebuild($f->context, $f->action, $projection->digest());
        self::assertSame($projection->json(), $rebuilt->json());
        self::assertSame('unknown', $this->scalar('SELECT outcome FROM "' . $this->schema . '".effect_intents'));
        $this->throws(ContractViolation::class, fn () => $f->actions->committedIntent($f->context,
            $f->runtime->snapshot($f->context), $f->claim, $f->action, 'effect:one'));
        // Isolated test corruption only: normal UPDATE/DELETE are rejected above.
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events DISABLE TRIGGER append_only');
        $db->execute('UPDATE ' . $db->table('agentools_events') . ' SET content_hash=:hash WHERE seq=5', ['hash' => 'sha-256:' . str_repeat('0',64)]);
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events ENABLE TRIGGER append_only');
        $this->throws(IntegrityConflict::class, fn () => $f->actions->rebuild($f->context, $f->action, $projection->digest()));
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events DISABLE TRIGGER append_only');
        $hash = (new \AgenTools\Persistence\EventStoreSupport())->contentHash('tenant-a', $f->action->value, $unknown);
        $db->execute('UPDATE ' . $db->table('agentools_events') . ' SET content_hash=:hash,prev_hash=:previous WHERE seq=5',
            ['hash' => $hash, 'previous' => 'sha-256:' . str_repeat('0',64)]);
        $this->database->pdo->exec('ALTER TABLE "' . $this->schema . '".agentools_events ENABLE TRIGGER append_only');
        $this->throws(\AgenTools\Action\JournalIntegrityException::class,
            fn () => $f->actions->rebuild($f->context, $f->action, $projection->digest()));
        self::assertSame($projection->digest(), $this->current()['digest']);
        self::assertSame(0, $this->ownerCalls);
    }
}
