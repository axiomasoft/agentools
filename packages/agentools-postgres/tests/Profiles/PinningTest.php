<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Core\Profiles\UseBoundary;
use PDOException;

final class PinningTest extends DatabaseCase
{
    public function testNewAssignmentsPreserveActivePin(): void
    {
        $f = $this->f; $seven = $f->publish(); $eight = $f->publish(8); $f->assign();
        $c = $f->context(); $pin = $f->sessions->pin($c, $f->instance());
        self::assertSame(7, $pin->bundle->version);
        self::assertSame(2, $f->assign(8, 1, 'assignment-eight'));
        self::assertSame($seven->bundle->toArray(), $f->sessions->pin($c, $f->instance())->bundle->toArray());
        self::assertSame($eight->bundle->toArray(), $f->sessions->pin($f->context('new-session'), $f->instance())->bundle->toArray());
        self::assertSame(1, $pin->revision);
        $this->rejects(fn () => $f->sessions->pin($c, $f->instance('other')), ContractViolation::class);
    }

    public function testAssignmentCasReplayAndConflict(): void
    {
        $f = $this->f; $f->publish(); $f->publish(8);
        self::assertSame(1, $f->assign()); self::assertSame(1, $f->assign());
        $this->rejects(fn () => $f->assign(8), IntegrityConflict::class);
        $this->rejects(fn () => $f->assign(8, 0, 'stale'), ContractViolation::class);
        self::assertSame(2, $f->assign(8, 1, 'next'));
        self::assertSame(1, $f->assign());
        $f->authority->denied = true; $this->rejects(fn () => $f->assign(), ContractViolation::class);
    }

    public function testCurrentGuardsOverrideEveryPin(): void
    {
        $f = $this->f; $f->publish(); $f->assign(); $c = $f->context(); $pin = $f->sessions->pin($c, $f->instance());
        foreach ([UseBoundary::Invocation, UseBoundary::Action, UseBoundary::Disclosure] as $boundary) {
            $f->sessions->assertPin($c, $pin, $boundary);
            $f->authority->emergency = true;
            $this->rejects(fn () => $f->sessions->assertPin($c, $pin, $boundary), ContractViolation::class);
            $f->authority->emergency = false;
        }
        $f->authority->revoked = true;
        $this->rejects(fn () => $f->sessions->pin($c, $f->instance()), ContractViolation::class);
        $f->authority->revoked = false; $f->host->revision = 'policy-2';
        $this->rejects(fn () => $f->sessions->assertPin($c, $pin, UseBoundary::Action), ContractViolation::class);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_session_versions'));
    }

    public function testExplicitMigrationWithAuditAndReplay(): void
    {
        $f = $this->f; $f->publish(); $target = $f->publish(8); $f->assign(); $c = $f->context();
        $old = $f->sessions->pin($c, $f->instance());
        $pin = $f->sessions->migrate($c, $f->ref('migration'), 1, $target->bundle, 'owner approved upgrade');
        self::assertSame(2, $pin->revision); self::assertSame(8, $pin->bundle->version);
        self::assertSame($pin->bundle->toArray(), $f->sessions->migrate($c, $f->ref('migration'), 1, $target->bundle, 'owner approved upgrade')->bundle->toArray());
        self::assertSame(2, $this->scalar('SELECT count(*) FROM profile_session_versions'));
        self::assertSame('validation:session-compatibility', $this->scalar('SELECT compatibility_ref FROM profile_session_versions WHERE revision=2'));
        self::assertSame('actor-a', $this->scalar('SELECT actor_ref FROM profile_session_versions WHERE revision=2'));
        $this->rejects(fn () => $f->sessions->assertPin($c, $old, UseBoundary::Action), ContractViolation::class);
        $this->rejects(fn () => $f->sessions->migrate($c, $f->ref('migration'), 1, $target->bundle, 'different reason'), IntegrityConflict::class);
        $this->rejects(fn () => $f->sessions->migrate($c, $f->ref('stale'), 1, $target->bundle, 'stale source'), ContractViolation::class);
        $this->rejects(fn () => $this->database->pdo->exec("UPDATE profile_session_versions SET reason='changed' WHERE revision=2"), PDOException::class);
        $this->rejects(fn () => $this->database->pdo->exec('UPDATE profile_session_heads SET revision=1'), PDOException::class);
        $this->rejects(fn () => $this->database->pdo->exec('DELETE FROM profile_session_heads'), PDOException::class);
        $this->rejects(fn () => $this->database->pdo->exec('DELETE FROM profile_assignments'), PDOException::class);
    }

    public function testMigrationCompatibilityAndTenantDenied(): void
    {
        $f = $this->f; $f->publish(); $target = $f->publish(8); $f->assign(); $c = $f->context(); $f->sessions->pin($c, $f->instance());
        $f->authority->incompatible = true;
        $this->rejects(fn () => $f->sessions->migrate($c, $f->ref('migrate'), 1, $target->bundle, 'upgrade'), ContractViolation::class);
        $f->authority->incompatible = false;
        $this->rejects(fn () => $f->sessions->migrate($c, $f->ref('migrate'), 1, $target->bundle, ''), \InvalidArgumentException::class);
        $other = new Fixture($this->database->pdo, $this->schema, 'tenant-b');
        $this->rejects(fn () => $other->sessions->pin($other->context(), $f->instance()), ContractViolation::class);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM profile_session_versions'));
    }
}
