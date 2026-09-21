-- agentools_idempotency_leases v1
-- Unique (tenant_id, tool_name, idempotency_key) is the durable IDE-5 arbiter.
-- Partial unique indexes on unexpired rows are forbidden. Cleanup deletes
-- completed rows only.

CREATE TABLE IF NOT EXISTS agentools_idempotency_leases (
    tenant_id TEXT NOT NULL,
    tool_name TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    state TEXT NOT NULL,
    lease_token TEXT,
    lease_until TIMESTAMPTZ,
    attempt BIGINT NOT NULL,
    result_json TEXT,
    result_digest TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (tenant_id, tool_name, idempotency_key),
    CONSTRAINT agentools_idempotency_leases_state_chk
        CHECK (state IN ('in_progress', 'completed'))
);
