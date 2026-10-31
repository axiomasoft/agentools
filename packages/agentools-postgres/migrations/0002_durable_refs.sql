-- Durable refs for the first read vertical. Dated SDK EventStore remains the
-- authoritative Action stream; action_ref is an opaque binding, not a new journal.
-- Publisher/knowledge/evaluation/scheduler tables are deliberately deferred.

CREATE TABLE payload_refs (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    content_hash text NOT NULL CHECK (content_hash ~ '^[0-9a-f]{64}$'),
    encrypted_ref text,
    encryption_key_ref text,
    classification text NOT NULL,
    purpose text NOT NULL,
    retention_until timestamptz NOT NULL,
    purged_at timestamptz,
    CHECK ((purged_at IS NULL AND encrypted_ref IS NOT NULL AND encryption_key_ref IS NOT NULL) OR (purged_at IS NOT NULL AND encrypted_ref IS NULL AND encryption_key_ref IS NULL))
);

CREATE TRIGGER version_guard BEFORE UPDATE ON payload_refs FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON payload_refs FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('content_hash', 'classification', 'purpose', 'retention_until');

CREATE TABLE invocation_intents (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    run_id text NOT NULL,
    step_key text NOT NULL,
    invocation_key text NOT NULL,
    request_hash text NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
    request_ref text NOT NULL,
    model_ref text NOT NULL,
    admission_ref text NOT NULL,
    UNIQUE (tenant_id, run_id, invocation_key),
    UNIQUE (tenant_id, id, run_id),
    FOREIGN KEY (tenant_id, run_id, step_key) REFERENCES run_steps(tenant_id, run_id, step_key) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON invocation_intents FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON invocation_intents FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('run_id', 'step_key', 'invocation_key', 'request_hash', 'request_ref', 'model_ref', 'admission_ref');

CREATE TABLE invocation_results (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    invocation_id text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    outcome text NOT NULL CHECK (outcome IN ('success','failed','unknown')),
    result_ref text,
    UNIQUE (tenant_id, invocation_id),
    FOREIGN KEY (tenant_id, invocation_id) REFERENCES invocation_intents(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON invocation_results FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON invocation_results FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('invocation_id', 'payload_hash', 'outcome', 'result_ref');

CREATE TABLE proposal_map (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    run_id text NOT NULL,
    invocation_id text NOT NULL,
    proposal_key text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    proposal_ref text NOT NULL,
    action_ref text NOT NULL,
    UNIQUE (tenant_id, run_id, invocation_id, proposal_key),
    UNIQUE (tenant_id, action_ref),
    FOREIGN KEY (tenant_id, invocation_id, run_id) REFERENCES invocation_intents(tenant_id, id, run_id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON proposal_map FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON proposal_map FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('run_id', 'invocation_id', 'proposal_key', 'payload_hash', 'proposal_ref', 'action_ref');

CREATE TABLE effect_intents (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    action_ref text NOT NULL,
    effect_key text NOT NULL,
    owner_namespace text NOT NULL,
    owner_operation_key text NOT NULL,
    request_hash text NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
    request_ref text NOT NULL,
    outcome text NOT NULL DEFAULT 'pending' CHECK (outcome IN ('pending','success','failed','unknown')),
    receipt_ref text,
    UNIQUE (tenant_id, action_ref, effect_key),
    UNIQUE (tenant_id, owner_namespace, owner_operation_key),
    FOREIGN KEY (tenant_id, action_ref) REFERENCES proposal_map(tenant_id, action_ref) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON effect_intents FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON effect_intents FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('action_ref', 'effect_key', 'owner_namespace', 'owner_operation_key', 'request_hash', 'request_ref');

CREATE TABLE responses (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    run_id text NOT NULL,
    response_key text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    payload_ref_id text NOT NULL,
    UNIQUE (tenant_id, run_id, response_key),
    UNIQUE (tenant_id, id, run_id),
    FOREIGN KEY (tenant_id, run_id) REFERENCES runs(tenant_id, id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id, payload_ref_id) REFERENCES payload_refs(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON responses FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON responses FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('run_id', 'response_key', 'payload_hash', 'payload_ref_id');

CREATE TABLE delivery_intents (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    run_id text NOT NULL,
    response_id text NOT NULL,
    destination text NOT NULL,
    delivery_key text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    outcome text NOT NULL DEFAULT 'pending' CHECK (outcome IN ('pending','success','failed','unknown')),
    receipt_ref text,
    UNIQUE (tenant_id, run_id, response_id, destination),
    UNIQUE (tenant_id, delivery_key),
    FOREIGN KEY (tenant_id, response_id, run_id) REFERENCES responses(tenant_id, id, run_id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON delivery_intents FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON delivery_intents FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('run_id', 'response_id', 'destination', 'delivery_key', 'payload_hash');

CREATE TABLE delivery_receipts (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    delivery_id text NOT NULL,
    receipt_version bigint NOT NULL CHECK (receipt_version >= 0),
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    outcome text NOT NULL CHECK (outcome IN ('success','failed','unknown')),
    receipt_ref text,
    UNIQUE (tenant_id, delivery_id, receipt_version),
    FOREIGN KEY (tenant_id, delivery_id) REFERENCES delivery_intents(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON delivery_receipts FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON delivery_receipts FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('delivery_id', 'receipt_version', 'payload_hash', 'outcome', 'receipt_ref');

CREATE TABLE usage_versions (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    invocation_id text NOT NULL,
    usage_version bigint NOT NULL CHECK (usage_version >= 0),
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    input_tokens bigint CHECK (input_tokens >= 0),
    output_tokens bigint CHECK (output_tokens >= 0),
    owner_fact_ref text,
    UNIQUE (tenant_id, invocation_id, usage_version),
    FOREIGN KEY (tenant_id, invocation_id) REFERENCES invocation_intents(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON usage_versions FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON usage_versions FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('invocation_id', 'usage_version', 'payload_hash', 'input_tokens', 'output_tokens', 'owner_fact_ref');

CREATE TABLE outbox (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    event_key text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    event_type text NOT NULL,
    aggregate_ref text NOT NULL,
    payload_ref_id text,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','dispatched','deadletter','unknown')),
    available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deadline_at timestamptz NOT NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    max_attempts integer NOT NULL DEFAULT 8 CHECK (max_attempts > 0),
    claim_owner text,
    claimed_until timestamptz,
    fencing_epoch bigint NOT NULL DEFAULT 0 CHECK (fencing_epoch >= 0),
    dispatched_at timestamptz,
    retention_until timestamptz NOT NULL,
    UNIQUE (tenant_id, event_key),
    CHECK ((claim_owner IS NULL) = (claimed_until IS NULL)),
    CHECK (attempt_count <= max_attempts),
    CHECK ((status = 'dispatched') = (dispatched_at IS NOT NULL)),
    CHECK (deadline_at >= created_at AND retention_until >= deadline_at),
    FOREIGN KEY (tenant_id, payload_ref_id) REFERENCES payload_refs(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON outbox FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON outbox FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('event_key', 'payload_hash', 'event_type', 'aggregate_ref', 'payload_ref_id', 'deadline_at', 'retention_until', 'max_attempts');

-- Scan only terminal, resolved transport receipts for retention. Pending/unknown
-- work and their payload refs cannot be removed by a retention candidate query.
CREATE VIEW outbox_retention_candidates AS
    SELECT tenant_id, id FROM outbox
    WHERE status = 'dispatched' AND retention_until < clock_timestamp();
CREATE FUNCTION platform_outbox_delete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'dispatched' OR OLD.retention_until >= clock_timestamp() THEN
        RAISE EXCEPTION 'unresolved or retained outbox' USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END $$;
CREATE TRIGGER retention_guard BEFORE DELETE ON outbox FOR EACH ROW EXECUTE FUNCTION platform_outbox_delete_guard();
CREATE FUNCTION platform_payload_purge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.purged_at IS NOT NULL OR OLD.retention_until > clock_timestamp()
       OR EXISTS (SELECT 1 FROM outbox WHERE tenant_id = OLD.tenant_id AND payload_ref_id = OLD.id AND status <> 'dispatched') THEN
        RAISE EXCEPTION 'payload retention or unresolved work' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'preserve payload tombstone' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER retention_guard BEFORE UPDATE OF encrypted_ref, encryption_key_ref, purged_at OR DELETE ON payload_refs
    FOR EACH ROW EXECUTE FUNCTION platform_payload_purge_guard();
CREATE INDEX outbox_ready ON outbox(tenant_id, available_at, id) WHERE status = 'pending';
CREATE INDEX inbox_ready ON inbox(tenant_id, available_at, id) WHERE status = 'pending';
CREATE INDEX inbox_claim_expiry ON inbox(tenant_id, claimed_until) WHERE claim_owner IS NOT NULL;
CREATE INDEX outbox_claim_expiry ON outbox(tenant_id, claimed_until) WHERE claim_owner IS NOT NULL;
CREATE INDEX outbox_retention ON outbox(tenant_id, retention_until) WHERE status = 'dispatched';
CREATE INDEX runs_lease_expiry ON runs(tenant_id, lease_until) WHERE claim_owner IS NOT NULL;
CREATE INDEX effect_intents_unresolved ON effect_intents(tenant_id, outcome) WHERE outcome IN ('pending','unknown');
CREATE INDEX delivery_intents_unresolved ON delivery_intents(tenant_id, outcome) WHERE outcome IN ('pending','unknown');
CREATE INDEX payload_refs_retention ON payload_refs(tenant_id, retention_until) WHERE purged_at IS NULL;

CREATE INDEX sessions_parent ON sessions(tenant_id, instance_id);

CREATE INDEX turns_parent ON turns(tenant_id, session_id);

CREATE INDEX runs_parent ON runs(tenant_id, turn_id);

CREATE INDEX run_steps_parent ON run_steps(tenant_id, run_id);

CREATE INDEX inbox_parent ON inbox(tenant_id, turn_id);

CREATE INDEX invocation_intents_parent ON invocation_intents(tenant_id, run_id, step_key);

CREATE INDEX invocation_results_parent ON invocation_results(tenant_id, invocation_id);

CREATE INDEX proposal_map_parent ON proposal_map(tenant_id, invocation_id, run_id);

CREATE INDEX effect_intents_parent ON effect_intents(tenant_id, action_ref);

CREATE INDEX responses_parent ON responses(tenant_id, run_id);

CREATE INDEX delivery_intents_parent ON delivery_intents(tenant_id, response_id, run_id);

CREATE INDEX delivery_receipts_parent ON delivery_receipts(tenant_id, delivery_id);

CREATE INDEX usage_versions_parent ON usage_versions(tenant_id, invocation_id);

CREATE INDEX outbox_parent ON outbox(tenant_id, payload_ref_id);

CREATE INDEX responses_payload ON responses(tenant_id, payload_ref_id);

-- Results and identity mappings are append-only evidence; corrections use a new
-- invocation/usage/receipt version rather than overwriting an accepted record.
CREATE FUNCTION platform_append_only_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'append-only evidence' USING ERRCODE = '23505';
END $$;
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON invocation_results
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON proposal_map
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON usage_versions
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON delivery_receipts
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
