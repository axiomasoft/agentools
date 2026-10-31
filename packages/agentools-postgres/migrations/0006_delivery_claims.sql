-- Delivery owner coordination only: no transcript bytes and no provider/model loop.
CREATE TABLE source_fences (
    tenant_id text NOT NULL CHECK (tenant_id<>''),
    id text NOT NULL CHECK (id<>''),
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    watermark bigint NOT NULL CHECK (watermark > 0),
    closed boolean NOT NULL DEFAULT false,
    PRIMARY KEY (tenant_id,id)
);
CREATE TRIGGER version_guard BEFORE UPDATE ON source_fences FOR EACH ROW EXECUTE FUNCTION platform_version_guard();
CREATE FUNCTION platform_source_closed_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF NEW.watermark<OLD.watermark OR (OLD.closed AND NOT NEW.closed) THEN
        RAISE EXCEPTION 'source closure is monotonic' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER closure_guard BEFORE UPDATE ON source_fences FOR EACH ROW EXECUTE FUNCTION platform_source_closed_guard();

CREATE TABLE delivery_controls (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    session_id text NOT NULL,
    source_ref text NOT NULL,
    source_revision bigint NOT NULL CHECK (source_revision>0),
    response_version bigint NOT NULL CHECK (response_version>0),
    policy_revision text NOT NULL,
    display_hash text NOT NULL CHECK (display_hash ~ '^[0-9a-f]{64}$'),
    state text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','sending','delivered','unknown','failed','cancelled')),
    cancelled boolean NOT NULL DEFAULT false,
    claim_owner text,
    lease_until timestamptz,
    fencing_epoch bigint NOT NULL DEFAULT 0 CHECK (fencing_epoch>=0),
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count>=0),
    max_attempts integer NOT NULL DEFAULT 12 CHECK (max_attempts BETWEEN 1 AND 100),
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,id) REFERENCES delivery_intents(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,session_id) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,source_ref) REFERENCES source_fences(tenant_id,id) ON DELETE RESTRICT,
    CHECK ((claim_owner IS NULL)=(lease_until IS NULL)),
    CHECK (attempt_count<=max_attempts)
);
CREATE INDEX delivery_controls_scan ON delivery_controls(tenant_id,session_id,state,lease_until,id);
CREATE INDEX delivery_controls_source ON delivery_controls(tenant_id,source_ref);
CREATE TRIGGER version_guard BEFORE UPDATE ON delivery_controls FOR EACH ROW EXECUTE FUNCTION platform_version_guard();
CREATE TRIGGER identity_guard BEFORE UPDATE ON delivery_controls FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('session_id','source_ref','source_revision','response_version','policy_revision','display_hash','max_attempts');
CREATE FUNCTION platform_delivery_control_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF OLD.cancelled AND NOT NEW.cancelled THEN
        RAISE EXCEPTION 'delivery cancellation is monotonic' USING ERRCODE='23514';
    END IF;
    IF OLD.state IN ('delivered','failed') AND NEW.state<>OLD.state THEN
        RAISE EXCEPTION 'preserve known destination fact' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER state_guard BEFORE UPDATE ON delivery_controls FOR EACH ROW EXECUTE FUNCTION platform_delivery_control_guard();

CREATE TABLE input_source_bindings (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    session_id text NOT NULL,
    source_ref text NOT NULL,
    source_revision bigint NOT NULL CHECK (source_revision>0),
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,session_id) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,source_ref) REFERENCES source_fences(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX input_source_bindings_session ON input_source_bindings(tenant_id,session_id);
CREATE INDEX input_source_bindings_source ON input_source_bindings(tenant_id,source_ref);
CREATE INDEX turns_input_reference ON turns(tenant_id,input_ref);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON input_source_bindings FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();

CREATE TABLE source_invalidation_receipts (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    source_ref text NOT NULL,
    watermark bigint NOT NULL CHECK (watermark>0),
    PRIMARY KEY (tenant_id,id)
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON source_invalidation_receipts FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
