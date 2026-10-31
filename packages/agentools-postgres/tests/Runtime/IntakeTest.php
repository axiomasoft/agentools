<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Runtime;

use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, CanonicalJson, ContractViolation, ErrorCode, IdentityKeys, IntegrityConflict, PayloadHash, Ref, RunId, RunStatus, TenantId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, RunRepository, SessionBinding, SessionRepository, StorageFailure, TurnRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class IntakeTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $fixture;
    private ExecutionContext $context;
    private IntakeRepository $intake;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->fixture = new Fixture($this->database->pdo, $this->schema, new TenantId('tenant-a'));
        $this->context = $this->fixture->context();
        (new SessionRepository($this->fixture->environment))->bind($this->context, $this->fixture->binding($this->context));
        $this->intake = new IntakeRepository($this->fixture->environment);
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->pdo->inTransaction()) {
                $this->database->pdo->rollBack();
            }
            $this->database->pdo->query('SELECT pg_advisory_unlock_all()');
            $this->database->dropSchema($this->schema);
        }
    }

    private function rowCount(string $table): int
    {
        $statement = $this->database->pdo->query('SELECT count(*) FROM "' . $this->schema . '"."' . $table . '"');
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertIsInt($value);
        return $value;
    }

    private function accept(string $content = 'a'): \AgenToolsPlatform\Core\Common\AcceptedTurn
    {
        return $this->intake->accept($this->fixture->input($this->context, $content), $this->fixture->snapshot($this->context));
    }

    private function assertOneIntake(): void
    {
        foreach (['turns','runs','inbox','outbox'] as $table) {
            self::assertSame(1, $this->rowCount($table), $table);
        }
        foreach (['invocation_intents','proposal_map','effect_intents','delivery_intents'] as $table) {
            self::assertSame(0, $this->rowCount($table), $table);
        }
    }

    /** @param callable(): mixed $operation */
    private function violation(callable $operation, ErrorCode $code): void
    {
        try {
            $operation();
            self::fail('Expected ' . $code->value);
        } catch (ContractViolation $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    /** @param callable(): mixed $operation */
    private function conflict(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected integrity conflict');
        } catch (IntegrityConflict $error) {
            self::assertNull($error->getPrevious());
        }
    }

    public function testConcurrentSameSource(): void
    {
        // Both real PHP processes reach the INSERT trigger while a parent-held
        // advisory barrier blocks them. Release only after observing both waits.
        $this->database->pdo->exec("CREATE FUNCTION intake_barrier() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            PERFORM pg_advisory_xact_lock(hashtext(TG_TABLE_SCHEMA), 321); RETURN NEW; END $$");
        $this->database->pdo->exec('CREATE TRIGGER intake_barrier BEFORE INSERT ON turns FOR EACH ROW EXECUTE FUNCTION intake_barrier()');
        $this->database->pdo->query("SELECT pg_advisory_lock(hashtext('" . $this->schema . "'),321)");
        $prefix = 'intake_' . $this->schema;
        $first = new WorkerProcess($this->schema, 'accept', $prefix . '_a');
        $second = new WorkerProcess($this->schema, 'accept', $prefix . '_b');
        try {
            $first->go();
            $second->go();
            $deadline = hrtime(true) + 10_000_000_000;
            $waiting = 0;
            while (hrtime(true) < $deadline) {
                $statement = $this->database->pdo->prepare("SELECT count(*) FROM pg_stat_activity
                    WHERE datname=current_database() AND application_name LIKE :prefix AND wait_event='advisory'");
                $statement->execute(['prefix' => $prefix . '%']);
                $waiting = $statement->fetchColumn();
                if ($waiting === 2) {
                    break;
                }
                usleep(1000); // Poll an observable barrier, not a time-based race schedule.
            }
            self::assertSame(2, $waiting, 'Both processes must overlap before barrier release');
        } finally {
            $this->database->pdo->query("SELECT pg_advisory_unlock(hashtext('" . $this->schema . "'),321)");
        }
        $a = $first->finish();
        $b = $second->finish();
        self::assertSame(0, $a['exit'], $a['stderr']);
        self::assertSame(0, $b['exit'], $b['stderr']);
        self::assertSame(json_decode($a['stdout'], true, 32, JSON_THROW_ON_ERROR), json_decode($b['stdout'], true, 32, JSON_THROW_ON_ERROR));
        $this->assertOneIntake();
    }

    public function testSourceHashConflict(): void
    {
        $original = $this->accept();
        $this->conflict(fn () => $this->accept('b'));
        self::assertSame($original->toArray(), $this->accept()->toArray());
        $this->assertOneIntake();
    }

    public function testConflictResponseLost(): void
    {
        $original = $this->accept();
        $this->conflict(fn () => $this->accept('b'));
        // Discard that conflict response; retry from a newly composed store/connection.
        $reconnected = new IsolatedDatabase();
        $fresh = new Fixture($reconnected->pdo, $this->schema, $this->fixture->tenant);
        $context = $fresh->context();
        $this->conflict(fn () => (new IntakeRepository($fresh->environment))->accept($fresh->input($context, 'b'), $fresh->snapshot($context)));
        self::assertSame($original->toArray(), $this->accept()->toArray());
        $this->assertOneIntake();
    }

    public function testStableIdentityRoundtrip(): void
    {
        $input = $this->fixture->input($this->context);
        $receipt = $this->accept();
        $keys = new IdentityKeys($this->fixture->hasher);
        self::assertSame($keys->turn($input->source)->value, $receipt->turnId->value);
        self::assertSame($keys->run($receipt->turnId)->value, $receipt->runId->value);
        $turn = (new TurnRepository($this->fixture->environment))->get($this->context, $receipt->turnId);
        self::assertNotNull($turn);
        self::assertSame($receipt->payloadHash->value, $turn->payloadHash->value);
        self::assertSame($input->payload->inputRef->value, $turn->inputRef->value);
        $run = (new RunRepository($this->fixture->environment))->get($this->context, $receipt->runId);
        self::assertNotNull($run);
        self::assertEquals($input->context->toArray(), $run->checkpoint['accepted_context']);
        self::assertEquals($input->payload->toArray(), $run->checkpoint['input']);
        self::assertEquals($this->fixture->snapshot($this->context)->toArray(), $run->checkpoint['accepted_snapshot']);
        // Preserve existing typed identity forms. Only intake persistence is implemented here;
        // proposal/effect/delivery/occurrence owners remain their declared later slices.
        $invocation = $keys->invocation($receipt->runId, 'step', 1);
        $action = new ActionId($this->fixture->tenant, 'dated opaque action');
        $identities = [$keys->proposal($invocation, 'provider-id', null)->toArray(),
            $keys->effectKey($action, 0)->value, $keys->delivery($receipt->runId, 1, new Ref($this->fixture->tenant, 'chatom:room'))->toArray(),
            $keys->usage($invocation, 1)->toArray(), $keys->occurrence(new Ref($this->fixture->tenant, 'schedule'), new UtcInstant('2026-10-31T10:00:00Z'))->value];
        $statement = $this->database->pdo->prepare('SELECT CAST(:refs AS jsonb)::text');
        $statement->execute(['refs' => json_encode($identities, JSON_THROW_ON_ERROR)]);
        $encoded = $statement->fetchColumn();
        self::assertIsString($encoded);
        self::assertEquals($identities, json_decode($encoded, true, 32, JSON_THROW_ON_ERROR));
        $replay = $this->accept();
        $sameInvocation = $keys->invocation($replay->runId, 'step', 1);
        self::assertSame($identities, [$keys->proposal($sameInvocation, 'provider-id', null)->toArray(),
            $keys->effectKey($action, 0)->value, $keys->delivery($replay->runId, 1, new Ref($this->fixture->tenant, 'chatom:room'))->toArray(),
            $keys->usage($sameInvocation, 1)->toArray(), $keys->occurrence(new Ref($this->fixture->tenant, 'schedule'), new UtcInstant('2026-10-31T10:00:00Z'))->value]);
        self::assertSame($receipt->toArray(), $replay->toArray());
    }

    public function testIdentitySchemeConflict(): void
    {
        $original = $this->accept();
        $changed = new class implements CanonicalJson {
            public function canonicalize(mixed $value): string { return (new TestCanonicalJson())->canonicalize($value) . ' '; }
            public function canonicalizeJson(string $json): string { return (new TestCanonicalJson())->canonicalizeJson($json) . ' '; }
        };
        $hasher = new CanonicalHasher($changed);
        $environment = new RepositoryContext($this->fixture->environment->db, $hasher, $this->fixture->host, $this->fixture->revisions);
        $snapshot = new \AgenToolsPlatform\Core\Context\ContextSnapshot(new Ref($this->fixture->tenant, 'snapshot'), 1,
            new PayloadHash('sha-256:' . str_repeat('c', 64)), $this->context->scopeHash($hasher));
        $this->conflict(fn () => (new IntakeRepository($environment))->accept($this->fixture->input($this->context), $snapshot));
        self::assertSame($original->toArray(), $this->accept()->toArray());
        $this->assertOneIntake();
    }

    public function testCommitBeforeLostWakeup(): void
    {
        $worker = new WorkerProcess($this->schema, 'crash', 'intake_crash_' . $this->schema);
        $worker->go();
        $result = $worker->finish();
        self::assertSame(73, $result['exit'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        $this->assertOneIntake();
        $statement = $this->database->pdo->query('SELECT id,aggregate_ref,status,claimed_until FROM outbox');
        self::assertNotFalse($statement);
        $saved = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($saved);
        self::assertSame('pending', $saved['status']);
        self::assertNull($saved['claimed_until']);
        $receipt = $this->accept();
        self::assertSame($receipt->runId->value, $saved['aggregate_ref']);
        self::assertSame('run.accepted:' . $receipt->runId->value, $saved['id']);
        $this->assertOneIntake();
    }

    public function testSessionAndInstanceScope(): void
    {
        $first = $this->accept();
        $otherContext = $this->fixture->context('other-session');
        $sessions = new SessionRepository($this->fixture->environment);
        $sessions->bind($otherContext, $this->fixture->binding($otherContext, 'other-instance'));
        $other = $this->intake->accept($this->fixture->input($otherContext, 'a', 'event', 'other-instance'), $this->fixture->snapshot($otherContext));
        self::assertNotSame($first->turnId->value, $other->turnId->value);
        self::assertSame(2, $this->rowCount('turns'));
        $alias = $this->fixture->context('alias-session');
        $sessions->bind($alias, $this->fixture->binding($alias));
        $this->conflict(fn () => $this->intake->accept($this->fixture->input($alias), $this->fixture->snapshot($alias)));
        $this->violation(fn () => $this->intake->accept($this->fixture->input($otherContext), $this->fixture->snapshot($otherContext)), ErrorCode::AccessDenied);
        $binding = $this->fixture->binding($this->context, 'unused-instance');
        $this->conflict(fn () => $sessions->bind($this->context, $binding));
        self::assertSame(2, $this->rowCount('instances')); // The failed binding's INSERT rolled back.
        self::assertSame('instance', $sessions->get($this->context)?->instance->value);
    }

    public function testTenantWorkerResetAndAccess(): void
    {
        $receipt = $this->accept();
        $tenantB = new TenantId('tenant-b');
        $this->fixture->host->tenant = $tenantB;
        $contextB = ExecutionContext::fromHost($this->fixture->host, new \AgenToolsPlatform\Core\Common\SessionId($tenantB, 'session'), 'assist.read', new \AgenToolsPlatform\Core\Security\Correlation('trace-b','request-b'));
        $runs = new RunRepository($this->fixture->environment);
        $turns = new TurnRepository($this->fixture->environment);
        self::assertNull($runs->get($contextB, new RunId($tenantB, $receipt->runId->value)));
        self::assertNull($turns->get($contextB, new TurnId($tenantB, $receipt->turnId->value)));
        self::assertNull((new SessionRepository($this->fixture->environment))->get($contextB));
        $this->violation(fn () => $runs->get($contextB, $receipt->runId), ErrorCode::AccessDenied);
        $this->violation(fn () => $turns->get($contextB, $receipt->turnId), ErrorCode::AccessDenied);
        $this->violation(fn () => $this->accept(), ErrorCode::AccessDenied);
        $this->fixture->host->tenant = $this->fixture->tenant;
        self::assertSame($receipt->toArray(), $this->accept()->toArray());
        $this->fixture->host->denied = true;
        $this->violation(fn () => $runs->get($this->context, $receipt->runId), ErrorCode::AccessDenied);
        $this->violation(fn () => $this->accept(), ErrorCode::AccessDenied);
        $this->assertOneIntake();
    }

    public function testStaleContextBeforeAdvance(): void
    {
        $receipt = $this->accept();
        $runs = new RunRepository($this->fixture->environment);
        $snapshot = $this->fixture->snapshot($this->context);
        $this->fixture->revisions->revision = 2;
        $this->violation(fn () => $runs->beginPreparation($this->context, $snapshot, $receipt->runId, 0), ErrorCode::StaleContext);
        $this->violation(fn () => $this->intake->accept($this->fixture->input($this->context), $snapshot), ErrorCode::StaleContext);
        self::assertSame(0, $runs->get($this->context, $receipt->runId)?->version);
        $snapshot = $this->fixture->snapshot($this->context);
        $this->fixture->host->policy = 'policy-2';
        $this->violation(fn () => $runs->beginPreparation($this->context, $snapshot, $receipt->runId, 0), ErrorCode::StaleContext);
        $freshContext = $this->fixture->context();
        $advanced = $runs->beginPreparation($freshContext, $this->fixture->snapshot($freshContext), $receipt->runId, 0);
        self::assertSame(1, $advanced->version);
        self::assertSame(RunStatus::Preparing, $advanced->status);
        self::assertSame(0, $advanced->fencingEpoch);
        $this->violation(fn () => $runs->beginPreparation($freshContext, $this->fixture->snapshot($freshContext), $receipt->runId, 0), ErrorCode::OptimisticConflict);
        self::assertSame($receipt->toArray(), $this->intake->accept($this->fixture->input($freshContext), $this->fixture->snapshot($freshContext))->toArray());
    }

    public function testAtomicRollbackAtEveryBoundary(): void
    {
        foreach (['runs','inbox','outbox'] as $table) {
            $this->database->pdo->exec("CREATE FUNCTION intake_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'private diagnostic never public'; END $$");
            $this->database->pdo->exec("CREATE TRIGGER intake_fault AFTER INSERT ON $table FOR EACH ROW EXECUTE FUNCTION intake_fault()");
            try {
                $this->accept();
                self::fail('Injected transaction fault succeeded');
            } catch (StorageFailure $error) {
                self::assertSame('PostgreSQL persistence unavailable', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
            foreach (['turns','runs','inbox','outbox'] as $changed) {
                self::assertSame(0, $this->rowCount($changed), $table . ' -> ' . $changed);
            }
            self::assertFalse($this->database->pdo->inTransaction());
            $this->database->pdo->exec("DROP TRIGGER intake_fault ON $table; DROP FUNCTION intake_fault()");
        }
        $this->accept();
        $this->assertOneIntake();
    }

    public function testActiveRunAndCallerTransactionBoundary(): void
    {
        $receipt = $this->accept();
        $this->conflict(fn () => $this->fixture->environment->db->execute('INSERT INTO ' . $this->fixture->environment->db->table('runs') . '(tenant_id,id,turn_id)
            VALUES (:tenant,:id,:turn)', ['tenant'=>$this->fixture->tenant->value,'id'=>'second-active','turn'=>$receipt->turnId->value]));
        $calls = $this->fixture->host->calls;
        $this->database->pdo->beginTransaction();
        try {
            $this->accept();
            self::fail('Caller transaction accepted');
        } catch (\LogicException $error) {
            self::assertStringContainsString('caller transactions', $error->getMessage());
        }
        self::assertSame($calls, $this->fixture->host->calls);
        self::assertTrue($this->database->pdo->inTransaction());
        $this->database->pdo->rollBack();
        // Pooled search_path cannot redirect the repositories to another tenant/schema.
        $this->database->pdo->exec('SET search_path TO public');
        self::assertSame($receipt->toArray(), $this->accept()->toArray());
    }
}
