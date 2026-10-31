-- Shared identity, not a second journal. Preserve every existing proposal binding.
CREATE TABLE action_origins (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 0 CHECK (version = 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,id)
);
INSERT INTO action_origins(tenant_id,id) SELECT tenant_id,action_ref FROM proposal_map;
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON action_origins
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE FUNCTION platform_proposal_action_origin() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    INSERT INTO action_origins(tenant_id,id) VALUES (NEW.tenant_id,NEW.action_ref);
    RETURN NEW;
END $$;
CREATE TRIGGER action_origin AFTER INSERT ON proposal_map
    FOR EACH ROW EXECUTE FUNCTION platform_proposal_action_origin();
ALTER TABLE agentools_event_streams DROP CONSTRAINT agentools_event_streams_tenant_id_action_id_fkey;
ALTER TABLE agentools_event_streams ADD FOREIGN KEY (tenant_id,action_id)
    REFERENCES action_origins(tenant_id,id) ON DELETE RESTRICT;
ALTER TABLE effect_intents DROP CONSTRAINT effect_intents_tenant_id_action_ref_fkey;
ALTER TABLE effect_intents ADD FOREIGN KEY (tenant_id,action_ref)
    REFERENCES action_origins(tenant_id,id) ON DELETE RESTRICT;

CREATE TABLE action_submissions (
    tenant_id text NOT NULL CHECK (tenant_id <> ''),
    id text NOT NULL CHECK (id <> ''),
    version bigint NOT NULL DEFAULT 1 CHECK (version = 1),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    submission_key text NOT NULL CHECK (submission_key <> ''),
    origin_ref text NOT NULL CHECK (origin_ref <> ''),
    proposal_id text,
    actor_ref text NOT NULL CHECK (actor_ref <> ''),
    scope_hash text NOT NULL CHECK (scope_hash ~ '^sha-256:[0-9a-f]{64}$'),
    request_hash text NOT NULL CHECK (request_hash ~ '^sha-256:[0-9a-f]{64}$'),
    definition_ref jsonb NOT NULL CHECK (jsonb_typeof(definition_ref)='object'),
    input_ref text NOT NULL CHECK (input_ref <> ''),
    input_hash text NOT NULL CHECK (input_hash ~ '^sha-256:[0-9a-f]{64}$'),
    deadline_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id,id),
    UNIQUE (tenant_id,submission_key),
    UNIQUE (tenant_id,proposal_id),
    FOREIGN KEY (tenant_id,id) REFERENCES action_origins(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,proposal_id) REFERENCES proposal_map(tenant_id,id) ON DELETE RESTRICT
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON action_submissions
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE FUNCTION platform_action_origin_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM proposal_map WHERE tenant_id=NEW.tenant_id AND action_ref=NEW.id)
       AND NOT EXISTS (SELECT 1 FROM action_submissions WHERE tenant_id=NEW.tenant_id AND id=NEW.id) THEN
        RAISE EXCEPTION 'action requires a durable origin' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER origin_commit_guard AFTER INSERT ON action_origins
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION platform_action_origin_guard();
CREATE FUNCTION platform_submission_commit_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM agentools_events WHERE tenant_id=NEW.tenant_id AND action_id=NEW.id
        AND seq=1 AND kind='action_created')
       OR NOT EXISTS (SELECT 1 FROM action_current WHERE tenant_id=NEW.tenant_id AND id=NEW.id)
       OR (NEW.proposal_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM proposal_map
           WHERE tenant_id=NEW.tenant_id AND id=NEW.proposal_id AND action_ref=NEW.id
             AND payload_hash=substring(NEW.input_hash from 9))) THEN
        RAISE EXCEPTION 'submission requires committed dated action and matching proposal' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER submission_commit_guard AFTER INSERT ON action_submissions
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION platform_submission_commit_guard();
