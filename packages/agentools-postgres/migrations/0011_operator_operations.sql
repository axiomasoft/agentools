-- Operational audit facts, separate from Action history and domain owner ledgers.
CREATE TABLE operator_operations (
    tenant_id text NOT NULL CHECK (tenant_id<>''),
    id text NOT NULL CHECK (id<>''),
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    kind text NOT NULL CHECK (kind IN ('run_cancel','run_finalize','action_cancel','stream_stop','tts_stop','manual_resolve','compensation_prepare')),
    target_ref text NOT NULL,
    actor_ref text NOT NULL,
    session_ref text NOT NULL,
    scope_hash text NOT NULL,
    request_hash text NOT NULL,
    expected_version bigint NOT NULL CHECK (expected_version>=0),
    expected_epoch bigint NOT NULL CHECK (expected_epoch>=0),
    reason text NOT NULL,
    evidence_ref text,
    PRIMARY KEY(tenant_id,id),
    FOREIGN KEY(tenant_id,session_ref) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX operator_operations_session ON operator_operations(tenant_id,session_ref);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON operator_operations
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();

CREATE TABLE operator_operation_receipts (
    tenant_id text NOT NULL CHECK (tenant_id<>''),
    id text NOT NULL CHECK (id<>''),
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    result_hash text NOT NULL,
    result jsonb NOT NULL CHECK (jsonb_typeof(result)='object' AND octet_length(result::text)<=4096),
    PRIMARY KEY(tenant_id,id),
    FOREIGN KEY(tenant_id,id) REFERENCES operator_operations(tenant_id,id) ON DELETE RESTRICT
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON operator_operation_receipts
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();

CREATE FUNCTION platform_run_cancel_decision_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF NEW.status='cancelled' AND OLD.status IS DISTINCT FROM NEW.status AND NOT EXISTS (
        SELECT 1 FROM operator_operations o JOIN operator_operation_receipts r ON r.tenant_id=o.tenant_id AND r.id=o.id
        WHERE o.tenant_id=NEW.tenant_id AND o.target_ref=NEW.id AND o.kind='run_finalize' AND o.evidence_ref IS NOT NULL
          AND o.expected_version=OLD.version AND o.expected_epoch=OLD.fencing_epoch
          AND r.result->>'status'='cancelled' AND (r.result->>'version')::bigint=NEW.version
          AND (r.result->>'epoch')::bigint=NEW.fencing_epoch
    ) THEN RAISE EXCEPTION 'audited cancellation policy decision required' USING ERRCODE='23514'; END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER cancel_decision_guard AFTER UPDATE ON runs DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION platform_run_cancel_decision_guard();
