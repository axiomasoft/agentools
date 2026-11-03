CREATE TABLE {{schema}}.qualification_snapshots (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    tuple_hash text NOT NULL, issued_at timestamptz NOT NULL,
    decision text NOT NULL CHECK (decision IN ('qualified','rejected','insufficient_evidence')),
    PRIMARY KEY (tenant_id,id)
);
CREATE INDEX qualification_tuple_lookup ON {{schema}}.qualification_snapshots (tenant_id,tuple_hash,issued_at DESC);
CREATE TABLE {{schema}}.qualification_policies (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    stable_id text NOT NULL, canary_id text,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,stable_id) REFERENCES {{schema}}.qualification_snapshots(tenant_id,id),
    FOREIGN KEY (tenant_id,canary_id) REFERENCES {{schema}}.qualification_snapshots(tenant_id,id)
);
CREATE TABLE {{schema}}.qualification_heads (
    tenant_id text NOT NULL, id text NOT NULL, revision bigint NOT NULL CHECK (revision > 0), policy_id text NOT NULL,
    PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,policy_id) REFERENCES {{schema}}.qualification_policies(tenant_id,id)
);
CREATE TABLE {{schema}}.qualification_audit (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    scope_id text NOT NULL, revision bigint NOT NULL, stable_id text NOT NULL,
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,scope_id,revision),
    FOREIGN KEY (tenant_id,stable_id) REFERENCES {{schema}}.qualification_snapshots(tenant_id,id)
);
CREATE TABLE {{schema}}.qualification_pins (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    policy_id text NOT NULL, snapshot_id text NOT NULL, is_canary boolean NOT NULL,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,policy_id) REFERENCES {{schema}}.qualification_policies(tenant_id,id),
    FOREIGN KEY (tenant_id,snapshot_id) REFERENCES {{schema}}.qualification_snapshots(tenant_id,id)
);
CREATE FUNCTION {{schema}}.qualification_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'qualification records are append-only' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER qualification_snapshot_immutable BEFORE UPDATE OR DELETE ON {{schema}}.qualification_snapshots
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.qualification_immutable();
CREATE TRIGGER qualification_policy_immutable BEFORE UPDATE OR DELETE ON {{schema}}.qualification_policies
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.qualification_immutable();
CREATE TRIGGER qualification_pin_immutable BEFORE UPDATE OR DELETE ON {{schema}}.qualification_pins
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.qualification_immutable();
CREATE TRIGGER qualification_audit_immutable BEFORE UPDATE OR DELETE ON {{schema}}.qualification_audit
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.qualification_immutable();
