-- A hold is durable owner evidence. Release never deletes its identity or permits
-- that identity to become active again. All hold/purge races lock the parent.
CREATE TABLE payload_holds (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    payload_id text NOT NULL,
    authority_ref text NOT NULL CHECK (btrim(authority_ref) <> ''),
    released_by text,
    released_at timestamptz,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,payload_id) REFERENCES payload_refs(tenant_id,id) ON DELETE RESTRICT,
    CHECK ((released_at IS NULL) = (released_by IS NULL))
);
CREATE INDEX payload_holds_parent ON payload_holds(tenant_id,payload_id);
CREATE TRIGGER version_guard BEFORE UPDATE ON payload_holds FOR EACH ROW EXECUTE FUNCTION platform_version_guard();
CREATE TRIGGER identity_guard BEFORE UPDATE ON payload_holds FOR EACH ROW EXECUTE FUNCTION platform_identity_guard('payload_id','authority_ref');

CREATE FUNCTION platform_payload_hold_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE item record;
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'preserve hold receipt' USING ERRCODE='23514'; END IF;
    SELECT purge_pending_at,purged_at INTO item FROM payload_refs
        WHERE tenant_id=NEW.tenant_id AND id=NEW.payload_id FOR UPDATE;
    IF TG_OP='INSERT' THEN
        IF NOT FOUND OR item.purge_pending_at IS NOT NULL OR item.purged_at IS NOT NULL
           OR NEW.released_at IS NOT NULL THEN
            RAISE EXCEPTION 'hold cannot resurrect purging payload' USING ERRCODE='23514';
        END IF;
    ELSIF OLD.released_at IS NOT NULL OR NEW.released_at IS NULL OR btrim(NEW.released_by)='' THEN
        RAISE EXCEPTION 'hold release is monotonic' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER hold_state BEFORE INSERT OR UPDATE OR DELETE ON payload_holds
    FOR EACH ROW EXECUTE FUNCTION platform_payload_hold_guard();

CREATE FUNCTION platform_payload_retention_hold_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF (NEW.purge_pending_at IS DISTINCT FROM OLD.purge_pending_at OR NEW.purged_at IS DISTINCT FROM OLD.purged_at)
       AND EXISTS (SELECT 1 FROM payload_holds WHERE tenant_id=OLD.tenant_id AND payload_id=OLD.id AND released_at IS NULL) THEN
        RAISE EXCEPTION 'active payload hold' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER retention_hold BEFORE UPDATE OF purge_pending_at,purged_at ON payload_refs
    FOR EACH ROW EXECUTE FUNCTION platform_payload_retention_hold_guard();

-- PLAN2 now admits direct Action submissions too. An Action acceptance/terminal
-- label alone is not a qualified payload-release receipt; retain these links
-- until that owner supplies its exact resolution contract. Keep all v5 guards.
CREATE OR REPLACE FUNCTION platform_payload_unresolved(tenant text, payload text) RETURNS boolean LANGUAGE sql STABLE SET search_path FROM CURRENT AS $$
    SELECT EXISTS (SELECT 1 FROM outbox WHERE tenant_id=tenant AND payload_ref_id=payload AND status<>'dispatched')
    OR EXISTS (SELECT 1 FROM delivery_intents d JOIN responses r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
        JOIN payload_refs p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
        WHERE r.tenant_id=tenant AND r.payload_ref_id=payload AND (
            d.payload_hash<>r.payload_hash OR d.payload_hash<>p.content_hash
            OR platform_delivery_resolved(d.tenant_id,d.id,d.outcome,d.receipt_ref,d.payload_hash) IS NOT TRUE))
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
        WHERE x.tenant_id=tenant AND x.result_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM action_submissions WHERE tenant_id=tenant AND input_ref=payload);
$$;

CREATE TRIGGER payload_link BEFORE INSERT ON action_submissions
    FOR EACH ROW EXECUTE FUNCTION platform_payload_link_guard('input_ref');
