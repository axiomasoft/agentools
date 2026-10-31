-- Payload bytes stay in the encrypted owner store. These are technical handles,
-- per-session authority bindings, dependent materializations and minimal audit.
ALTER TABLE payload_refs ADD COLUMN session_id text;
ALTER TABLE payload_refs ADD COLUMN binding_hash text CHECK (binding_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE payload_refs ADD COLUMN purge_pending_at timestamptz;
ALTER TABLE payload_refs ADD COLUMN purge_token text CHECK (purge_token ~ '^[0-9a-f]{64}$');
ALTER TABLE payload_refs ADD CONSTRAINT payload_session_fk FOREIGN KEY (tenant_id,session_id) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT;
ALTER TABLE payload_refs ADD CONSTRAINT payload_binding_pair CHECK ((session_id IS NULL)=(binding_hash IS NULL));
ALTER TABLE payload_refs ADD CONSTRAINT payload_pending_pair CHECK ((purge_pending_at IS NULL)=(purge_token IS NULL));
CREATE INDEX payload_refs_session ON payload_refs(tenant_id,session_id);
CREATE TRIGGER binding_identity BEFORE UPDATE ON payload_refs FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('session_id','binding_hash');

CREATE TABLE payload_materializations (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    payload_id text NOT NULL,
    binding_hash text NOT NULL CHECK (binding_hash ~ '^[0-9a-f]{64}$'),
    encrypted_ref text,
    encryption_key_ref text,
    purged_at timestamptz,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,payload_id) REFERENCES payload_refs(tenant_id,id) ON DELETE RESTRICT,
    CHECK ((purged_at IS NULL AND encrypted_ref IS NOT NULL AND encryption_key_ref IS NOT NULL)
        OR (purged_at IS NOT NULL AND encrypted_ref IS NULL AND encryption_key_ref IS NULL))
);
CREATE INDEX payload_materializations_parent ON payload_materializations(tenant_id,payload_id);
CREATE TRIGGER version_guard BEFORE UPDATE ON payload_materializations FOR EACH ROW EXECUTE FUNCTION platform_version_guard();
CREATE TRIGGER identity_guard BEFORE UPDATE ON payload_materializations FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('payload_id','binding_hash');

CREATE TABLE payload_access_audit (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    payload_id text NOT NULL,
    actor_ref text NOT NULL,
    purpose text NOT NULL,
    operation text NOT NULL CHECK (operation IN ('register','status','materialize','dereference','purge')),
    outcome text NOT NULL CHECK (outcome IN ('authorized','released','denied','unknown','purged','integrity_conflict')),
    decision_ref text,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,payload_id) REFERENCES payload_refs(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX payload_access_audit_parent ON payload_access_audit(tenant_id,payload_id);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON payload_access_audit FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();

CREATE FUNCTION platform_payload_unresolved(tenant text, payload text) RETURNS boolean LANGUAGE sql STABLE SET search_path FROM CURRENT AS $$
    SELECT EXISTS (SELECT 1 FROM outbox WHERE tenant_id=tenant AND payload_ref_id=payload AND status<>'dispatched')
    OR EXISTS (SELECT 1 FROM delivery_intents d JOIN responses r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
        WHERE r.tenant_id=tenant AND r.payload_ref_id=payload AND d.outcome IN ('pending','unknown'))
    OR EXISTS (SELECT 1 FROM effect_intents WHERE tenant_id=tenant AND request_ref=payload AND outcome IN ('pending','unknown'))
    OR EXISTS (SELECT 1 FROM invocation_intents i LEFT JOIN invocation_results x ON x.tenant_id=i.tenant_id AND x.invocation_id=i.id
        WHERE i.tenant_id=tenant AND i.request_ref=payload AND (x.outcome IS NULL OR x.outcome='unknown'))
    OR EXISTS (SELECT 1 FROM turns t JOIN runs r ON r.tenant_id=t.tenant_id AND r.turn_id=t.id
        WHERE t.tenant_id=tenant AND t.input_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM run_steps s JOIN runs r ON r.tenant_id=s.tenant_id AND r.id=s.run_id
        WHERE s.tenant_id=tenant AND s.state_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM proposal_map p JOIN runs r ON r.tenant_id=p.tenant_id AND r.id=p.run_id
        WHERE p.tenant_id=tenant AND p.proposal_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM invocation_results x JOIN invocation_intents i ON i.tenant_id=x.tenant_id AND i.id=x.invocation_id
        JOIN runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id
        WHERE x.tenant_id=tenant AND x.result_ref=payload AND r.status NOT IN ('completed','failed','cancelled'));
$$;

CREATE OR REPLACE FUNCTION platform_payload_purge_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'preserve payload tombstone' USING ERRCODE='23514';
    END IF;
    IF OLD.purged_at IS NOT NULL OR OLD.retention_until>clock_timestamp()
       OR OLD.purge_pending_at IS NULL OR platform_payload_unresolved(OLD.tenant_id,OLD.id)
       OR NEW.encrypted_ref IS NOT NULL OR NEW.encryption_key_ref IS NOT NULL OR NEW.purged_at IS NULL THEN
        RAISE EXCEPTION 'payload retained or unresolved' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE FUNCTION platform_payload_state_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF OLD.purge_pending_at IS NOT NULL THEN
        IF NEW.purge_pending_at IS DISTINCT FROM OLD.purge_pending_at OR NEW.purge_token IS DISTINCT FROM OLD.purge_token THEN
            RAISE EXCEPTION 'immutable purge identity' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.purge_pending_at IS NOT NULL THEN
        IF OLD.purged_at IS NOT NULL OR OLD.retention_until>clock_timestamp() OR platform_payload_unresolved(OLD.tenant_id,OLD.id) THEN
            RAISE EXCEPTION 'payload retained or unresolved' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER purge_state BEFORE UPDATE ON payload_refs FOR EACH ROW EXECUTE FUNCTION platform_payload_state_guard();

-- A new unresolved reference must serialize with the purge reservation. Historical
-- refs remain as tombstone provenance; known outcome resolution stays possible.
CREATE FUNCTION platform_payload_link_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE ref text; item record;
BEGIN
    ref := to_jsonb(NEW)->>TG_ARGV[0];
    IF ref IS NULL THEN RETURN NEW; END IF;
    IF TG_OP='UPDATE' AND TG_NARGS>1 AND (to_jsonb(NEW)->>TG_ARGV[1]) IN ('success','failed','dispatched') THEN RETURN NEW; END IF;
    SELECT purge_pending_at,purged_at INTO item FROM payload_refs WHERE tenant_id=NEW.tenant_id AND id=ref FOR UPDATE;
    IF FOUND AND (item.purge_pending_at IS NOT NULL OR item.purged_at IS NOT NULL) THEN
        RAISE EXCEPTION 'payload purge prevents new materialization' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF payload_ref_id,status ON outbox FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('payload_ref_id','status');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF payload_ref_id ON responses FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('payload_ref_id');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF request_ref,outcome ON effect_intents FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('request_ref','outcome');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF request_ref ON invocation_intents FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('request_ref');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF result_ref ON invocation_results FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('result_ref');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF input_ref ON turns FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('input_ref');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF state_ref ON run_steps FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('state_ref');
CREATE TRIGGER payload_link BEFORE INSERT OR UPDATE OF proposal_ref ON proposal_map FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('proposal_ref');
CREATE TRIGGER payload_link BEFORE INSERT ON payload_materializations FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('payload_id');

CREATE FUNCTION platform_dependent_payload_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE ref text; item record;
BEGIN
    IF TG_TABLE_NAME='delivery_intents' THEN
        IF NEW.outcome IN ('success','failed') THEN RETURN NEW; END IF;
        SELECT payload_ref_id INTO ref FROM responses WHERE tenant_id=NEW.tenant_id AND id=NEW.response_id;
    ELSE
        IF NEW.status IN ('completed','failed','cancelled') THEN RETURN NEW; END IF;
        SELECT input_ref INTO ref FROM turns WHERE tenant_id=NEW.tenant_id AND id=NEW.turn_id;
    END IF;
    SELECT purge_pending_at,purged_at INTO item FROM payload_refs WHERE tenant_id=NEW.tenant_id AND id=ref FOR UPDATE;
    IF FOUND AND (item.purge_pending_at IS NOT NULL OR item.purged_at IS NOT NULL) THEN
        RAISE EXCEPTION 'purging payload cannot start dependent work' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payload_dependency BEFORE INSERT OR UPDATE OF outcome,response_id ON delivery_intents FOR EACH ROW EXECUTE FUNCTION platform_dependent_payload_guard();
CREATE TRIGGER payload_dependency BEFORE INSERT OR UPDATE OF status,turn_id ON runs FOR EACH ROW EXECUTE FUNCTION platform_dependent_payload_guard();

CREATE FUNCTION platform_materialization_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'preserve materialization tombstone' USING ERRCODE='23514'; END IF;
    IF OLD.purged_at IS NOT NULL OR NEW.encrypted_ref IS NOT NULL OR NEW.encryption_key_ref IS NOT NULL OR NEW.purged_at IS NULL
       OR NOT EXISTS (SELECT 1 FROM payload_refs WHERE tenant_id=OLD.tenant_id AND id=OLD.payload_id AND purge_pending_at IS NOT NULL) THEN
        RAISE EXCEPTION 'materialization only purged with parent' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER purge_guard BEFORE UPDATE OF encrypted_ref,encryption_key_ref,purged_at OR DELETE ON payload_materializations FOR EACH ROW EXECUTE FUNCTION platform_materialization_guard();
