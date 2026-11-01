<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Isolation;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Payloads\{BoundedPayloadPolicy, EncryptedPayloadStore, ExpiryCandidates, FileEncryptedPayloadStore, PayloadDescriptor, PayloadHandle, PayloadHolds, PayloadRepository};
use AgenToolsPlatform\Postgres\Runtime\{SessionRepository, StorageFailure};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;
use PHPUnit\Framework\TestCase;

/** Real SQL and encrypted disk effects; host ACL and key-service authority remain explicit fixtures. */
final class RetentionControlsTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private string $directory;
    private Fixture $fixture;
    private FileEncryptedPayloadStore $store;
    private TestPolicy $sourcePolicy;
    private BoundedPayloadPolicy $policy;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->fixture = new Fixture($this->database->pdo, $this->schema, new TenantId('retention-tenant'));
        $context = $this->fixture->context();
        (new SessionRepository($this->fixture->environment))->bind($context, $this->fixture->binding($context));
        $this->directory = sys_get_temp_dir() . '/agentools-retention-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->store = new FileEncryptedPayloadStore($this->directory, static fn (): string => $key);
        $this->sourcePolicy = new TestPolicy($this->database->pdo);
        $this->policy = new BoundedPayloadPolicy($this->fixture->environment, $this->sourcePolicy, ['restricted' => ['assist.read' => 2]]);
    }

    protected function tearDown(): void
    {
        $this->database->dropSchema($this->schema);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function expiry(int $milliseconds): UtcInstant
    {
        $row = $this->fixture->environment->db->row('SELECT to_char((clock_timestamp()+CAST(:ms AS integer)*interval \'1 millisecond\')
            AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS expiry', ['ms' => $milliseconds]);
        self::assertIsArray($row);
        self::assertIsString($row['expiry']);
        return new UtcInstant($row['expiry']);
    }

    private function handle(string $id): PayloadHandle
    {
        $tenant = $this->fixture->tenant;
        return new PayloadHandle(new Ref($tenant, $id), new Ref($tenant, 'cipher:' . $id), new Ref($tenant, 'key:v1'));
    }

    private function repository(?EncryptedPayloadStore $store = null): PayloadRepository
    {
        return new PayloadRepository($this->fixture->environment, $this->policy, $store ?? $this->store);
    }

    private function register(string $id, int $milliseconds = 100): PayloadDescriptor
    {
        $payload = new PayloadDescriptor(new Ref($this->fixture->tenant, $id),
            new PayloadHash('sha-256:' . hash('sha256', 'VISIBLE-TEXT')), 'restricted', 'assist.read', $this->expiry($milliseconds));
        $this->repository()->register($this->fixture->context(), $payload, $this->handle($id));
        $this->store->put($this->fixture->context(), $this->handle($id), 'VISIBLE-TEXT');
        return $payload;
    }

    private function expire(PayloadDescriptor $payload): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            $row = $this->fixture->environment->db->row('SELECT clock_timestamp()>=CAST(:expiry AS timestamptz) AS expired', ['expiry' => $payload->retentionUntil->value]);
            if (($row['expired'] ?? false) === true) {
                return;
            }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        self::fail('Expiry did not pass on the database clock');
    }

    private function holds(): PayloadHolds
    {
        return new PayloadHolds($this->fixture->environment,
            static fn (ExecutionContext $context, Ref $payload, Ref $hold, string $operation): Ref => new Ref($payload->tenant, 'test-owner:' . $operation));
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected retained payload');
        } catch (ContractViolation) {
            self::assertTrue(true);
        }
    }

    public function testBoundsDoNotReplaceCurrentSourceAuthority(): void
    {
        $this->denied(fn () => $this->register('too-long', 5000));
        $this->denied(fn () => $this->register('already-expired', -100));
        $this->sourcePolicy->denied = true;
        $this->denied(fn () => $this->register('revoked', 1000));
        self::assertSame(0, $this->fixture->environment->db->row('SELECT count(*) AS n FROM ' . $this->fixture->environment->db->table('payload_refs'))['n']);
    }

    public function testHoldAuthorityCannotCrossSessionsOrEraseItsHistory(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('scoped', 1000);
        $hold = new Ref($this->fixture->tenant, 'hold');
        $this->denied(fn () => $this->holds()->place($this->fixture->context('other-session'), $payload->ref, $hold));
        $this->denied(fn () => $this->holds()->place($context, $payload->ref, new Ref(new TenantId('foreign'), 'hold')));
        $this->holds()->place($context, $payload->ref, $hold);
        $db = $this->fixture->environment->db;
        $this->denied(fn () => $db->execute('DELETE FROM ' . $db->table('payload_holds')));
        $this->holds()->release($context, $payload->ref, $hold);
        $this->denied(fn () => $db->execute('UPDATE ' . $db->table('payload_holds') . ' SET released_by=NULL,released_at=NULL,version=version+1'));
        self::assertSame(1, $db->row('SELECT count(*) AS n FROM ' . $db->table('payload_holds') . ' WHERE released_at IS NOT NULL')['n']);
    }

    public function testHoldsSurviveRestartAndPurgeAllRegisteredCopies(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('held', 300);
        $this->repository()->materialize($context, $payload->ref, $this->handle('held-copy'));
        $this->store->put($context, $this->handle('held-copy'), 'VISIBLE-TEXT');
        $hold = new Ref($this->fixture->tenant, 'legal-hold');
        $other = new Ref($this->fixture->tenant, 'other-hold');
        $this->holds()->place($context, $payload->ref, $hold);
        $this->holds()->place($context, $payload->ref, $hold);
        $this->holds()->place($context, $payload->ref, $other);
        $this->expire($payload);
        $this->denied(fn () => $this->repository()->purge($context, $payload->ref));
        self::assertSame('VISIBLE-TEXT', $this->store->read($context, $this->handle('held-copy')));
        $this->holds()->release($context, $payload->ref, $hold);
        $this->holds()->release($context, $payload->ref, $hold);
        $this->denied(fn () => $this->repository()->purge($context, $payload->ref));
        $this->holds()->release($context, $payload->ref, $other);
        $this->repository()->purge($context, $payload->ref);
        self::assertSame('purged', $this->repository()->status($context, $payload->ref));
        $db = $this->fixture->environment->db;
        self::assertSame(1, $db->row('SELECT count(*) AS n FROM ' . $db->table('payload_materializations') . ' WHERE purged_at IS NOT NULL AND encrypted_ref IS NULL')['n']);
        self::assertSame(2, $db->row('SELECT count(*) AS n FROM ' . $db->table('payload_holds') . ' WHERE released_at IS NOT NULL')['n']);
        $this->holds()->place($context, $payload->ref, $hold);
        $this->denied(fn () => $this->holds()->place($context, $payload->ref, new Ref($this->fixture->tenant, 'late-hold')));
        $this->expectException(IntegrityConflict::class);
        $this->store->put($context, $this->handle('held-copy'), 'VISIBLE-TEXT');
    }

    public function testOrphanScanAndCrashAfterPhysicalPurgeReconcileWithoutResurrection(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('orphan');
        $this->expire($payload);
        $scanner = new ExpiryCandidates($this->fixture->environment);
        self::assertSame(['orphan'], array_map(static fn (Ref $ref): string => $ref->value, $scanner->scan($context, 1)));
        self::assertSame([], $scanner->scan($context, 1, 'orphan'));
        $fault = new class($this->store) implements EncryptedPayloadStore {
            public function __construct(private FileEncryptedPayloadStore $store) {}
            public function read(ExecutionContext $context, PayloadHandle $handle): string { return $this->store->read($context, $handle); }
            public function purge(ExecutionContext $context, PayloadHandle $handle, string $key): void {
                $this->store->purge($context, $handle, $key);
                throw new StorageFailure(); // Actual physical deletion precedes this injected lost acknowledgement.
            }
            public function supportsIdempotentPurge(): bool { return true; }
            public function reset(): void { $this->store->reset(); }
        };
        try {
            $this->repository($fault)->purge($context, $payload->ref);
            self::fail('Expected lost purge ACK');
        } catch (StorageFailure) {
            self::assertSame('purging', $this->repository()->status($context, $payload->ref));
        }
        $this->denied(fn () => $this->holds()->place($context, $payload->ref, new Ref($this->fixture->tenant, 'too-late')));
        $this->repository()->purge($context, $payload->ref);
        $this->repository()->purge($context, $payload->ref);
        self::assertSame([], $scanner->scan($context));
        self::assertSame('purged', $this->repository()->status($context, $payload->ref));
    }

    public function testExpiryScanIncludesUnknownDeliveryButCannotAuthorizeItsErasure(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('unknown', 200);
        $accepted = (new IntakeRepository($this->fixture->environment))->accept($this->fixture->input($context), $this->fixture->snapshot($context));
        $db = $this->fixture->environment->db;
        $db->execute('INSERT INTO ' . $db->table('responses') . '(tenant_id,id,run_id,response_key,payload_hash,payload_ref_id)
            VALUES (:tenant,\'response\',:run,\'response\',:hash,:payload)', ['tenant' => $this->fixture->tenant->value,
                'run' => $accepted->runId->value, 'hash' => substr($payload->contentHash->value, 8), 'payload' => $payload->ref->value]);
        $db->execute('INSERT INTO ' . $db->table('delivery_intents') . '(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash,outcome)
            VALUES (:tenant,\'delivery\',:run,\'response\',\'chat\',\'delivery\',:hash,\'unknown\')', ['tenant' => $this->fixture->tenant->value,
                'run' => $accepted->runId->value, 'hash' => substr($payload->contentHash->value, 8)]);
        $this->expire($payload);
        self::assertCount(1, (new ExpiryCandidates($this->fixture->environment))->scan($context));
        $this->denied(fn () => $this->repository()->purge($context, $payload->ref));
        self::assertSame('VISIBLE-TEXT', $this->store->read($context, $this->handle('unknown')));
        self::assertSame('unknown', $db->row('SELECT outcome FROM ' . $db->table('delivery_intents'))['outcome']);
        self::assertSame('expired', $this->repository()->status($context, $payload->ref));
    }

    public function testParallelPlanDirectActionInputRemainsUnresolvedAndCannotLinkAfterPurge(): void
    {
        // The actual owner submission commits a dated Action and its outbox, not a SQL acceptance double.
        require_once dirname(__DIR__, 4) . '/examples/platform/direct-action/bootstrap.php';
        $direct = new \AgenToolsPlatform\Examples\DirectAction\Composition($this->database->pdo, $this->schema,
            new \AgenToolsPlatform\Examples\DirectAction\Host($this->database->pdo, $this->fixture->tenant, 'actor'));
        $context = $this->fixture->context();
        $payload = $this->register('host:input:submission-1', 200);
        $direct->application->submit($direct->submission());
        $this->expire($payload);
        $this->denied(fn () => $this->repository()->purge($context, $payload->ref));
        self::assertSame('VISIBLE-TEXT', $this->store->read($context, $this->handle('host:input:submission-1')));

        $late = $this->register('host:input:late', 100);
        $this->expire($late);
        $this->repository()->purge($context, $late->ref);
        $this->denied(fn () => $direct->application->submit($direct->submission(key: 'late')));
        $db = $this->fixture->environment->db;
        self::assertSame(1, $db->row('SELECT count(*) AS n FROM ' . $db->table('action_submissions'))['n']);
        self::assertSame(1, $db->row('SELECT count(*) AS n FROM ' . $db->table('agentools_events'))['n']);
    }
}
