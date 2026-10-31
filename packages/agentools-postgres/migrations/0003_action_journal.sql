-- The dated EventStore remains the only Action journal. IDs below are storage
-- aliases, not a new wire format; ts_text preserves the exact hashed timestamp.
CREATE TABLE agentools_event_streams (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    action_id text NOT NULL CHECK (action_id <> ''),
    id text GENERATED ALWAYS AS (action_id) STORED NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    current_seq bigint NOT NULL DEFAULT 0 CHECK (current_seq >= 0),
    PRIMARY KEY (tenant_id,id),
    UNIQUE (tenant_id,action_id),
    FOREIGN KEY (tenant_id,action_id) REFERENCES proposal_map(tenant_id,action_ref) ON DELETE RESTRICT
);
CREATE FUNCTION platform_stream_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.action_id IS DISTINCT FROM OLD.action_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.current_seq <= OLD.current_seq THEN
        RAISE EXCEPTION 'immutable stream or non-increasing sequence' USING ERRCODE = '23514';
    END IF;
    NEW.version := OLD.version+1;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER stream_guard BEFORE UPDATE ON agentools_event_streams FOR EACH ROW EXECUTE FUNCTION platform_stream_guard();

CREATE TABLE agentools_events (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    event_id text NOT NULL CHECK (event_id <> ''),
    id text GENERATED ALWAYS AS (event_id) STORED NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    action_id text NOT NULL,
    seq bigint NOT NULL CHECK (seq >= 1),
    expected_prev_seq bigint NOT NULL CHECK (expected_prev_seq=seq-1),
    kind text NOT NULL,
    event_version integer NOT NULL CHECK (event_version >= 1),
    spec_rev text NOT NULL,
    actor_type text NOT NULL,
    actor_id text NOT NULL,
    ts timestamptz NOT NULL,
    ts_text text NOT NULL,
    payload_json text NOT NULL CHECK ((jsonb_typeof(payload_json::jsonb) = 'object' OR payload_json::jsonb='[]'::jsonb) AND octet_length(payload_json) <= 16384),
    epoch bigint CHECK (epoch >= 1),
    prev_hash text CHECK (prev_hash ~ '^sha-256:[0-9a-f]{64}$'),
    content_hash text NOT NULL CHECK (content_hash ~ '^sha-256:[0-9a-f]{64}$'),
    global_position bigint GENERATED ALWAYS AS IDENTITY,
    PRIMARY KEY (tenant_id,id),
    UNIQUE (tenant_id,event_id),
    UNIQUE (tenant_id,action_id,seq),
    UNIQUE (global_position),
    FOREIGN KEY (tenant_id,action_id) REFERENCES agentools_event_streams(tenant_id,action_id) ON DELETE RESTRICT
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON agentools_events FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();

CREATE TABLE action_current (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    state jsonb NOT NULL CHECK (jsonb_typeof(state)='object'),
    digest text NOT NULL CHECK (digest ~ '^sha-256:[0-9a-f]{64}$'),
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,id) REFERENCES agentools_event_streams(tenant_id,action_id) ON DELETE RESTRICT
);
CREATE FUNCTION platform_projection_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF TG_OP='UPDATE' THEN
        IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.id IS DISTINCT FROM OLD.id
           OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.version <= OLD.version THEN
            RAISE EXCEPTION 'projection needs a new journal version' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM agentools_event_streams s WHERE s.tenant_id=NEW.tenant_id
        AND s.action_id=NEW.id AND s.current_seq=NEW.version)
       OR (NEW.state->>'seq')::bigint IS DISTINCT FROM NEW.version THEN
        RAISE EXCEPTION 'projection must match journal head' USING ERRCODE = '23514';
    END IF;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER projection_guard BEFORE INSERT OR UPDATE ON action_current FOR EACH ROW EXECUTE FUNCTION platform_projection_guard();

CREATE TABLE action_projection_rebuilds (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    action_id text NOT NULL,
    state jsonb NOT NULL CHECK (jsonb_typeof(state)='object'),
    digest text NOT NULL CHECK (digest ~ '^sha-256:[0-9a-f]{64}$'),
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,action_id) REFERENCES agentools_event_streams(tenant_id,action_id) ON DELETE RESTRICT
);
CREATE INDEX action_projection_rebuilds_parent ON action_projection_rebuilds(tenant_id,action_id);
ALTER TABLE effect_intents ADD COLUMN request_context jsonb CHECK
    (jsonb_typeof(request_context)='object' AND octet_length(request_context::text) <= 16384);
ALTER TABLE effect_intents ADD COLUMN claim_epoch bigint CHECK (claim_epoch >= 1);
ALTER TABLE effect_intents ADD CONSTRAINT effect_context_pair CHECK ((request_context IS NULL)=(claim_epoch IS NULL));
CREATE TRIGGER request_identity BEFORE UPDATE ON effect_intents FOR EACH ROW
    EXECUTE FUNCTION platform_identity_guard('request_context','claim_epoch');

-- A direct journal append cannot commit a partial platform transaction. The
-- repository additionally validates the canonical reducer/digest before writing.
CREATE FUNCTION platform_action_commit_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE head bigint; last_event text;
BEGIN
    SELECT current_seq INTO head FROM agentools_event_streams WHERE tenant_id=NEW.tenant_id AND action_id=NEW.action_id;
    SELECT event_id INTO last_event FROM agentools_events WHERE tenant_id=NEW.tenant_id AND action_id=NEW.action_id AND seq=head;
    IF NOT EXISTS (SELECT 1 FROM action_current WHERE tenant_id=NEW.tenant_id AND id=NEW.action_id AND version=head)
       OR NOT EXISTS (SELECT 1 FROM outbox WHERE tenant_id=NEW.tenant_id AND event_key='action.event:' || last_event) THEN
        RAISE EXCEPTION 'journal projection outbox must commit together' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM agentools_events e WHERE e.tenant_id=NEW.tenant_id AND e.action_id=NEW.action_id
        AND e.kind='effect_requested' AND NOT EXISTS (SELECT 1 FROM effect_intents i
            WHERE i.tenant_id=e.tenant_id AND i.action_ref=e.action_id AND i.effect_key=e.payload_json::jsonb->>'effect_id'
            AND i.request_hash=e.payload_json::jsonb->>'request_hash' AND i.request_ref=e.payload_json::jsonb->>'request_ref'
            AND i.owner_operation_key=e.payload_json::jsonb->>'idempotency_key' AND i.claim_epoch=e.epoch
            AND i.request_context IS NOT NULL)) THEN
        RAISE EXCEPTION 'committed immutable effect intent required' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER action_commit_guard AFTER INSERT OR UPDATE ON agentools_event_streams
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION platform_action_commit_guard();
