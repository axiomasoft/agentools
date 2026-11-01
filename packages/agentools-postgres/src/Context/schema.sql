CREATE TABLE {{schema}}.context_snapshots (
    tenant_id text NOT NULL,
    id text NOT NULL,
    revision integer NOT NULL CHECK (revision = 1),
    content_hash text NOT NULL,
    scope_hash text NOT NULL,
    manifest_json text NOT NULL,
    manifest_hash text NOT NULL,
    PRIMARY KEY (tenant_id, id)
);
-- No rendered prompt, raw tool payload or plaintext diagnostics column.
