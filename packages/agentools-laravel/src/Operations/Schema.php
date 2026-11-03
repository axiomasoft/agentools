<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Explicit deployment setup; doctor and commands never run DDL. Core migrations must already exist. */
final class Schema
{
    public static function install(Connection $db): void
    {
        $db->assertIdle();
        $db->transaction(function () use ($db): void {
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('ops_facade_intents').' (
                tenant_id text NOT NULL, id text NOT NULL, session_id text NOT NULL, actor_ref text NOT NULL,
                request_hash text NOT NULL CHECK (request_hash ~ \'^sha-256:[0-9a-f]{64}$\'),
                verb text NOT NULL, reason text NOT NULL, target_ref text NOT NULL, dry_run boolean NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY(tenant_id,id),
                FOREIGN KEY(tenant_id,session_id) REFERENCES '.$db->table('sessions').'(tenant_id,id) ON DELETE RESTRICT)');
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('ops_facade_receipts').' (
                tenant_id text NOT NULL,id text NOT NULL,result_hash text NOT NULL,result jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY(tenant_id,id),
                FOREIGN KEY(tenant_id,id) REFERENCES '.$db->table('ops_facade_intents').'(tenant_id,id) ON DELETE RESTRICT)');
            foreach (['ops_facade_intents','ops_facade_receipts'] as $table) {
                $db->execute('DROP TRIGGER IF EXISTS append_only ON '.$db->table($table));
                $db->execute('CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON '.$db->table($table).
                    ' FOR EACH ROW EXECUTE FUNCTION '.$db->table('platform_append_only_guard').'()');
            }
        });
    }
}
