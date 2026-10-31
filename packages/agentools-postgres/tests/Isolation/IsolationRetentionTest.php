<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Isolation;

use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict, PayloadHash, Ref, RunId, TenantId, UtcInstant};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Payloads\{JobBoundary, PayloadDescriptor, PayloadHandle, PayloadRepository};
use AgenToolsPlatform\Postgres\Runtime\{RunRepository, SessionRepository, StorageFailure, TurnRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class IsolationRetentionTest extends TestCase
{
    private IsolatedDatabase $database;
    private string $schema;
    private Fixture $alpha;
    private Fixture $beta;
    private TestPolicy $policy;
    private TestVault $vault;

    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        (new Migrator($this->database->pdo, $this->schema))->migrate();
        $this->alpha = $this->tenant('tenant-alpha');
        $this->beta = $this->tenant('tenant-beta');
        $this->policy = new TestPolicy($this->database->pdo);
        $this->vault = new TestVault($this->database->pdo);
    }

    private function tenant(string $tenant): Fixture
    {
        $fixture = new Fixture($this->database->pdo, $this->schema, new TenantId($tenant));
        $context = $fixture->context();
        (new SessionRepository($fixture->environment))->bind($context, $fixture->binding($context));
        return $fixture;
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
            $this->database->dropSchema($this->schema);
        }
    }

    private function repository(Fixture $fixture): PayloadRepository
    {
        return new PayloadRepository($fixture->environment, $this->policy, $this->vault);
    }

    private function expiry(int $milliseconds = 30000): UtcInstant
    {
        $statement = $this->database->pdo->prepare('SELECT to_char((clock_timestamp()+CAST(:ms AS integer)*interval \'1 millisecond\')
            AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\')');
        $statement->execute(['ms' => $milliseconds]);
        $value = $statement->fetchColumn();
        self::assertIsString($value);
        return new UtcInstant($value);
    }

    private function payload(Fixture $fixture, string $id = 'payload', int $milliseconds = 30000, string $bytes = 'CHILD-TEXT'): PayloadDescriptor
    {
        $ref = new Ref($fixture->tenant, $id);
        $payload = new PayloadDescriptor($ref, new PayloadHash('sha-256:' . hash('sha256', $bytes)), 'restricted', 'assist.read', $this->expiry($milliseconds));
        $this->vault->backend[$fixture->tenant->value . ':' . $id] = $bytes;
        $this->repository($fixture)->register($fixture->context(), $payload, $this->handle($fixture, $id));
        return $payload;
    }

    private function handle(Fixture $fixture, string $id): PayloadHandle
    {
        return new PayloadHandle(new Ref($fixture->tenant, $id), new Ref($fixture->tenant, 'vault:SECRET-handle:' . $id),
            new Ref($fixture->tenant, 'secret_ref:SECRET-key:' . $id));
    }

    private function awaitExpiry(UtcInstant $expiry): void
    {
        $query = $this->database->pdo->prepare('SELECT clock_timestamp()>=CAST(:expiry AS timestamptz) AS expired');
        $deadline = hrtime(true)+5_000_000_000;
        do {
            $query->execute(['expiry' => $expiry->value]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['expired'] === true) { return; }
            usleep(1000);
        } while (hrtime(true)<$deadline);
        self::fail('DB expiry did not pass');
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->database->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    /** @param class-string<\Throwable> $type @param callable(): mixed $operation */
    private function fails(string $type, callable $operation): void
    {
        try { $operation(); self::fail('Expected failure'); }
        catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
            self::assertStringNotContainsString('SECRET', $error->getMessage());
            self::assertStringNotContainsString('CHILD-TEXT', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testTenantFkStatusAndSequentialJobs(): void
    {
        $alpha = $this->payload($this->alpha);
        $beta = $this->payload($this->beta, 'payload', 30000, 'BETA-CONTENT');
        $state = new \stdClass(); $state->host = [];
        $jobs = new JobBoundary($this->alpha->environment->db, $this->vault, static function () use ($state): void { $state->host = []; });
        $jobs->run(function () use ($state, $alpha): void {
            self::assertSame([], $state->host);
            $state->host = ['actor' => 'ALPHA-actor', 'credential' => 'ALPHA-secret', 'profile' => 'ALPHA-bundle', 'retrieval' => 'ALPHA-source'];
            $this->database->pdo->exec("SET agentools.tenant_id='tenant-alpha'");
            self::assertSame('CHILD-TEXT', $this->repository($this->alpha)->dereference($this->alpha->context(), $alpha->ref));
            $this->vault->credential = 'ALPHA-credential';
        });
        $jobs->run(function () use ($state, $alpha, $beta): void {
            self::assertSame([], $state->host);
            self::assertNull($this->vault->credential);
            self::assertNull($this->vault->cachedBytes);
            self::assertNotSame('tenant-alpha', $this->scalar("SELECT current_setting('agentools.tenant_id',true)"));
            $this->fails(ContractViolation::class, fn () => $this->repository($this->beta)->status($this->beta->context(), $alpha->ref));
            $this->fails(ContractViolation::class, fn () => $this->repository($this->beta)->dereference($this->beta->context(), $alpha->ref));
            self::assertSame('BETA-CONTENT', $this->repository($this->beta)->dereference($this->beta->context(), $beta->ref));
            self::assertSame($this->beta->tenant->value, $this->beta->context()->identity->tenant->value);
            self::assertSame('tenant-beta', (new SessionRepository($this->beta->environment))->get($this->beta->context())?->bundleRef->tenant->value);
        });
        $b = $this->beta; $context = $b->context();
        $accepted = (new IntakeRepository($b->environment))->accept($b->input($context), $b->snapshot($context));
        $this->fails(ContractViolation::class, fn () => $this->alpha->environment->db->execute('INSERT INTO '
            . $this->alpha->environment->db->table('runs') . '(tenant_id,id,turn_id) VALUES (\'tenant-alpha\',\'cross\',:turn)', ['turn' => $accepted->turnId->value]));
        $this->fails(ContractViolation::class, fn () => (new RunRepository($this->alpha->environment))->get($this->alpha->context(), $accepted->runId));
        $action = new \AgenToolsPlatform\Core\Common\ActionId($b->tenant, 'foreign-action');
        $this->fails(ContractViolation::class, fn () => (new \AgenToolsPlatform\Postgres\Actions\ActionRepository($this->alpha->environment))->rebuild($this->alpha->context(), $action, 'unused'));
    }

    public function testValidTenantGraphAndAuditedPayload(): void
    {
        $f = $this->alpha; $ctx = $f->context();
        $accepted = (new IntakeRepository($f->environment))->accept($f->input($ctx), $f->snapshot($ctx));
        self::assertNotNull((new TurnRepository($f->environment))->get($ctx, $accepted->turnId));
        self::assertNotNull((new RunRepository($f->environment))->get($ctx, $accepted->runId));
        $payload = $this->payload($f);
        $repo = $this->repository($f);
        self::assertSame('available', $repo->status($ctx, $payload->ref));
        self::assertSame('CHILD-TEXT', $repo->dereference($ctx, $payload->ref));
        self::assertSame(2, $this->scalar('SELECT count(*) FROM "' . $this->schema . '".payload_access_audit WHERE operation=\'dereference\''));
        $audit = $this->scalar('SELECT json_agg(to_jsonb(a))::text FROM "' . $this->schema . '".payload_access_audit a');
        self::assertIsString($audit);
        self::assertStringNotContainsString('SECRET', $audit);
        self::assertStringNotContainsString('CHILD-TEXT', $audit);
        self::assertNull($this->vault->cachedBytes);
        self::assertNull($this->vault->credential);
    }

    public function testRollbackAndWorkerReset(): void
    {
        $state = new \stdClass(); $state->refs = [];
        $jobs = new JobBoundary($this->alpha->environment->db, $this->vault, static function () use ($state): void { $state->refs = []; });
        $this->fails(ContractViolation::class, fn () => $jobs->run(function () use ($state): void {
            $state->refs = ['ALPHA-actor','ALPHA-profile','ALPHA-policy','ALPHA-secret'];
            $this->vault->credential = 'ALPHA-secret';
            $this->alpha->environment->db->transaction(function (): void {
                $this->alpha->environment->db->execute('INSERT INTO ' . $this->alpha->environment->db->table('turns') . '
                    (tenant_id,id,session_id,source,source_event_key,payload_hash,input_ref)
                    VALUES (\'tenant-alpha\',\'partial\',\'missing-beta-session\',\'chat\',\'event\',:hash,\'ref\')', ['hash' => str_repeat('a',64)]);
            });
        }));
        $jobs->run(function () use ($state, $jobs): void {
            self::assertSame([], $state->refs);
            self::assertNull($this->vault->credential);
            self::assertFalse($this->database->pdo->inTransaction());
            self::assertSame(0, $this->scalar('SELECT count(*) FROM "' . $this->schema . '".turns'));
            $this->fails(\LogicException::class, fn () => $jobs->run(static fn (): bool => true));
            // Unclosed caller transaction is rolled back by the job's finally boundary.
            $this->database->pdo->beginTransaction();
            $this->database->pdo->exec("SET LOCAL agentools.tenant_id='tenant-beta'");
        });
        self::assertFalse($this->database->pdo->inTransaction());
        self::assertNotSame('tenant-beta', $this->scalar("SELECT current_setting('agentools.tenant_id',true)"));
    }

    public function testAtomicPayloadAndNoLockIo(): void
    {
        $f = $this->alpha; $payload = $this->payload($f); $repo = $this->repository($f);
        $repo->register($f->context(), $payload, $this->handle($f, 'payload'));
        self::assertSame(1, $this->scalar('SELECT count(*) FROM "' . $this->schema . '".payload_refs'));
        $this->vault->onRead = function (): void {
            self::assertFalse($this->database->pdo->inTransaction());
            $observer = new IsolatedDatabase();
            $observer->pdo->beginTransaction();
            try {
                $query = $observer->pdo->query('SELECT id FROM "' . $this->schema . '".payload_refs FOR UPDATE NOWAIT');
                self::assertNotFalse($query); self::assertIsString($query->fetchColumn());
                $query = $observer->pdo->query('SELECT count(*) FROM "' . $this->schema . '".payload_access_audit WHERE operation=\'dereference\' AND outcome=\'authorized\'');
                self::assertNotFalse($query); self::assertSame(1, $query->fetchColumn(), 'Audit committed before IO');
            } finally { $observer->pdo->rollBack(); }
        };
        self::assertSame('CHILD-TEXT', $repo->dereference($f->context(), $payload->ref));
        $changed = new PayloadDescriptor($payload->ref, new PayloadHash('sha-256:' . str_repeat('0',64)),
            $payload->classification, $payload->purpose, $payload->retentionUntil);
        $this->fails(IntegrityConflict::class, fn () => $repo->register($f->context(), $changed, $this->handle($f, 'payload')));
        self::assertSame(0, $this->scalar('SELECT version FROM "' . $this->schema . '".payload_refs'));
        $this->fails(IntegrityConflict::class, fn () => $f->environment->db->execute('UPDATE '
            . $f->environment->db->table('payload_access_audit') . ' SET outcome=\'released\''));
    }

    public function testRejectForeignStaleAndExpiredPayload(): void
    {
        $f = $this->alpha; $payload = $this->payload($f, 'expiring', 100); $repo = $this->repository($f);
        $foreignSession = $f->context('other-session');
        $this->fails(ContractViolation::class, fn () => $repo->status($foreignSession, $payload->ref));
        $this->policy->denied = true;
        $this->fails(ContractViolation::class, fn () => $repo->dereference($f->context(), $payload->ref));
        $this->policy->denied = false;
        $this->database->pdo->beginTransaction();
        try { $this->fails(\LogicException::class, fn () => $repo->dereference($f->context(), $payload->ref)); }
        finally { $this->database->pdo->rollBack(); }
        $this->awaitExpiry($payload->retentionUntil);
        self::assertSame('expired', $repo->status($f->context(), $payload->ref));
        $this->fails(ContractViolation::class, fn () => $repo->dereference($f->context(), $payload->ref));
        self::assertSame(0, $this->vault->reads);
        self::assertSame(0, $this->scalar('SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=\'' . $this->schema . '\' AND c.relrowsecurity'));
    }

    public function testPurgeCrashRollbackAndUnsupportedOwner(): void
    {
        $f = $this->alpha; $payload = $this->payload($f, 'purge', 500); $repo = $this->repository($f);
        $copy = $this->handle($f, 'copy'); $repo->materialize($f->context(), $payload->ref, $copy);
        $this->vault->backend[$f->tenant->value . ':copy'] = 'CHILD-TEXT';
        $this->awaitExpiry($payload->retentionUntil);
        $this->vault->supported = false;
        $this->fails(ContractViolation::class, fn () => $repo->purge($f->context(), $payload->ref));
        self::assertSame('expired', $repo->status($f->context(), $payload->ref));
        self::assertCount(0, $this->vault->deletions);
        $this->vault->supported = true; $this->vault->loseDeleteReceipt = true;
        $this->fails(StorageFailure::class, fn () => $repo->purge($f->context(), $payload->ref));
        self::assertSame('purging', $repo->status($f->context(), $payload->ref));
        $key = $this->vault->deletions[0]['operation'];
        $this->fails(ContractViolation::class, fn () => $repo->dereference($f->context(), $payload->ref));
        $this->vault->loseDeleteReceipt = false;
        $this->fault('payload_materializations', 'UPDATE');
        try { $this->fails(StorageFailure::class, fn () => $repo->purge($f->context(), $payload->ref)); }
        finally { $this->dropFault('payload_materializations'); }
        self::assertSame('purging', $repo->status($f->context(), $payload->ref));
        self::assertIsString($this->scalar('SELECT encrypted_ref FROM "' . $this->schema . '".payload_refs'));
        $repo->purge($f->context(), $payload->ref);
        self::assertSame($key, $this->vault->deletions[1]['operation']);
        self::assertSame('purged', $repo->status($f->context(), $payload->ref));
        self::assertNull($this->scalar('SELECT encrypted_ref FROM "' . $this->schema . '".payload_refs'));
        self::assertNull($this->scalar('SELECT encryption_key_ref FROM "' . $this->schema . '".payload_materializations'));
        self::assertSame(substr($payload->contentHash->value,8), $this->scalar('SELECT content_hash FROM "' . $this->schema . '".payload_refs'));
        self::assertSame([], $this->vault->backend);
        $count = count($this->vault->deletions); $repo->purge($f->context(), $payload->ref);
        self::assertCount($count, $this->vault->deletions);
    }

    public function testRetentionProtectsUnresolvedAndPurgesCopies(): void
    {
        $f = $this->alpha; $payload = $this->payload($f, 'retained', 500); $repo = $this->repository($f);
        $copy = $this->handle($f, 'materialized'); $repo->materialize($f->context(), $payload->ref, $copy);
        $this->vault->backend[$f->tenant->value . ':materialized'] = 'CHILD-TEXT';
        $this->fails(ContractViolation::class, fn () => $repo->purge($f->context(), $payload->ref));
        $ctx = $f->context(); $accepted = (new IntakeRepository($f->environment))->accept($f->input($ctx), $f->snapshot($ctx));
        $db = $f->environment->db;
        $db->execute('INSERT INTO ' . $db->table('responses') . '(tenant_id,id,run_id,response_key,payload_hash,payload_ref_id)
            VALUES (:tenant,\'response\',:run,\'response\',:hash,:payload)', ['tenant' => $f->tenant->value, 'run' => $accepted->runId->value,
                'hash' => substr($payload->contentHash->value,8), 'payload' => $payload->ref->value]);
        $db->execute('INSERT INTO ' . $db->table('delivery_intents') . '(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash,outcome)
            VALUES (:tenant,\'delivery\',:run,\'response\',\'destination\',\'delivery\',:hash,\'unknown\')', [
                'tenant' => $f->tenant->value, 'run' => $accepted->runId->value, 'hash' => substr($payload->contentHash->value,8)]);
        $db->execute('INSERT INTO ' . $db->table('delivery_receipts') . '(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome)
            VALUES (:tenant,\'unknown-receipt\',\'delivery\',0,:hash,\'unknown\')', [
                'tenant' => $f->tenant->value, 'hash' => substr($payload->contentHash->value,8)]);
        $this->awaitExpiry($payload->retentionUntil);
        $this->fails(ContractViolation::class, fn () => $repo->purge($ctx, $payload->ref));
        self::assertCount(0, $this->vault->deletions);
        $this->fails(ContractViolation::class, fn () => $db->execute('UPDATE ' . $db->table('delivery_intents') . ' SET version=version+1,outcome=\'success\''));
        $this->fails(ContractViolation::class, fn () => $repo->purge($ctx, $payload->ref));
        self::assertCount(0, $this->vault->deletions);
        self::assertSame('CHILD-TEXT', $this->vault->backend[$f->tenant->value . ':retained']);
        self::assertSame('CHILD-TEXT', $this->vault->backend[$f->tenant->value . ':materialized']);
        $db->execute('INSERT INTO ' . $db->table('delivery_receipts') . '(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref)
            VALUES (:tenant,\'known-receipt\',\'delivery\',1,:hash,\'success\',\'owner:receipt\')', [
                'tenant' => $f->tenant->value, 'hash' => substr($payload->contentHash->value,8)]);
        $db->execute('UPDATE ' . $db->table('delivery_intents') . ' SET version=version+1,outcome=\'success\',receipt_ref=\'owner:receipt\'');
        self::assertSame('unknown', $this->scalar('SELECT outcome FROM "' . $this->schema . '".delivery_receipts WHERE id=\'unknown-receipt\''));
        $this->vault->onPurge = function () use ($repo, $f, $payload, $db): void {
            self::assertFalse($this->database->pdo->inTransaction());
            $this->fails(ContractViolation::class, fn () => $repo->materialize($f->context(), $payload->ref, $this->handle($f,'late-copy')));
            $this->fails(ContractViolation::class, fn () => $db->execute('INSERT INTO ' . $db->table('outbox') . '
                (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,payload_ref_id,deadline_at,retention_until)
                VALUES (:tenant,\'late\',\'late\',:hash,\'delivery\',\'response\',:payload,
                    clock_timestamp()+interval \'1 hour\',clock_timestamp()+interval \'2 hours\')', [
                    'tenant' => $f->tenant->value, 'hash' => substr($payload->contentHash->value,8), 'payload' => $payload->ref->value]));
            $this->fails(ContractViolation::class, fn () => $db->execute('UPDATE ' . $db->table('delivery_intents') . ' SET version=version+1,outcome=\'unknown\''));
            $observer = new IsolatedDatabase();
            $observer->pdo->exec('SET search_path TO "' . $this->schema . '", pg_catalog');
            foreach (["payload_hash,'unknown',NULL", "payload_hash,'success','owner:unsupported'", "repeat('0',64),'success','owner:receipt'"] as $receipt) {
                try {
                    $observer->pdo->exec("INSERT INTO delivery_receipts(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref)
                        SELECT tenant_id,'late-receipt',id,2,$receipt FROM delivery_intents");
                    self::fail('Unbound receipt accepted during external deletion');
                } catch (\PDOException $error) { self::assertSame('23514', (string) $error->getCode()); }
            }
            $this->fails(ContractViolation::class, fn () => $db->execute('UPDATE ' . $db->table('payload_refs') . ' SET version=version+1,purge_token=:token', ['token' => str_repeat('0',64)]));
        };
        $repo->purge($ctx, $payload->ref);
        self::assertSame('purged', $repo->status($ctx, $payload->ref));
        self::assertNull($this->scalar('SELECT encrypted_ref FROM "' . $this->schema . '".payload_materializations'));
        self::assertSame([], $this->vault->backend);
        self::assertSame(1, $this->scalar('SELECT count(*) FROM "' . $this->schema . '".responses'));
        $this->fails(ContractViolation::class, fn () => $db->execute('DELETE FROM ' . $db->table('payload_refs')));
        $this->fails(ContractViolation::class, fn () => $db->execute('DELETE FROM ' . $db->table('payload_materializations')));

        // Independent owner-effect boundary: a resolved delivery must not hide an
        // unknown effect request referencing another tenant-scoped payload.
        $action = new \AgenToolsPlatform\Postgres\Tests\Actions\Fixture($this->database, $this->schema);
        $request = $this->payload($action->runtime, 'encrypted:request:one', 500);
        $base = $action->events(); $original = $base[3];
        $events = [...array_slice($base,0,3), $action->event($original->eventId, $original->kind,
            array_replace($original->payload, ['request_hash' => substr($request->contentHash->value,8)]), 1)];
        $intent = new \AgenToolsPlatform\Postgres\Actions\EffectIntent('effect:one','test-owner','owner:operation:one',
            $request->contentHash, $request->ref);
        $action->actions->append($action->context, $action->runtime->snapshot($action->context), $action->claim, $action->action, 0, $events, [$intent]);
        $action->runtime->environment->db->execute('UPDATE ' . $db->table('effect_intents') . ' SET version=version+1,outcome=\'unknown\'');
        $this->awaitExpiry($request->retentionUntil);
        $ownerRepo = $this->repository($action->runtime); $beforeDelete = count($this->vault->deletions);
        $this->fails(ContractViolation::class, fn () => $ownerRepo->purge($action->context, $request->ref));
        self::assertCount($beforeDelete, $this->vault->deletions);
        $outcome = $action->event('retention-owner-receipt','effect_confirmed',['effect_id'=>'effect:one','external_ref'=>'owner:receipt'],1);
        $action->actions->append($action->context, $action->runtime->snapshot($action->context), $action->claim, $action->action, 4, [$outcome]);
        // Earlier callback belongs to alpha; this new request has its own owner context.
        $this->vault->onPurge = null;
        $ownerRepo->purge($action->context, $request->ref);
        self::assertSame('purged', $ownerRepo->status($action->context, $request->ref));
    }

    public function testLegacyPurgeReservationRequiresReceiptResolution(): void
    {
        $original = $this->schema;
        $directory = sys_get_temp_dir() . '/agentools_p35_' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema);
        try {
            foreach (['0001_intake.sql','0002_durable_refs.sql','0003_action_journal.sql','0004_payload_lifecycle.sql'] as $file) {
                self::assertTrue(copy(dirname(__DIR__,2) . '/migrations/' . $file, $directory . '/' . $file));
            }
            $migrator = new Migrator($this->database->pdo, $this->schema);
            self::assertCount(4, $migrator->migrate($directory));
            $history = $this->scalar("SELECT jsonb_object_agg(version,checksum)::text FROM platform_migrations");
            $f = $this->tenant('tenant-alpha'); $ctx = $f->context();
            $payload = $this->payload($f, 'legacy', 500); $repo = $this->repository($f);
            $repo->materialize($ctx, $payload->ref, $this->handle($f, 'legacy-copy'));
            $this->vault->backend[$f->tenant->value . ':legacy-copy'] = 'CHILD-TEXT';
            $accepted = (new IntakeRepository($f->environment))->accept($f->input($ctx), $f->snapshot($ctx));
            $db = $f->environment->db;
            $db->execute('INSERT INTO ' . $db->table('responses') . '(tenant_id,id,run_id,response_key,payload_hash,payload_ref_id)
                VALUES (:tenant,\'response\',:run,\'response\',:hash,\'legacy\')', [
                    'tenant' => $f->tenant->value, 'run' => $accepted->runId->value, 'hash' => substr($payload->contentHash->value,8)]);
            $db->execute('INSERT INTO ' . $db->table('delivery_intents') . '(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash,outcome,receipt_ref)
                VALUES (:tenant,\'delivery\',:run,\'response\',\'chat\',\'delivery\',:hash,\'success\',NULL)', [
                    'tenant' => $f->tenant->value, 'run' => $accepted->runId->value, 'hash' => substr($payload->contentHash->value,8)]);
            $db->execute('INSERT INTO ' . $db->table('delivery_receipts') . '(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome)
                VALUES (:tenant,\'unknown-receipt\',\'delivery\',0,:hash,\'unknown\')', [
                    'tenant' => $f->tenant->value, 'hash' => substr($payload->contentHash->value,8)]);
            $this->awaitExpiry($payload->retentionUntil);
            self::assertFalse($this->scalar("SELECT platform_payload_unresolved('tenant-alpha','legacy')"));
            $db->execute('UPDATE ' . $db->table('payload_refs') . ' SET purge_pending_at=clock_timestamp(),purge_token=:token,version=version+1', ['token'=>str_repeat('a',64)]);
            self::assertSame(['0005_delivery_resolution.sql'], $migrator->migrate());
            self::assertTrue($this->scalar("SELECT platform_payload_unresolved('tenant-alpha','legacy')"));
            self::assertSame($history, $this->scalar("SELECT jsonb_object_agg(version,checksum)::text FROM platform_migrations WHERE version<>'0005_delivery_resolution.sql'"));
            self::assertSame([], $migrator->migrate());
            $this->fails(ContractViolation::class, fn () => $repo->purge($ctx, $payload->ref));
            self::assertCount(0, $this->vault->deletions, 'Legacy reservation must recheck receipts before external IO');
            self::assertCount(2, $this->vault->backend);
            self::assertSame(str_repeat('a',64), $this->scalar('SELECT purge_token FROM payload_refs'));
            $this->fails(ContractViolation::class, fn () => $db->execute('UPDATE ' . $db->table('payload_refs') . ' SET encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp(),version=version+1'));
            $receiptSql = 'INSERT INTO ' . $db->table('delivery_receipts') . '(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref)
                VALUES (:tenant,\'known-receipt\',\'delivery\',1,:hash,\'success\',\'owner:receipt\') ON CONFLICT (tenant_id,delivery_id,receipt_version) DO NOTHING';
            $receiptParams = ['tenant' => $f->tenant->value, 'hash' => substr($payload->contentHash->value,8)];
            $db->execute($receiptSql, $receiptParams);
            $this->fails(ContractViolation::class, fn () => $repo->purge($ctx, $payload->ref));
            self::assertCount(0, $this->vault->deletions, 'Receipt must also be bound to the intent');
            $db->execute('UPDATE ' . $db->table('delivery_intents') . ' SET version=version+1,receipt_ref=\'owner:receipt\'');
            $repo->purge($ctx, $payload->ref);
            self::assertSame('purged', $repo->status($ctx, $payload->ref));
            self::assertSame([], $this->vault->backend);
            self::assertSame('unknown', $this->scalar("SELECT outcome FROM delivery_receipts WHERE id='unknown-receipt'"));
            self::assertSame(1, $this->scalar('SELECT count(*) FROM responses'));
            $db->execute($receiptSql, $receiptParams); // Bound receipt replay survives purge.
            self::assertSame(2, $this->scalar('SELECT count(*) FROM delivery_receipts'));
            $count = count($this->vault->deletions); $repo->purge($ctx, $payload->ref);
            self::assertCount($count, $this->vault->deletions);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
            $this->database->dropSchema($this->schema);
            $this->schema = $original;
            $this->database->pdo->exec('SET search_path TO "' . $original . '", pg_catalog');
        }
    }

    public function testResolverFailuresAuditAndRevoke(): void
    {
        $f = $this->alpha; $payload = $this->payload($f); $repo = $this->repository($f);
        $this->fault('payload_access_audit','INSERT');
        try { $this->fails(StorageFailure::class, fn () => $repo->dereference($f->context(), $payload->ref)); }
        finally { $this->dropFault('payload_access_audit'); }
        self::assertSame(0, $this->vault->reads, 'Mandatory audit failure denies before resolver');
        $this->vault->failRead = true;
        $this->fails(StorageFailure::class, fn () => $repo->dereference($f->context(), $payload->ref));
        $this->vault->failRead = false;
        $this->vault->backend[$f->tenant->value . ':payload'] = 'CORRUPTED';
        $this->fails(IntegrityConflict::class, fn () => $repo->dereference($f->context(), $payload->ref));
        $this->vault->backend[$f->tenant->value . ':payload'] = 'CHILD-TEXT';
        $this->vault->onRead = function (): void { $this->policy->denied = true; };
        $this->fails(ContractViolation::class, fn () => $repo->dereference($f->context(), $payload->ref));
        self::assertNull($this->vault->credential); self::assertNull($this->vault->cachedBytes);
        $audit = $this->scalar('SELECT json_agg(to_jsonb(a))::text FROM "' . $this->schema . '".payload_access_audit a');
        self::assertIsString($audit); self::assertStringNotContainsString('SECRET',$audit); self::assertStringNotContainsString('CHILD-TEXT',$audit);
        self::assertStringContainsString('denied', $audit); self::assertStringContainsString('integrity_conflict', $audit);
    }

    public function testReadPurgeDisclosureFence(): void
    {
        $f = $this->alpha; $payload = $this->payload($f, 'racing', 1000); $repo = $this->repository($f);
        $this->vault->onRead = function () use ($payload,$repo,$f): void {
            $this->awaitExpiry($payload->retentionUntil);
            $repo->purge($f->context(), $payload->ref);
        };
        $this->fails(ContractViolation::class, fn () => $repo->dereference($f->context(), $payload->ref));
        self::assertSame('purged', $repo->status($f->context(), $payload->ref));
        self::assertSame(1, $this->vault->reads);
        self::assertSame(0, $this->scalar('SELECT count(*) FROM "' . $this->schema . '".payload_access_audit WHERE outcome=\'released\''));
    }

    private function fault(string $table, string $operation): void
    {
        $this->database->pdo->exec('CREATE FUNCTION "' . $this->schema . '".test_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'fault\'; END $$');
        $this->database->pdo->exec('CREATE TRIGGER test_fault AFTER ' . $operation . ' ON "' . $this->schema . '"."' . $table . '" FOR EACH ROW EXECUTE FUNCTION "' . $this->schema . '".test_fault()');
    }

    private function dropFault(string $table): void
    {
        $this->database->pdo->exec('DROP TRIGGER test_fault ON "' . $this->schema . '"."' . $table . '"');
        $this->database->pdo->exec('DROP FUNCTION "' . $this->schema . '".test_fault()');
    }
}
