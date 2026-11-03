<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Explicit deployment setup; no runtime DDL. Payloads and security audit have separate retention. */
final class Schema
{
    public static function install(Connection $db): void
    {
        $db->transaction(function () use ($db): void {
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('observability_events').' (
                tenant_id text NOT NULL,id text NOT NULL,event_hash text NOT NULL CHECK(event_hash ~ \'^sha-256:[0-9a-f]{64}$\'),
                document jsonb NOT NULL CHECK(jsonb_typeof(document)=\'object\' AND octet_length(document::text)<=16384),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(tenant_id,id))');
            $db->execute('DROP TRIGGER IF EXISTS append_only ON '.$db->table('observability_events'));
            $db->execute('CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON '.$db->table('observability_events').
                ' FOR EACH ROW EXECUTE FUNCTION '.$db->table('platform_append_only_guard').'()');
            $db->execute('CREATE TABLE IF NOT EXISTS '.$db->table('observability_diagnostics').' (
                tenant_id text NOT NULL,id text NOT NULL,session_id text NOT NULL,target_ref text NOT NULL,
                binding_hash text NOT NULL,content_hash text NOT NULL,key_ref text NOT NULL,
                ciphertext text,nonce text,expires_at timestamptz NOT NULL,purged_at timestamptz,
                hold_ref text,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),PRIMARY KEY(tenant_id,id),
                FOREIGN KEY(tenant_id,session_id) REFERENCES '.$db->table('sessions').'(tenant_id,id),
                CHECK ((purged_at IS NULL AND ciphertext IS NOT NULL AND nonce IS NOT NULL)
                    OR (purged_at IS NOT NULL AND ciphertext IS NULL AND nonce IS NULL)))');
            $db->execute('CREATE OR REPLACE FUNCTION '.$db->table('observability_diagnostic_guard').'() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP=\'DELETE\' THEN RAISE EXCEPTION \'diagnostic tombstone required\' USING ERRCODE=\'23514\'; END IF;
                    IF OLD.purged_at IS NOT NULL THEN RAISE EXCEPTION \'immutable diagnostic tombstone\' USING ERRCODE=\'23514\'; END IF;
                    IF NEW.ciphertext IS DISTINCT FROM OLD.ciphertext OR NEW.nonce IS DISTINCT FROM OLD.nonce OR NEW.purged_at IS DISTINCT FROM OLD.purged_at THEN
                        IF NEW.ciphertext IS NOT NULL OR NEW.nonce IS NOT NULL OR NEW.purged_at IS NULL
                            OR OLD.expires_at>clock_timestamp() OR OLD.hold_ref IS NOT NULL THEN
                            RAISE EXCEPTION \'diagnostic retained or immutable\' USING ERRCODE=\'23514\';
                        END IF;
                    END IF;
                    RETURN NEW;
                END $$');
            $db->execute('DROP TRIGGER IF EXISTS retention_guard ON '.$db->table('observability_diagnostics'));
            $db->execute('CREATE TRIGGER retention_guard BEFORE UPDATE OR DELETE ON '.$db->table('observability_diagnostics').
                ' FOR EACH ROW EXECUTE FUNCTION '.$db->table('observability_diagnostic_guard').'()');
            $db->execute('DROP TRIGGER IF EXISTS identity_guard ON '.$db->table('observability_diagnostics'));
            $db->execute('CREATE TRIGGER identity_guard BEFORE UPDATE ON '.$db->table('observability_diagnostics').
                ' FOR EACH ROW EXECUTE FUNCTION '.$db->table('platform_identity_guard').
                '(\'tenant_id\',\'id\',\'session_id\',\'target_ref\',\'binding_hash\',\'content_hash\',\'key_ref\',\'expires_at\',\'created_at\')');
        });
    }
}
