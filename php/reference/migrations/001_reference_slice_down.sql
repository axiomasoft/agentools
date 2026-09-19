DROP INDEX IF EXISTS idx_audit_tenant;
DROP INDEX IF EXISTS idx_idempotency_tenant;
DROP TABLE IF EXISTS audit_events;
DROP TABLE IF EXISTS idempotency_records;
DROP TABLE IF EXISTS session_projections;
DROP TABLE IF EXISTS journal_events;
DROP TABLE IF EXISTS schema_migrations;
