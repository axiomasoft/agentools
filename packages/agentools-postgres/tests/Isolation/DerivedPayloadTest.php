<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Isolation;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Payloads\{BoundedPayloadPolicy, DerivedPayloadRepository, EncryptedPayloadStore, FileEncryptedPayloadStore, PayloadDescriptor, PayloadHandle, PayloadHolds, PayloadRepository};
use AgenToolsPlatform\Postgres\Runtime\{SessionRepository, StorageFailure};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;
use PHPUnit\Framework\TestCase;

/** Real stores/receipts/bytes; current host authority and key service are fixtures. */
final class DerivedPayloadTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private string $directory;
    private Fixture $fixture;
    private FileEncryptedPayloadStore $store;
    private BoundedPayloadPolicy $policy;
    private Ref $source;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->fixture = new Fixture($this->database->pdo, $this->schema, new TenantId('derived-tenant'));
        $context = $this->fixture->context();
        (new SessionRepository($this->fixture->environment))->bind($context, $this->fixture->binding($context));
        $this->directory = sys_get_temp_dir() . '/agentools-derived-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->store = new FileEncryptedPayloadStore($this->directory, static fn (): string => $key);
        $this->policy = new BoundedPayloadPolicy($this->fixture->environment, new TestPolicy($this->database->pdo),
            ['restricted' => ['assist.read' => 2]]);
        $this->source = new Ref($this->fixture->tenant, 'chatom:source:synthetic');
        $this->deliveries()->openSource($context, $this->source, 1);
    }

    protected function tearDown(): void
    {
        $this->database->dropSchema($this->schema);
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($this->directory);
    }

    private function deliveries(): DeliveryRepository
    {
        return new DeliveryRepository($this->fixture->environment, static function (): void {});
    }

    private function payloads(?EncryptedPayloadStore $store = null): PayloadRepository
    {
        return new PayloadRepository($this->fixture->environment, $this->policy, $store ?? $this->store);
    }

    private function derived(?EncryptedPayloadStore $store = null): DerivedPayloadRepository
    {
        return new DerivedPayloadRepository($this->fixture->environment, $this->payloads($store), static function (): void {});
    }

    private function handle(string $id): PayloadHandle
    {
        return new PayloadHandle(new Ref($this->fixture->tenant, $id),
            new Ref($this->fixture->tenant, 'cipher:' . $id), new Ref($this->fixture->tenant, 'key:synthetic'));
    }

    private function register(string $id, string $dependency, int $milliseconds = 500): PayloadDescriptor
    {
        $row = $this->fixture->environment->db->row('SELECT to_char((clock_timestamp()+CAST(:ms AS integer)*interval \'1 millisecond\')
            AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS expiry', ['ms' => $milliseconds]);
        self::assertIsArray($row);
        self::assertIsString($row['expiry']);
        $handle = $this->handle($id);
        $payload = new PayloadDescriptor($handle->slot, new PayloadHash('sha-256:' . hash('sha256', 'DERIVED-' . $id)),
            'restricted', 'assist.read', new UtcInstant($row['expiry']));
        $context = $this->fixture->context();
        $this->payloads()->register($context, $payload, $handle);
        $this->derived()->bind($context, $this->source, 1, $dependency, $handle->slot);
        $this->store->put($context, $handle, 'DERIVED-' . $id);
        return $payload;
    }

    private function expire(PayloadDescriptor $payload): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            $row = $this->fixture->environment->db->row('SELECT clock_timestamp()>=CAST(:expiry AS timestamptz) AS expired',
                ['expiry' => $payload->retentionUntil->value]);
            if (($row['expired'] ?? false) === true) { return; }
            usleep(1000);
        } while (hrtime(true) < $deadline);
        self::fail('Synthetic expiry did not pass on the database clock');
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail('Expected closed or incomplete operation'); }
        catch (ContractViolation) { self::assertTrue(true); }
    }

    private function completedCount(): int
    {
        $db = $this->fixture->environment->db;
        $row = $db->row('SELECT count(*) AS n FROM ' . $db->table('derived_cleanup_receipts') . ' WHERE completed_at IS NOT NULL');
        self::assertIsArray($row);
        self::assertIsInt($row['n']);
        return $row['n'];
    }

    private function cleanup(string $dependency, ?EncryptedPayloadStore $store = null, int $limit = 100): Ref
    {
        return $this->derived($store)->cleanup($this->fixture->context(), $this->source, $dependency, 2,
            'cleanup:' . $dependency, new PayloadHash('sha-256:' . hash('sha256', $dependency)), $limit);
    }

    public function testAllSevenStoresEraseBytesBeforeDurableCompletionAndCannotReopen(): void
    {
        $context = $this->fixture->context();
        foreach (DerivedPayloadRepository::DEPENDENCIES as $dependency) {
            $last = $this->register($dependency, $dependency, 1000);
            self::assertSame('DERIVED-' . $dependency, $this->derived()->read($context, $this->source, $dependency, $last->ref));
        }
        $this->payloads()->materialize($context, $this->handle('summary')->slot, $this->handle('summary-backup'));
        $this->store->put($context, $this->handle('summary-backup'), 'DERIVED-summary');
        $this->denied(fn () => $this->cleanup('context'));
        $this->deliveries()->closeSource($context, $this->source, 2);
        $this->denied(fn () => $this->cleanup('context'));
        self::assertSame(0, $this->completedCount());
        foreach (DerivedPayloadRepository::DEPENDENCIES as $dependency) {
            $this->denied(fn () => $this->derived()->read($context, $this->source, $dependency, $this->handle($dependency)->slot));
            $this->denied(fn () => $this->derived()->bind($context, $this->source, 1, $dependency, $this->handle($dependency)->slot));
        }
        $this->expire($last);
        foreach (DerivedPayloadRepository::DEPENDENCIES as $dependency) {
            $receipt = $this->cleanup($dependency);
            self::assertSame($receipt->value, $this->cleanup($dependency)->value);
            self::assertSame('purged', $this->payloads()->status($context, $this->handle($dependency)->slot));
        }
        self::assertCount(8, glob($this->directory . '/*.purged'));
        self::assertSame(7, $this->completedCount());
        $db = $this->fixture->environment->db;
        $this->denied(fn () => $db->execute('DELETE FROM ' . $db->table('derived_cleanup_receipts')));
        $this->expectException(IntegrityConflict::class);
        $this->store->put($context, $this->handle('summary-backup'), 'DERIVED-summary');
    }

    public function testEmptyCoverageForeignScopeAndChangedOperationNeverProduceCompletion(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('summary', 'summary');
        $this->deliveries()->closeSource($context, $this->source, 2);
        $this->denied(fn () => $this->cleanup('context'));
        $this->denied(fn () => $this->derived()->cleanup($this->fixture->context('other-session'), $this->source, 'summary', 2,
            'foreign', new PayloadHash('sha-256:' . str_repeat('b', 64))));
        $this->denied(fn () => $this->derived()->cleanup($context, new Ref(new TenantId('foreign'), $this->source->value), 'summary', 2,
            'foreign', new PayloadHash('sha-256:' . str_repeat('b', 64))));
        $this->denied(fn () => $this->cleanup('summary'));
        $this->expire($payload);
        try {
            $this->derived()->cleanup($context, $this->source, 'summary', 2, 'cleanup:summary', new PayloadHash('sha-256:' . str_repeat('b', 64)));
            self::fail('Changed operation key accepted');
        } catch (IntegrityConflict) {
            self::assertSame('DERIVED-summary', $this->store->read($context, $this->handle('summary')));
        }
        self::assertNotEmpty($this->cleanup('summary')->value);
        self::assertSame(1, $this->completedCount());
    }

    public function testHoldAndLostPhysicalDeletionAckReconcileAfterRestart(): void
    {
        $context = $this->fixture->context();
        $payload = $this->register('summary', 'summary');
        $holds = new PayloadHolds($this->fixture->environment,
            static fn (): Ref => new Ref($context->session->tenant, 'synthetic:hold-authority'));
        $hold = new Ref($this->fixture->tenant, 'hold');
        $holds->place($context, $payload->ref, $hold);
        $this->deliveries()->closeSource($context, $this->source, 2);
        $this->expire($payload);
        $this->denied(fn () => $this->cleanup('summary'));
        self::assertSame('DERIVED-summary', $this->store->read($context, $this->handle('summary')));
        $holds->release($context, $payload->ref, $hold);
        $fault = new class($this->store) implements EncryptedPayloadStore {
            public function __construct(private FileEncryptedPayloadStore $store) {}
            public function read(ExecutionContext $context, PayloadHandle $handle): string { return $this->store->read($context, $handle); }
            public function purge(ExecutionContext $context, PayloadHandle $handle, string $key): void {
                $this->store->purge($context, $handle, $key);
                throw new StorageFailure();
            }
            public function supportsIdempotentPurge(): bool { return true; }
            public function reset(): void { $this->store->reset(); }
        };
        try { $this->cleanup('summary', $fault); self::fail('Lost ACK accepted'); }
        catch (StorageFailure) { self::assertSame('purging', $this->payloads()->status($context, $payload->ref)); }
        self::assertSame(0, $this->completedCount());
        self::assertNotEmpty($this->cleanup('summary')->value);
        self::assertSame(1, $this->completedCount());
    }

    public function testBoundedCleanupCompletesOnlyAfterAllPayloads(): void
    {
        $this->register('summary-one', 'summary');
        $last = $this->register('summary-two', 'summary');
        $this->deliveries()->closeSource($this->fixture->context(), $this->source, 2);
        $this->expire($last);
        $this->denied(fn () => $this->cleanup('summary', limit: 1));
        self::assertSame(0, $this->completedCount());
        self::assertNotEmpty($this->cleanup('summary', limit: 1)->value);
        self::assertSame(1, $this->completedCount());
    }
    private function expiry(int $milliseconds): UtcInstant
    {
        $row = $this->fixture->environment->db->row('SELECT to_char((clock_timestamp()+CAST(:ms AS integer)*interval \'1 millisecond\')
            AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS expiry', ['ms' => $milliseconds]);
        self::assertIsArray($row);
        self::assertIsString($row['expiry']);
        return new UtcInstant($row['expiry']);
    }

    public function testActualProcessCrashBeforeRenameRetainsHandleProvenanceAndPurgeCleansStaging(): void
    {
        $context = $this->fixture->context();
        $handle = $this->handle('crashed-write');
        $payload = new PayloadDescriptor($handle->slot, new PayloadHash('sha-256:' . hash('sha256', 'CRASH-LEFT-CIPHERTEXT')),
            'restricted', 'assist.read', $this->expiry(100));
        $this->payloads()->register($context, $payload, $handle);
        $this->crashWrite($handle, 'put');
        $staged = glob($this->directory . '/*.write-*');
        self::assertCount(1, $staged);
        self::assertStringStartsWith('ATPS1', file_get_contents($staged[0]));
        self::assertStringNotContainsString('CRASH-LEFT-CIPHERTEXT', file_get_contents($staged[0]));

        // A crash while writing the purge marker leaves another associated draft.
        $this->crashWrite($handle, 'purge');
        self::assertCount(2, glob($this->directory . '/*.write-*'));
        $legacy = $this->directory . '/write-legacy';
        file_put_contents($legacy, 'UNATTRIBUTED');
        $other = $this->handle('other-handle');
        $this->crashWrite($other, 'put');
        $this->expire($payload);
        $this->payloads()->purge($context, $payload->ref);
        self::assertSame('purged', $this->payloads()->status($context, $payload->ref));
        self::assertCount(1, glob($this->directory . '/*.write-*'));
        self::assertSame('UNATTRIBUTED', file_get_contents($legacy));
        $this->payloads()->purge($context, $payload->ref);
        $this->expectException(IntegrityConflict::class);
        $this->store->put($context, $handle, 'CRASH-LEFT-CIPHERTEXT');
    }

    private function crashWrite(PayloadHandle $handle, string $operation): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/payload-write-crash.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], serialize([$this->directory, $this->fixture->context(), $handle, $operation]));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(73, proc_close($process), $output . $errors);
    }


}
