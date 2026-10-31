-- Platform technical persistence only; no transcript, money ledger or profile publisher.
-- Opaque refs/hashes never authorize reads or effects. No nullable global tenant namespace.
CREATE FUNCTION platform_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.id IS DISTINCT FROM OLD.id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'immutable row identity' USING ERRCODE = '23514';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        RAISE EXCEPTION 'version must advance exactly once' USING ERRCODE = '23514';
    END IF;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END $$;
-- Prevent changing any accepted identity/hash/pin in place, including upserts.
CREATE FUNCTION platform_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE field text;
BEGIN
    FOREACH field IN ARRAY TG_ARGV LOOP
        IF to_jsonb(NEW)->field IS DISTINCT FROM to_jsonb(OLD)->field THEN
            RAISE EXCEPTION 'accepted identity conflict' USING ERRCODE = '23505';
        END IF;
    END LOOP;
    RETURN NEW;
END $$;

CREATE TABLE instances (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    config_ref text NOT NULL,
    config_hash text NOT NULL CHECK (config_hash ~ '^[0-9a-f]{64}$'),
    registry_hash text NOT NULL CHECK (registry_hash ~ '^[0-9a-f]{64}$')
);

CREATE TRIGGER version_guard BEFORE UPDATE ON instances FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON instances FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('config_ref', 'config_hash', 'registry_hash');

CREATE TABLE sessions (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    instance_id text NOT NULL,
    bundle_ref text NOT NULL,
    bundle_hash text NOT NULL CHECK (bundle_hash ~ '^[0-9a-f]{64}$'),
    history_ref text,
    FOREIGN KEY (tenant_id, instance_id) REFERENCES instances(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON sessions FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON sessions FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('instance_id', 'bundle_ref', 'bundle_hash');

CREATE TABLE turns (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    session_id text NOT NULL,
    source text NOT NULL CHECK (source <> ''),
    source_event_key text NOT NULL CHECK (source_event_key <> ''),
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    input_ref text NOT NULL,
    UNIQUE (tenant_id, source, source_event_key),
    FOREIGN KEY (tenant_id, session_id) REFERENCES sessions(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON turns FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON turns FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('session_id', 'source', 'source_event_key', 'payload_hash', 'input_ref');

CREATE TABLE runs (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    turn_id text NOT NULL,
    status text NOT NULL DEFAULT 'accepted' CHECK (status IN ('accepted','preparing','running','waiting_action','waiting_confirmation','waiting_external','cancelling','cancelled','completed','failed')),
    claim_owner text,
    lease_until timestamptz,
    fencing_epoch bigint NOT NULL DEFAULT 0 CHECK (fencing_epoch >= 0),
    checkpoint jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(checkpoint) = 'object' AND octet_length(checkpoint::text) <= 16384),
    terminal_code text,
    CHECK ((claim_owner IS NULL) = (lease_until IS NULL)),
    CHECK ((status IN ('completed','failed','cancelled')) = (terminal_code IS NOT NULL)),
    FOREIGN KEY (tenant_id, turn_id) REFERENCES turns(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON runs FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON runs FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('turn_id');

CREATE UNIQUE INDEX runs_one_active_per_turn ON runs(tenant_id, turn_id) WHERE status NOT IN ('completed','failed','cancelled');

CREATE TABLE run_steps (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    run_id text NOT NULL,
    step_key text NOT NULL,
    action_ref text,
    state_ref text,
    UNIQUE (tenant_id, run_id, step_key),
    FOREIGN KEY (tenant_id, run_id) REFERENCES runs(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON run_steps FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON run_steps FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('run_id', 'step_key');

CREATE TABLE inbox (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id, id),
    source text NOT NULL,
    source_event_key text NOT NULL,
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    turn_id text NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','completed','deadletter','unknown')),
    available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deadline_at timestamptz NOT NULL DEFAULT (clock_timestamp() + interval '1 day'),
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    max_attempts integer NOT NULL DEFAULT 8 CHECK (max_attempts > 0),
    claim_owner text,
    claimed_until timestamptz,
    fencing_epoch bigint NOT NULL DEFAULT 0 CHECK (fencing_epoch >= 0),
    CHECK ((claim_owner IS NULL) = (claimed_until IS NULL)),
    CHECK (attempt_count <= max_attempts AND deadline_at >= created_at),
    UNIQUE (tenant_id, source, source_event_key),
    FOREIGN KEY (tenant_id, turn_id) REFERENCES turns(tenant_id, id) ON DELETE RESTRICT
);

CREATE TRIGGER version_guard BEFORE UPDATE ON inbox FOR EACH ROW EXECUTE FUNCTION platform_version_guard();

CREATE TRIGGER identity_guard BEFORE UPDATE ON inbox FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('source', 'source_event_key', 'payload_hash', 'turn_id', 'deadline_at', 'max_attempts');
