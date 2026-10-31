<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Concurrency;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, ContractViolation, ErrorCode, RunId, RunStatus, TenantId};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository, RunClaim, Scanner};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Outbox\{OutboxClaim, OutboxRepository, Relay, WakeSignal};
use AgenToolsPlatform\Postgres\Runtime\{RunRepository, SessionRepository, StorageFailure, StoredRun};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\{Fixture, WorkerProcess};
use PDO;
use PHPUnit\Framework\TestCase;

final class ClaimsOutboxTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $fixture;
    private ExecutionContext $context;
    private ClaimRepository $claims;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->fixture = new Fixture($this->database->pdo, $this->schema, new TenantId('tenant-a'));
        $this->context = $this->fixture->context();
        (new SessionRepository($this->fixture->environment))->bind($this->context, $this->fixture->binding($this->context));
        $this->claims = new ClaimRepository($this->fixture->environment);
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

    private function accept(): AcceptedTurn
    {
        return (new IntakeRepository($this->fixture->environment))->accept(
            $this->fixture->input($this->context), $this->fixture->snapshot($this->context));
    }

    private function claim(RunId $run, int $version = 0): RunClaim
    {
        return $this->claims->claim($this->context, $this->fixture->snapshot($this->context), $run, $version)
            ?? throw new \RuntimeException('Expected current claim');
    }

    /** @return array<string, mixed> */
    private function row(string $table): array
    {
        $statement = $this->database->pdo->query('SELECT * FROM "' . $this->schema . '"."' . $table . '"');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function countRows(string $table): int
    {
        $statement = $this->database->pdo->query('SELECT count(*) FROM "' . $this->schema . '"."' . $table . '"');
        self::assertNotFalse($statement);
        $count = $statement->fetchColumn();
        self::assertIsInt($count);
        return $count;
    }

    /** @param callable(): mixed $operation */
    private function violation(callable $operation, ErrorCode $code = ErrorCode::StaleClaim): void
    {
        try {
            $operation();
            self::fail('Expected ' . $code->value);
        } catch (ContractViolation $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    private function awaitExpiry(string $until): void
    {
        $query = $this->database->pdo->prepare('SELECT clock_timestamp() >= CAST(:until AS timestamptz) AS expired');
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $query->execute(['until' => $until]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['expired'] === true) {
                return;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        self::fail('DB lease did not expire');
    }

    /** Prove another connection can acquire the actual row while external work is paused. */
    private function assertUnlocked(string $table): void
    {
        self::assertFalse($this->database->pdo->inTransaction());
        $observer = new IsolatedDatabase();
        $observer->pdo->beginTransaction();
        try {
            $observer->pdo->exec("SET LOCAL lock_timeout='250ms'");
            $query = $observer->pdo->query('SELECT id FROM "' . $this->schema . '"."' . $table . '" FOR UPDATE NOWAIT');
            self::assertNotFalse($query);
            self::assertIsString($query->fetchColumn());
        } finally {
            $observer->pdo->rollBack();
        }
    }

    public function testLostWakeScanner(): void
    {
        // Separate process commits Tx A and dies before publishing or returning the receipt.
        $worker = new WorkerProcess($this->schema, 'crash', 'claims_intake');
        $worker->go();
        $result = $worker->finish();
        self::assertSame(73, $result['exit'], $result['stderr']);
        $candidates = (new Scanner($this->fixture->environment))->scan($this->context, 1);
        self::assertCount(1, $candidates);
        self::assertSame(0, $candidates[0]['version']);
        self::assertGreaterThanOrEqual(0, $candidates[0]['age_seconds']);
        self::assertFalse($candidates[0]['recovery_required']);
        $claim = $this->claim($candidates[0]['run']);
        $saved = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
            RunStatus::Preparing, ['step' => 'read']);
        self::assertSame($this->accept()->runId->value, $saved->state->id->value);
        self::assertCount(0, (new Scanner($this->fixture->environment))->scan($this->context));
        self::assertSame(1, $this->countRows('turns'));
        self::assertSame(1, $this->countRows('runs'));
        self::assertSame(1, $this->countRows('outbox'));
    }

    public function testTwoWorkerStaleFence(): void
    {
        $accepted = $this->accept();
        $original = $this->row('runs');
        $first = new BarrierWorker($this->schema, 'stale');
        $paused = json_decode($first->line(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($paused);
        self::assertSame(1, $paused['epoch']);
        self::assertTrue($paused['io_outside_tx']);
        $this->assertUnlocked('runs');
        self::assertIsString($paused['until']);
        $this->awaitExpiry($paused['until']);
        $second = new BarrierWorker($this->schema, 'takeover');
        $current = json_decode($second->line(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($current);
        self::assertSame(2, $current['epoch']);
        self::assertSame('owner:immutable', $current['owner_operation_key']);
        self::assertSame('unknown', $current['outcome']);
        $result = $second->finish();
        self::assertSame(0, $result['exit'], $result['stderr']);
        $beforeLate = $this->row('runs');
        $first->go();
        self::assertSame('stale_claim', $first->line());
        $result = $first->finish();
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame($beforeLate, $this->row('runs'));
        self::assertSame($accepted->runId->value, $beforeLate['id']);
        self::assertSame($original['turn_id'], $beforeLate['turn_id']);
        $stored = (new RunRepository($this->fixture->environment))->get($this->context, $accepted->runId);
        self::assertNotNull($stored);
        $progress = $stored->checkpoint['progress'];
        self::assertIsArray($progress);
        self::assertSame('unknown', $progress['outcome']);
        self::assertSame(0, $this->countRows('effect_intents'), 'Lease expiry grants no owner effect retry');
    }

    public function testCommittedWake(): void
    {
        $accepted = $this->accept();
        $outbox = new OutboxRepository($this->fixture->environment);
        $calls = 0;
        $relay = new Relay($this->fixture->environment, $outbox);
        self::assertTrue($relay->once($this->context, function (WakeSignal $signal) use ($accepted, &$calls): void {
            ++$calls;
            $this->assertUnlocked('outbox');
            self::assertSame($accepted->runId->value, $signal->run->value);
            self::assertSame('run.accepted:' . $accepted->runId->value, $signal->eventKey);
            $claim = $this->claim($signal->run);
            $this->assertUnlocked('runs');
            $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
                RunStatus::Preparing, ['read' => 'started']);
        }));
        self::assertFalse($relay->once($this->context, static function (): void { self::fail('Repeated publish'); }));
        self::assertSame(1, $calls);
        self::assertSame('dispatched', $this->row('outbox')['status']);
        self::assertSame(1, $this->countRows('turns'));
        self::assertSame(2, $this->row('runs')['version']);
    }

    public function testDuplicateAndRolledBackWake(): void
    {
        $accepted = $this->accept();
        $claim = $this->claim($accepted->runId);
        $saved = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
            RunStatus::Preparing, []);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertNull($this->claims->claim($this->context, $this->fixture->snapshot($this->context), $accepted->runId, 0));
            $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
                $claim, RunStatus::Preparing, []));
        }
        $this->database->pdo->exec('CREATE FUNCTION intake_abort() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            RAISE EXCEPTION \'intake fault\'; END $$');
        $this->database->pdo->exec('CREATE TRIGGER intake_abort BEFORE INSERT ON outbox FOR EACH ROW EXECUTE FUNCTION intake_abort()');
        $input = $this->fixture->input($this->context, 'a', 'rolled-back');
        try {
            (new IntakeRepository($this->fixture->environment))->accept($input, $this->fixture->snapshot($this->context));
            self::fail('Expected rollback');
        } catch (StorageFailure) {
            self::assertFalse($this->database->pdo->inTransaction());
        }
        $missing = (new \AgenToolsPlatform\Core\Common\IdentityKeys($this->fixture->hasher))->run(
            (new \AgenToolsPlatform\Core\Common\IdentityKeys($this->fixture->hasher))->turn($input->source));
        self::assertNull($this->claims->claim($this->context, $this->fixture->snapshot($this->context), $missing, 0));
        self::assertSame(1, $this->countRows('turns'));
        self::assertSame(1, $this->countRows('runs'));
        self::assertSame(1, $this->countRows('outbox'));
        self::assertSame($saved->state->version, $this->row('runs')['version']);
    }

    public function testCurrentEpochCheckpoint(): void
    {
        $accepted = $this->accept();
        $original = (new RunRepository($this->fixture->environment))->get($this->context, $accepted->runId);
        self::assertNotNull($original);
        $claim = $this->claim($accepted->runId);
        self::assertSame(1, $claim->state->version);
        self::assertSame(1, $claim->state->fencingEpoch);
        $this->assertUnlocked('runs');
        $next = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
            RunStatus::Preparing, ['result_ref' => 'encrypted:read']);
        self::assertSame(2, $next->state->version);
        self::assertSame($claim->owner, $next->owner);
        self::assertSame($original->checkpoint['accepted_context'], $next->state->checkpoint['accepted_context']);
        self::assertSame($original->checkpoint['source_key'], $next->state->checkpoint['source_key']);
        $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $claim, RunStatus::Preparing, []));
        $beat = $this->claims->heartbeat($this->context, $this->fixture->snapshot($this->context), $next);
        self::assertSame(3, $beat->state->version);
        self::assertSame($next->state->fencingEpoch, $beat->state->fencingEpoch);
        $running = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $beat, RunStatus::Running, []);
        $terminal = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $running,
            RunStatus::Completed, ['response_ref' => 'encrypted:response'], 'read_complete');
        self::assertNull($terminal->leaseUntil);
        self::assertNull($this->row('runs')['claim_owner']);
        self::assertNull($this->claims->claim($this->context, $this->fixture->snapshot($this->context), $accepted->runId, $terminal->state->version));
    }

    public function testAtomicStorageAndTenantConstraints(): void
    {
        $accepted = $this->accept();
        foreach (['turns','runs','inbox','outbox'] as $table) {
            self::assertSame(1, $this->countRows($table));
        }
        $claim = $this->claim($accepted->runId);
        $statement = $this->database->pdo->query('SELECT EXTRACT(microseconds FROM lease_until)::bigint % 1000000
            FROM "' . $this->schema . '".runs');
        self::assertNotFalse($statement);
        $fraction = $statement->fetchColumn();
        self::assertIsInt($fraction);
        self::assertGreaterThan(0, $fraction, 'Real PostgreSQL clock preserves fractional lease time');
        $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim, RunStatus::Preparing, []);
        $this->violation(fn () => $this->fixture->environment->db->execute('INSERT INTO '
            . $this->fixture->environment->db->table('runs') . '(tenant_id,id,turn_id) VALUES (:tenant,:id,:turn)',
            ['tenant' => 'tenant-b', 'id' => 'cross-tenant', 'turn' => $accepted->turnId->value]), ErrorCode::InvalidInput);
        self::assertSame(1, $this->countRows('runs'));
        $this->assertUnlocked('runs');
        self::assertSame($accepted->toArray(), $this->accept()->toArray());
    }

    public function testFenceDimensionsAndAuthorization(): void
    {
        $accepted = $this->accept();
        $claim = $this->claim($accepted->runId);
        $state = $claim->state;
        foreach (['version','epoch','owner','run'] as $dimension) {
            $altered = new StoredRun($dimension === 'run' ? new RunId($state->id->tenant, 'missing') : $state->id,
                $state->turn, $state->status, $state->version + ($dimension === 'version' ? 1 : 0),
                $state->fencingEpoch + ($dimension === 'epoch' ? 1 : 0), $state->checkpoint);
            $wrong = new RunClaim($altered, $dimension === 'owner' ? 'wrong-token' : $claim->owner, $claim->leaseUntil);
            $this->violation(fn () => $this->claims->heartbeat($this->context, $this->fixture->snapshot($this->context), $wrong));
            $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $wrong,
                RunStatus::Preparing, []));
        }
        $other = new Fixture($this->database->pdo, $this->schema, new TenantId('tenant-b'));
        $otherContext = $other->context();
        $this->violation(fn () => $this->claims->heartbeat($otherContext, $other->snapshot($otherContext), $claim), ErrorCode::AccessDenied);
        $otherSession = $this->fixture->context('other-session');
        $this->violation(fn () => $this->claims->heartbeat($otherSession, $this->fixture->snapshot($otherSession), $claim));
        $this->fixture->host->denied = true;
        $this->violation(fn () => $this->claims->heartbeat($this->context, $this->fixture->snapshot($this->context), $claim), ErrorCode::AccessDenied);
        $this->fixture->host->denied = false;
        $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
            RunStatus::Completed, [], 'illegal'), ErrorCode::InvalidInput);
        $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim,
            RunStatus::Preparing, ['oversized' => str_repeat('x', 17000)]), ErrorCode::InvalidInput);
        self::assertSame(1, $this->row('runs')['version']);
        $this->database->pdo->beginTransaction();
        try {
            $this->claims->claim($this->context, $this->fixture->snapshot($this->context), $accepted->runId, 1);
            self::fail('Nested transaction must be refused');
        } catch (\LogicException) {
            self::assertTrue($this->database->pdo->inTransaction());
        } finally {
            $this->database->pdo->rollBack();
        }
    }

    private function fault(string $table): void
    {
        $this->database->pdo->exec('CREATE FUNCTION fault_' . $table . '() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            RAISE EXCEPTION \'injected fault\'; END $$');
        $this->database->pdo->exec('CREATE TRIGGER injected_fault AFTER UPDATE ON "' . $this->schema . '"."' . $table
            . '" FOR EACH ROW EXECUTE FUNCTION fault_' . $table . '()');
    }

    /** @param callable(): mixed $operation */
    private function assertRollback(string $table, callable $operation): void
    {
        $before = $this->row($table);
        $this->fault($table);
        try {
            $operation();
            self::fail('Expected injected fault');
        } catch (StorageFailure) {
            self::assertFalse($this->database->pdo->inTransaction());
            self::assertSame($before, $this->row($table));
        } finally {
            $this->database->pdo->exec('DROP TRIGGER injected_fault ON "' . $this->schema . '"."' . $table . '"');
            $this->database->pdo->exec('DROP FUNCTION fault_' . $table . '()');
        }
    }

    public function testRollbackAndRelayCrash(): void
    {
        $accepted = $this->accept();
        $this->assertRollback('runs', fn () => $this->claim($accepted->runId));
        $claim = $this->claim($accepted->runId);
        $this->assertRollback('runs', fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $claim, RunStatus::Preparing, ['result_ref' => 'encrypted:read']));
        $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $claim, RunStatus::Preparing, []);
        $outbox = new OutboxRepository($this->fixture->environment);
        $this->assertRollback('outbox', fn () => $outbox->claim($this->context));
        $crashed = new BarrierWorker($this->schema, 'relay-crash');
        $published = json_decode($crashed->line(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($published);
        $result = $crashed->finish();
        self::assertSame(73, $result['exit'], $result['stderr']);
        self::assertIsString($published['until']);
        $this->awaitExpiry($published['until']);
        $retry = $outbox->claim($this->context);
        self::assertNotNull($retry);
        self::assertSame($published['event_key'], $retry->signal->eventKey);
        self::assertSame(2, $retry->attempt);
        self::assertSame(2, $retry->epoch);
        $old = new OutboxClaim($retry->id, $retry->signal, $retry->version - 1, $retry->epoch - 1,
            'stale', $retry->attempt - 1, $published['until']);
        $this->violation(fn () => $outbox->dispatched($this->context, $old));
        $this->assertRollback('outbox', fn () => $outbox->dispatched($this->context, $retry));
        $outbox->dispatched($this->context, $retry);
        $this->violation(fn () => $outbox->dispatched($this->context, $retry));
        self::assertSame('dispatched', $this->row('outbox')['status']);
        self::assertSame(1, $this->countRows('outbox'));
        self::assertSame(1, $this->countRows('turns'));
    }

    public function testBoundedRecoveryAndDeadletter(): void
    {
        $accepted = $this->accept();
        $policy = new ClaimPolicy(100, 1);
        $claims = new ClaimRepository($this->fixture->environment, $policy);
        $claim = $claims->claim($this->context, $this->fixture->snapshot($this->context), $accepted->runId, 0);
        self::assertNotNull($claim);
        self::assertNotNull($claim->leaseUntil);
        $this->awaitExpiry($claim->leaseUntil);
        $this->violation(fn () => $claims->heartbeat($this->context, $this->fixture->snapshot($this->context), $claim));
        $this->violation(fn () => $claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $claim, RunStatus::Preparing, []));
        $scan = (new Scanner($this->fixture->environment, $policy))->scan($this->context);
        self::assertCount(1, $scan);
        self::assertTrue($scan[0]['recovery_required']);
        $this->violation(fn () => $claims->claim($this->context, $this->fixture->snapshot($this->context),
            $accepted->runId, $claim->state->version), ErrorCode::ManualResolutionRequired);
        $this->database->pdo->exec('INSERT INTO "' . $this->schema . '".outbox
            (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until,max_attempts)
            SELECT tenant_id,\'bounded\',\'bounded\',payload_hash,event_type,aggregate_ref,deadline_at,retention_until,1
            FROM "' . $this->schema . '".outbox');
        $outbox = new OutboxRepository($this->fixture->environment, 1000, 1);
        $first = $outbox->claim($this->context);
        self::assertNotNull($first);
        $outbox->unknown($this->context, $first);
        $second = $outbox->claim($this->context);
        self::assertNotNull($second);
        self::assertSame('bounded', $second->id);
        $outbox->unknown($this->context, $second);
        $statement = $this->database->pdo->query('SELECT status FROM "' . $this->schema . '".outbox WHERE id=\'bounded\'');
        self::assertNotFalse($statement);
        self::assertSame('deadletter', $statement->fetchColumn());
        self::assertSame(0, $outbox->deadletter($this->context));
        self::assertSame(2, $this->countRows('outbox'), 'Deadletter retains unresolved transport evidence');
        $input = $this->fixture->input($this->context, 'a', 'run-deadline');
        $another = (new IntakeRepository($this->fixture->environment))->accept($input, $this->fixture->snapshot($this->context));
        $deadlineClaims = new ClaimRepository($this->fixture->environment, new ClaimPolicy(10000, 8, 1));
        $live = $deadlineClaims->claim($this->context, $this->fixture->snapshot($this->context), $another->runId, 0);
        self::assertNotNull($live);
        $statement = $this->database->pdo->prepare('SELECT (created_at+interval \'1 second\')::text FROM "' . $this->schema . '".runs WHERE id=:id');
        $statement->execute(['id' => $another->runId->value]);
        $deadline = $statement->fetchColumn();
        self::assertIsString($deadline);
        $this->awaitExpiry($deadline);
        $this->violation(fn () => $deadlineClaims->heartbeat($this->context, $this->fixture->snapshot($this->context), $live));
        $this->violation(fn () => $deadlineClaims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $live, RunStatus::Preparing, []));
        $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $live, RunStatus::Preparing, [0 => 'unnamed']), ErrorCode::InvalidInput);
    }

    public function testRetrySameKeyAndCurrentGuards(): void
    {
        $accepted = $this->accept();
        $outbox = new OutboxRepository($this->fixture->environment, 10000, 1);
        $relay = new Relay($this->fixture->environment, $outbox);
        $seen = [];
        try {
            $relay->once($this->context, function (WakeSignal $signal) use (&$seen): void {
                $seen[] = [$signal->eventKey, $signal->payloadHash];
                $this->assertUnlocked('outbox');
                $this->claim($signal->run); // Receiver accepted wake before transport acknowledgement was lost.
                throw new \RuntimeException('publish acknowledgement lost');
            });
            self::fail('Expected transport failure');
        } catch (\RuntimeException $error) {
            self::assertSame('publish acknowledgement lost', $error->getMessage());
        }
        $unknown = $this->row('outbox');
        self::assertSame('unknown', $unknown['status']);
        self::assertIsString($unknown['available_at']);
        $this->awaitExpiry($unknown['available_at']);
        self::assertTrue($relay->once($this->context, function (WakeSignal $signal) use (&$seen): void {
            $seen[] = [$signal->eventKey, $signal->payloadHash];
            self::assertNull($this->claims->claim($this->context, $this->fixture->snapshot($this->context), $signal->run, 0));
        }));
        self::assertCount(2, $seen);
        self::assertSame($seen[0], $seen[1]);
        self::assertSame(1, $this->countRows('turns'));
        self::assertSame(1, $this->row('runs')['version']);
        // New short-lived wake exercises deadline deadletter without changing immutable policy fields.
        $this->database->pdo->exec('INSERT INTO "' . $this->schema . '".outbox
            (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until,max_attempts)
            SELECT tenant_id,\'deadline\',\'deadline\',payload_hash,event_type,aggregate_ref,
                clock_timestamp()+interval \'50 milliseconds\',retention_until,1 FROM "' . $this->schema . '".outbox');
        $current = $outbox->claim($this->context);
        self::assertNotNull($current);
        $this->fixture->host->denied = true;
        $this->violation(fn () => $outbox->dispatched($this->context, $current), ErrorCode::AccessDenied);
        $this->fixture->host->denied = false;
        $foreign = $this->fixture->context('foreign-session');
        $this->violation(fn () => $outbox->dispatched($foreign, $current));
        // Simulate crash on the last attempt. Scanner must wait for the live lease before quarantining.
        self::assertSame(0, $outbox->deadletter($this->context));
        $this->database->pdo->exec('UPDATE "' . $this->schema . '".outbox SET version=version+1,
            claimed_until=clock_timestamp()-interval \'1 microsecond\' WHERE id=\'deadline\'');
        self::assertSame(1, $outbox->deadletter($this->context));
        self::assertNull($outbox->claim($this->context));
        self::assertSame(2, $this->countRows('outbox'));
        self::assertSame($accepted->runId->value, $this->row('runs')['id']);
    }

    public function testSkipLockedAndAuthoritativeEvidence(): void
    {
        $accepted = $this->accept();
        $observer = new IsolatedDatabase();
        $outbox = new OutboxRepository($this->fixture->environment);
        foreach (['runs','outbox'] as $table) {
            $observer->pdo->beginTransaction();
            try {
                $observer->pdo->query('SELECT id FROM "' . $this->schema . '"."' . $table . '" FOR UPDATE');
                if ($table === 'runs') {
                    self::assertNull($this->claims->claim($this->context, $this->fixture->snapshot($this->context), $accepted->runId, 0));
                } else {
                    self::assertNull($outbox->claim($this->context));
                }
            } finally {
                $observer->pdo->rollBack();
            }
        }
        $claim = $this->claim($accepted->runId);
        $original = $claim->state->checkpoint;
        $altered = new StoredRun($claim->state->id, $claim->state->turn, RunStatus::Running,
            $claim->state->version, $claim->state->fencingEpoch, ['source_key' => 'substituted']);
        $substituted = new RunClaim($altered, $claim->owner, $claim->leaseUntil);
        $this->violation(fn () => $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context),
            $substituted, RunStatus::Completed, [], 'fabricated'), ErrorCode::InvalidInput);
        $saved = $this->claims->checkpoint($this->context, $this->fixture->snapshot($this->context), $substituted,
            RunStatus::Preparing, []);
        self::assertSame($original['source_key'], $saved->state->checkpoint['source_key']);
        self::assertSame($original['accepted_snapshot'], $saved->state->checkpoint['accepted_snapshot']);
        $transport = $outbox->claim($this->context);
        self::assertNotNull($transport);
        $outbox->dispatched($this->context, $transport);
        $this->fixture->environment->db->execute('INSERT INTO ' . $this->fixture->environment->db->table('outbox') . '
            (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
            SELECT tenant_id,\'expired\',\'expired\',payload_hash,event_type,aggregate_ref,
                clock_timestamp()+interval \'100 milliseconds\',retention_until
            FROM ' . $this->fixture->environment->db->table('outbox') . ' WHERE id=:source', ['source' => $transport->id]);
        $statement = $this->database->pdo->query('SELECT deadline_at FROM "' . $this->schema . '".outbox WHERE id=\'expired\'');
        self::assertNotFalse($statement);
        $deadline = $statement->fetchColumn();
        self::assertIsString($deadline);
        $this->awaitExpiry($deadline);
        self::assertNull($outbox->claim($this->context));
        self::assertSame(1, $outbox->deadletter($this->context));
        self::assertSame(0, $outbox->deadletter($this->context));
        self::assertSame(2, $this->countRows('outbox'));
    }
}
