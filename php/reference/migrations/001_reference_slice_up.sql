CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE journal_events (
    event_id TEXT NOT NULL,
    action_id TEXT NOT NULL,
    seq INTEGER NOT NULL,
    expected_prev_seq INTEGER NOT NULL,
    ts TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    actor_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    event_version INTEGER NOT NULL,
    spec_rev TEXT NOT NULL,
    epoch INTEGER,
    prev_hash TEXT,
    payload_json TEXT NOT NULL,
    PRIMARY KEY (action_id, event_id),
    UNIQUE (action_id, seq)
);

CREATE TABLE session_projections (
    action_id TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    seq INTEGER NOT NULL,
    spec_rev TEXT NOT NULL,
    epoch INTEGER NOT NULL DEFAULT 0,
    projected_at TEXT
);

CREATE TABLE idempotency_records (
    storage_key TEXT PRIMARY KEY,
    tenant_id TEXT NOT NULL,
    tool_name TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    result_json TEXT,
    digest TEXT
);

CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL,
    event_json TEXT NOT NULL,
    exported_at TEXT NOT NULL
);

CREATE INDEX idx_idempotency_tenant ON idempotency_records(tenant_id);
CREATE INDEX idx_audit_tenant ON audit_events(tenant_id);
