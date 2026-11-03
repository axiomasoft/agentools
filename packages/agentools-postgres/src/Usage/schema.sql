CREATE TABLE {{schema}}.accounting_usage (
    tenant_id text NOT NULL, id text NOT NULL, invocation_id text NOT NULL,
    usage_version bigint NOT NULL CHECK (usage_version > 0),
    payload_hash text NOT NULL, document jsonb NOT NULL,
    supersedes text, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,invocation_id,usage_version),
    FOREIGN KEY (tenant_id,supersedes) REFERENCES {{schema}}.accounting_usage(tenant_id,id),
    CHECK (tenant_id <> '' AND id <> '' AND payload_hash ~ '^sha-256:[0-9a-f]{64}$')
);
CREATE FUNCTION {{schema}}.accounting_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable accounting fact' USING ERRCODE='23514'; END $$;
CREATE TRIGGER accounting_immutable BEFORE UPDATE OR DELETE ON {{schema}}.accounting_usage
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.accounting_immutable();
CREATE TABLE {{schema}}.accounting_export (
    tenant_id text NOT NULL, id text NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','claimed','unknown','exported','quarantined','manual_resolution_required')),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0), epoch bigint NOT NULL DEFAULT 0 CHECK (epoch >= 0),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0), lease_until timestamptz,
    next_at timestamptz NOT NULL DEFAULT clock_timestamp(), ack_hash text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,id) REFERENCES {{schema}}.accounting_usage(tenant_id,id)
);
CREATE INDEX accounting_export_pending ON {{schema}}.accounting_export(tenant_id,next_at) WHERE status IN ('pending','claimed','unknown');
CREATE TABLE {{schema}}.accounting_quarantine (
    tenant_id text NOT NULL, id text NOT NULL, incoming_hash text NOT NULL, accepted_hash text NOT NULL,
    code text NOT NULL CHECK (code IN ('USAGE_PAYLOAD_CONFLICT','ADMISSION_PAYLOAD_CONFLICT')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY (tenant_id,id,incoming_hash)
);
