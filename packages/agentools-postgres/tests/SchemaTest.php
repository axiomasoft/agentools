<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests;

use AgenToolsPlatform\Postgres\MigrationException;
use AgenToolsPlatform\Postgres\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaTest extends TestCase
{
    private IsolatedDatabase $db;
    private string $schema;
    private Migrator $migrator;
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->db = new IsolatedDatabase();
        $this->schema = 'p3_' . bin2hex(random_bytes(12));
        $this->db->createSchema($this->schema);
        $this->migrator = new Migrator($this->db->pdo, $this->schema);
        $this->migrator->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->pdo->inTransaction()) {
                $this->db->pdo->rollBack();
            }
            $this->db->dropSchema($this->schema);
        }
    }

    private function sql(string $sql): int
    {
        $result = $this->db->pdo->exec($sql);
        self::assertNotFalse($result);
        return $result;
    }

    private function scalar(string $sql): string|int|bool|null
    {
        $result = $this->db->pdo->query($sql);
        self::assertNotFalse($result);
        return $result->fetchColumn();
    }

    /** @return list<string> */
    private function strings(string $sql): array
    {
        $statement = $this->db->pdo->query($sql);
        self::assertNotFalse($statement);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Expected SQL string column');
            }
            $values[] = $value;
        }
        return $values;
    }

    private function rejected(string $sql, string $state): void
    {
        try {
            $this->sql($sql);
            self::fail('Expected PostgreSQL constraint rejection: ' . $state);
        } catch (PDOException $error) {
            self::assertSame($state, (string) $error->getCode());
        }
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, array $values): void
    {
        $stmt = $this->db->pdo->prepare('INSERT INTO ' . $table . '(' . implode(',', array_keys($values))
            . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
        $stmt->execute(array_values($values));
    }

    private function seed(string $tenant = 'a'): void
    {
        $this->insert('instances', ['tenant_id'=>$tenant, 'id'=>'instance', 'config_ref'=>'code:v1',
            'config_hash'=>self::HASH, 'registry_hash'=>self::HASH]);
        $this->insert('sessions', ['tenant_id'=>$tenant, 'id'=>'session', 'instance_id'=>'instance',
            'bundle_ref'=>'code:v1', 'bundle_hash'=>self::HASH]);
        $this->insert('turns', ['tenant_id'=>$tenant, 'id'=>'turn', 'session_id'=>'session', 'source'=>'host',
            'source_event_key'=>'event', 'payload_hash'=>self::HASH, 'input_ref'=>'encrypted:input']);
        $this->insert('runs', ['tenant_id'=>$tenant, 'id'=>'run', 'turn_id'=>'turn']);
        $this->insert('run_steps', ['tenant_id'=>$tenant, 'id'=>'step', 'run_id'=>'run', 'step_key'=>'step']);
        $this->insert('invocation_intents', ['tenant_id'=>$tenant, 'id'=>'invocation', 'run_id'=>'run',
            'step_key'=>'step', 'invocation_key'=>'exchange', 'request_hash'=>self::HASH,
            'request_ref'=>'encrypted:request', 'model_ref'=>'model:pin', 'admission_ref'=>'owner:admission']);
        $this->insert('invocation_results', ['tenant_id'=>$tenant, 'id'=>'result', 'invocation_id'=>'invocation',
            'payload_hash'=>self::HASH, 'outcome'=>'unknown']);
        $this->insert('proposal_map', ['tenant_id'=>$tenant, 'id'=>'proposal', 'run_id'=>'run',
            'invocation_id'=>'invocation', 'proposal_key'=>'proposal', 'payload_hash'=>self::HASH,
            'proposal_ref'=>'encrypted:proposal', 'action_ref'=>'dated:action']);
        $this->insert('effect_intents', ['tenant_id'=>$tenant, 'id'=>'effect', 'action_ref'=>'dated:action',
            'effect_key'=>'effect', 'owner_namespace'=>'owner', 'owner_operation_key'=>'operation',
            'request_hash'=>self::HASH, 'request_ref'=>'encrypted:effect']);
        $this->insert('payload_refs', ['tenant_id'=>$tenant, 'id'=>'payload', 'content_hash'=>self::HASH,
            'encrypted_ref'=>'encrypted:response', 'encryption_key_ref'=>'kms:key', 'classification'=>'private',
            'purpose'=>'delivery', 'retention_until'=>'2000-01-01T00:00:00Z']);
        $this->insert('responses', ['tenant_id'=>$tenant, 'id'=>'response', 'run_id'=>'run', 'response_key'=>'response',
            'payload_hash'=>self::HASH, 'payload_ref_id'=>'payload']);
        $this->insert('delivery_intents', ['tenant_id'=>$tenant, 'id'=>'delivery', 'run_id'=>'run',
            'response_id'=>'response', 'destination'=>'chatom:room', 'delivery_key'=>'delivery', 'payload_hash'=>self::HASH]);
        $this->insert('delivery_receipts', ['tenant_id'=>$tenant, 'id'=>'receipt', 'delivery_id'=>'delivery',
            'receipt_version'=>0, 'payload_hash'=>self::HASH, 'outcome'=>'unknown']);
        $this->insert('usage_versions', ['tenant_id'=>$tenant, 'id'=>'usage', 'invocation_id'=>'invocation',
            'usage_version'=>0, 'payload_hash'=>self::HASH]);
        $this->insert('inbox', ['tenant_id'=>$tenant, 'id'=>'inbox', 'source'=>'host', 'source_event_key'=>'event',
            'payload_hash'=>self::HASH, 'turn_id'=>'turn']);
        $this->sql("INSERT INTO outbox(tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,payload_ref_id,deadline_at,retention_until)
            VALUES ('$tenant','outbox','wake','" . self::HASH . "','run.ready','run','payload',clock_timestamp()+interval '1 hour',clock_timestamp()+interval '2 hours')");
    }

    public function testCleanInstallAndReapply(): void
    {
        self::assertSame(12, (int) $this->scalar('SELECT count(*) FROM platform_migrations'));
        self::assertSame([], $this->migrator->migrate());
        $this->seed();
        self::assertSame('accepted', $this->scalar("SELECT status FROM runs WHERE tenant_id='a' AND id='run'"));
    }

    public function testCatalogKeysIndexesAndNullability(): void
    {
        $tables = $this->strings("SELECT tablename FROM pg_tables WHERE schemaname=current_schema() AND tablename <> 'platform_migrations'");
        self::assertCount(37, $tables);
        foreach ($tables as $table) {
            self::assertSame($table==='confirmation_consumed_tokens'?'tenant_id,token_id':'tenant_id,id', $this->scalar("SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
                FROM pg_constraint c CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY k(attnum,ordinality)
                JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum
                WHERE c.contype='p' AND c.conrelid='$table'::regclass"), $table);
            self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM information_schema.columns
                WHERE table_schema=current_schema() AND table_name='$table' AND column_name IN ('tenant_id','id','version','created_at','updated_at') AND is_nullable <> 'NO'"));
        }
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM pg_constraint c
            JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
            WHERE n.nspname=current_schema() AND c.contype='f' AND
            (c.conkey[1] <> 1 OR c.confkey[1] <> 1 OR NOT EXISTS
                (SELECT 1 FROM pg_index i WHERE i.indrelid=c.conrelid AND i.indisvalid
                 AND (SELECT array_agg(k.attnum::smallint ORDER BY k.ord)
                      FROM unnest(i.indkey) WITH ORDINALITY k(attnum,ord)
                      WHERE k.ord <= cardinality(c.conkey)) = c.conkey))"));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM information_schema.columns
            WHERE table_schema=current_schema() AND data_type='timestamp without time zone'"));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM pg_indexes WHERE schemaname=current_schema()
            AND indexname='runs_one_active_per_turn' AND indexdef LIKE '%WHERE%status%completed%failed%cancelled%'"));
    }

    public function testUnsafeLifecycleRefusedBeforeMutation(): void
    {
        foreach (['', 'pgsql:host=127.0.0.1;port=5432;dbname=production',
            'pgsql:host=remote;port=5432;dbname=agentools_test_x',
            'pgsql:host=127.0.0.1;port=5432;dbname=agentools_test_x;options=unsafe'] as $dsn) {
            try {
                new IsolatedDatabase($dsn, 'owner', 'secret');
                self::fail('Unsafe DSN accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('isolated', $error->getMessage());
            }
        }
        try {
            $this->db->dropSchema('public');
            self::fail('Unsafe cleanup accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('namespace', $error->getMessage());
        }
        $this->db->pdo->beginTransaction();
        $database = $this->db->verify()['database'];
        $this->sql('COMMENT ON DATABASE "' . $database . '" IS \'not-isolated\'');
        $newSchema = 'p3_' . bin2hex(random_bytes(12));
        try {
            $this->db->createSchema($newSchema);
            self::fail('Missing allow marker accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('verification failed', $error->getMessage());
        }
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM pg_namespace WHERE nspname='$newSchema'"));
        $this->db->pdo->rollBack();
        self::assertSame('postgresql', $this->db->verify()['engine']);
    }

    public function testCrossTenantForeignKeysRejected(): void
    {
        $this->seed();
        $this->rejected("INSERT INTO sessions(tenant_id,id,instance_id,bundle_ref,bundle_hash) VALUES ('b','bad','instance','pin','" . self::HASH . "')", '23503');
        // Exercise each FK on a real stored child: tenant b has no parents.
        $tables = ['sessions','turns','runs','run_steps','inbox','invocation_intents','invocation_results',
            'proposal_map','effect_intents','responses','delivery_intents','delivery_receipts','usage_versions','outbox'];
        foreach ($tables as $table) {
            $columns = $this->strings("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='$table' ORDER BY ordinal_position");
            $select = implode(',', array_map(static fn (string $col): string => $col === 'tenant_id' ? "'b'" : $col, $columns));
            $this->rejected("INSERT INTO $table SELECT $select FROM $table WHERE tenant_id='a'", '23503');
        }
        $this->seed('b'); // Identical opaque IDs in different tenants are valid.
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM runs'));
        $this->sql("INSERT INTO runs(tenant_id,id,turn_id,status,terminal_code) VALUES ('a','other','turn','completed','completed')");
        $this->rejected("INSERT INTO proposal_map(tenant_id,id,run_id,invocation_id,proposal_key,payload_hash,proposal_ref,action_ref)
            VALUES ('a','bad-proposal','other','invocation','other','" . self::HASH . "','encrypted:proposal','other-action')", '23503');
        $this->rejected("INSERT INTO delivery_intents(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash)
            VALUES ('a','bad-delivery','other','response','other','other','" . self::HASH . "')", '23503');
    }

    public function testStableIdentitiesAndHashConflicts(): void
    {
        $this->seed();
        foreach (['turns','inbox','invocation_intents','invocation_results','proposal_map','effect_intents',
            'responses','delivery_intents','delivery_receipts','usage_versions','outbox'] as $table) {
            $columns = $this->strings("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='$table' ORDER BY ordinal_position");
            $select = implode(',', array_map(static fn (string $col): string => $col === 'id' ? "'different-id'" : $col, $columns));
            $this->rejected("INSERT INTO $table SELECT $select FROM $table", '23505');
            $hash = in_array($table, ['invocation_intents','effect_intents'], true) ? 'request_hash' : 'payload_hash';
            $this->rejected("UPDATE $table SET $hash='" . str_repeat('b',64) . "',version=version+1", '23505');
        }
        // A replay can inspect and compare the saved hash without rewriting history.
        self::assertSame(self::HASH, $this->scalar("SELECT payload_hash FROM turns WHERE tenant_id='a' AND source='host' AND source_event_key='event'"));
        foreach (['invocation_results','proposal_map','usage_versions','delivery_receipts'] as $table) {
            $this->rejected("DELETE FROM $table", '23505');
        }
    }

    public function testActiveRunAndUnknownRemainUnresolved(): void
    {
        $this->seed();
        $this->rejected("INSERT INTO runs(tenant_id,id,turn_id,status) VALUES ('a','duplicate','turn','waiting_external')", '23505');
        $this->rejected("INSERT INTO runs(tenant_id,id,turn_id,status) VALUES ('a','bad','turn','unknown')", '23514');
        $this->rejected("UPDATE runs SET status='completed',version=version+1", '23514');
        self::assertSame(1, $this->sql("UPDATE runs SET status='completed',terminal_code='completed',version=version+1 WHERE tenant_id='a' AND id='run' AND version=0"));
        $this->sql("INSERT INTO runs(tenant_id,id,turn_id) VALUES ('a','next','turn')");
        self::assertSame('unknown', $this->scalar('SELECT outcome FROM invocation_results'));
    }

    public function testVersionCasAndFractionalDbClock(): void
    {
        $this->seed();
        $this->rejected("UPDATE runs SET version=-1", '23514');
        $this->rejected("UPDATE runs SET checkpoint='{}'", '23514');
        $this->rejected("UPDATE runs SET version=version+2", '23514');
        $this->sql("UPDATE runs SET version=version+1,claim_owner='worker',fencing_epoch=fencing_epoch+1,lease_until=clock_timestamp()+interval '1 hour 0.125 second' WHERE tenant_id='a' AND id='run' AND version=0");
        self::assertSame(0, $this->sql("UPDATE runs SET version=version+1 WHERE tenant_id='a' AND id='run' AND version=0"));
        self::assertSame(0, $this->sql("UPDATE runs SET version=version+1 WHERE tenant_id='a' AND id='run' AND version=1 AND fencing_epoch=0"));
        self::assertSame(1, $this->sql("UPDATE runs SET version=version+1 WHERE tenant_id='a' AND id='run' AND version=1 AND fencing_epoch=1 AND claim_owner='worker' AND lease_until>clock_timestamp()"));
        self::assertSame('0.125000', $this->scalar("SELECT (extract(epoch FROM (timestamptz '2026-01-01 00:00:00.125Z' - timestamptz '2026-01-01 00:00:00Z')))::text"));
    }

    public function testBoundedCheckpointAndNullableUsage(): void
    {
        $this->seed();
        $this->rejected("UPDATE runs SET checkpoint='[]',version=version+1", '23514');
        $this->rejected("UPDATE runs SET checkpoint=jsonb_build_object('x',repeat('x',17000)),version=version+1", '23514');
        $this->rejected("INSERT INTO usage_versions(tenant_id,id,invocation_id,usage_version,payload_hash,input_tokens) VALUES ('a','bad','invocation',1,'" . self::HASH . "',-1)", '23514');
        self::assertNull($this->scalar('SELECT input_tokens FROM usage_versions'));
        $this->sql("INSERT INTO usage_versions(tenant_id,id,invocation_id,usage_version,payload_hash,input_tokens,output_tokens) VALUES ('a','zero','invocation',1,'" . self::HASH . "',0,0)");
        self::assertSame(0, $this->scalar("SELECT input_tokens FROM usage_versions WHERE id='zero'"));
        $this->rejected('UPDATE inbox SET attempt_count=max_attempts+1,version=version+1', '23514');
        $this->rejected("UPDATE outbox SET claim_owner='worker',version=version+1", '23514');
    }

    public function testRetentionProtectsUnresolvedAndKeepsTombstone(): void
    {
        $this->seed();
        $this->rejected('DELETE FROM outbox', '23514');
        $this->rejected("UPDATE payload_refs SET encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp(),version=version+1", '23514');
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM outbox_retention_candidates'));
        $this->sql("UPDATE outbox SET status='unknown',version=version+1");
        $this->rejected('DELETE FROM outbox', '23514');
        $this->sql("UPDATE outbox SET status='dispatched',dispatched_at=clock_timestamp(),version=version+1");
        $this->rejected('DELETE FROM outbox', '23514'); // Still within retention.
        $this->rejected("UPDATE outbox SET retention_until=clock_timestamp(),version=version+1", '23505');
        $this->rejected("UPDATE payload_refs SET purge_pending_at=clock_timestamp(),purge_token='" . self::HASH . "',version=version+1", '23514');
        $this->rejected("UPDATE delivery_intents SET outcome='success',version=version+1", '23514');
        $this->rejected("UPDATE payload_refs SET purge_pending_at=clock_timestamp(),purge_token='" . self::HASH . "',version=version+1", '23514');
        $this->sql("INSERT INTO delivery_receipts(tenant_id,id,delivery_id,receipt_version,payload_hash,outcome,receipt_ref)
            VALUES ('a','resolved','delivery',1,'" . self::HASH . "','success','owner:receipt')");
        $this->sql("UPDATE delivery_intents SET outcome='success',receipt_ref='owner:receipt',version=version+1");
        self::assertSame('unknown', $this->scalar("SELECT outcome FROM delivery_receipts WHERE id='receipt'"));
        $this->sql("UPDATE payload_refs SET purge_pending_at=clock_timestamp(),purge_token='" . self::HASH . "',version=version+1");
        $this->sql("UPDATE payload_refs SET encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp(),version=version+1");
        self::assertSame(self::HASH, $this->scalar('SELECT content_hash FROM payload_refs'));
        self::assertNull($this->scalar('SELECT encrypted_ref FROM payload_refs'));
        $this->rejected('DELETE FROM payload_refs', '23514');
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM responses'));
        $this->sql("INSERT INTO payload_refs(tenant_id,id,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES ('a','retained','" . self::HASH . "','encrypted:retained','kms:key','private','test',clock_timestamp()+interval '1 day')");
        $this->rejected("UPDATE payload_refs SET encrypted_ref=NULL,encryption_key_ref=NULL,purged_at=clock_timestamp(),version=version+1 WHERE id='retained'", '23514');
        $this->sql("INSERT INTO outbox(tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,status,created_at,deadline_at,retention_until,dispatched_at)
            VALUES ('a','expired','expired','" . self::HASH . "','test','run','dispatched','2000-01-01Z','2000-01-02Z','2000-01-03Z','2000-01-02Z')");
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM outbox_retention_candidates'));
        self::assertSame(1, $this->sql("DELETE FROM outbox WHERE id='expired'"));
    }

    public function testDeliveryResolutionRequiresBoundLatestReceipt(): void
    {
        $this->seed();
        $this->seed('b');
        $this->sql("UPDATE outbox SET status='dispatched',dispatched_at=clock_timestamp(),version=version+1 WHERE tenant_id='a'");
        $resolve = "UPDATE delivery_intents SET outcome='success',receipt_ref='owner:receipt',version=version+1 WHERE tenant_id='a'";
        $this->insert('delivery_receipts', ['tenant_id'=>'b', 'id'=>'known', 'delivery_id'=>'delivery',
            'receipt_version'=>1, 'payload_hash'=>self::HASH, 'outcome'=>'success', 'receipt_ref'=>'owner:receipt']);
        $this->rejected($resolve, '23514'); // A foreign receipt is not evidence.
        $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'wrong-hash', 'delivery_id'=>'delivery',
            'receipt_version'=>1, 'payload_hash'=>str_repeat('b',64), 'outcome'=>'success', 'receipt_ref'=>'owner:receipt']);
        $this->rejected($resolve, '23514');
        foreach ([null, '', '   '] as $index => $ref) {
            $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'unbound-' . $index, 'delivery_id'=>'delivery',
                'receipt_version'=>$index+2, 'payload_hash'=>self::HASH, 'outcome'=>'success', 'receipt_ref'=>$ref]);
            $literal = $ref === null ? 'NULL' : "'$ref'";
            $this->rejected("UPDATE delivery_intents SET outcome='success',receipt_ref=$literal,version=version+1 WHERE tenant_id='a'", '23514');
        }
        $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'known', 'delivery_id'=>'delivery',
            'receipt_version'=>5, 'payload_hash'=>self::HASH, 'outcome'=>'success', 'receipt_ref'=>'owner:receipt']);
        $this->rejected("UPDATE delivery_intents SET outcome='success',receipt_ref='owner:other',version=version+1 WHERE tenant_id='a'", '23514');
        $this->sql($resolve);
        $this->sql($resolve); // Replay of the same resolution stays valid.
        self::assertFalse($this->scalar("SELECT platform_payload_unresolved('a','payload')"));
        $observer = new IsolatedDatabase();
        $observer->pdo->exec('SET search_path TO "' . $this->schema . '", pg_catalog');
        $observer->pdo->exec("SET lock_timeout='100ms'");
        $this->db->pdo->beginTransaction();
        try {
            $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'uncommitted', 'delivery_id'=>'delivery',
                'receipt_version'=>6, 'payload_hash'=>self::HASH, 'outcome'=>'unknown']);
            try {
                $observer->pdo->exec("UPDATE payload_refs SET purge_pending_at=clock_timestamp(),purge_token='" . self::HASH . "',version=version+1 WHERE tenant_id='a'");
                self::fail('Receipt insert did not serialize with purge');
            } catch (PDOException $error) { self::assertSame('55P03', (string) $error->getCode()); }
        } finally { $this->db->pdo->rollBack(); }
        $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'new-unknown', 'delivery_id'=>'delivery',
            'receipt_version'=>6, 'payload_hash'=>self::HASH, 'outcome'=>'unknown', 'created_at'=>'2000-01-01Z']);
        self::assertTrue($this->scalar("SELECT platform_payload_unresolved('a','payload')"));
        $this->rejected($resolve, '23514'); // An older success cannot erase the head.
        $this->rejected("UPDATE payload_refs SET purge_pending_at=clock_timestamp(),purge_token='" . self::HASH . "',version=version+1 WHERE tenant_id='a'", '23514');
        $this->insert('delivery_receipts', ['tenant_id'=>'a', 'id'=>'known-failure', 'delivery_id'=>'delivery',
            'receipt_version'=>7, 'payload_hash'=>self::HASH, 'outcome'=>'failed', 'receipt_ref'=>'owner:failure']);
        $this->sql("UPDATE delivery_intents SET outcome='failed',receipt_ref='owner:failure',version=version+1 WHERE tenant_id='a'");
        self::assertFalse($this->scalar("SELECT platform_payload_unresolved('a','payload')"));
        self::assertSame('unknown', $this->scalar("SELECT outcome FROM delivery_receipts WHERE tenant_id='a' AND id='receipt'"));
        $this->rejected("UPDATE delivery_receipts SET outcome='success',version=version+1 WHERE tenant_id='a' AND id='receipt'", '23505');
    }

    /** @param callable(string): void $test */
    private function migrationFiles(callable $test): void
    {
        $directory = sys_get_temp_dir() . '/agentools_p3_' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($directory, 0700));
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') ?: [] as $file) {
            self::assertTrue(copy($file, $directory . '/' . basename($file)));
        }
        try {
            $test($directory);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testMigrationChecksumAndHistoryGuard(): void
    {
        $this->migrationFiles(function (string $directory): void {
            file_put_contents($directory . '/0001_intake.sql', "\n-- changed checksum\n", FILE_APPEND);
            try {
                $this->migrator->migrate($directory);
                self::fail('Changed applied migration accepted');
            } catch (MigrationException $error) {
                self::assertStringContainsString('checksum', $error->getMessage());
            }
        });
        $this->sql("INSERT INTO platform_migrations(version,checksum) VALUES ('9999_unknown.sql','" . self::HASH . "')");
        try {
            $this->migrator->migrate();
            self::fail('Unknown migration accepted');
        } catch (MigrationException $error) {
            self::assertStringContainsString('prefix', $error->getMessage());
        }
    }

    public function testFailedUpgradeRollsBackDdlAndHistory(): void
    {
        $this->seed();
        $this->migrationFiles(function (string $directory): void {
            file_put_contents($directory . '/0013_fault.sql', 'CREATE TABLE should_rollback(id int); SELECT 1/0;');
            try {
                $this->migrator->migrate($directory);
                self::fail('Faulted upgrade succeeded');
            } catch (MigrationException $error) {
                self::assertSame('Migration failed and was rolled back', $error->getMessage());
            }
        });
        self::assertNull($this->scalar("SELECT to_regclass('should_rollback')"));
        self::assertSame(12, (int) $this->scalar('SELECT count(*) FROM platform_migrations'));
        self::assertSame('accepted', $this->scalar("SELECT status FROM runs WHERE tenant_id='a' AND id='run'"));
        self::assertSame([], $this->migrator->migrate());
    }

    public function testAdditiveUpgradeOldReaderAndTransactionBoundary(): void
    {
        $this->migrationFiles(function (string $directory): void {
            unlink($directory . '/0002_durable_refs.sql');
            unlink($directory . '/0003_action_journal.sql');
            unlink($directory . '/0004_payload_lifecycle.sql');
            unlink($directory . '/0005_delivery_resolution.sql');
            unlink($directory . '/0006_delivery_claims.sql');
            unlink($directory . '/0007_action_submissions.sql');
            unlink($directory . '/0008_payload_holds.sql');
            unlink($directory . '/0009_confirmation.sql');
            unlink($directory . '/0010_effect_recovery.sql');
            unlink($directory . '/0011_operator_operations.sql');
            unlink($directory . '/0012_derived_payloads.sql');
            $schema = 'p3_' . bin2hex(random_bytes(12));
            $this->db->createSchema($schema);
            try {
                $migrator = new Migrator($this->db->pdo, $schema);
                self::assertSame(['0001_intake.sql'], $migrator->migrate($directory));
                $this->sql("INSERT INTO instances(tenant_id,id,config_ref,config_hash,registry_hash) VALUES ('a','old','code:v1','" . self::HASH . "','" . self::HASH . "')");
                $before = $this->scalar("SELECT config_ref FROM instances WHERE tenant_id='a' AND id='old'");
                self::assertSame(['0002_durable_refs.sql', '0003_action_journal.sql', '0004_payload_lifecycle.sql', '0005_delivery_resolution.sql', '0006_delivery_claims.sql', '0007_action_submissions.sql', '0008_payload_holds.sql', '0009_confirmation.sql', '0010_effect_recovery.sql', '0011_operator_operations.sql', '0012_derived_payloads.sql'], $migrator->migrate());
                self::assertSame($before, $this->scalar("SELECT config_ref FROM instances WHERE tenant_id='a' AND id='old'"));
                self::assertSame([], $migrator->migrate());
                $this->db->pdo->beginTransaction();
                try {
                    $migrator->migrate();
                    self::fail('Nested migration accepted');
                } catch (MigrationException $error) {
                    self::assertStringContainsString('caller transaction', $error->getMessage());
                }
                self::assertTrue($this->db->pdo->inTransaction());
                $this->db->pdo->rollBack();
            } finally {
                $this->db->dropSchema($schema);
                $this->db->pdo->exec('SET search_path TO "' . $this->schema . '", pg_catalog');
            }
        });
    }
}
