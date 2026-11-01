-- Derived bytes use the existing encrypted payload owner and its retention,
-- holds, unresolved-work guards and physical deletion receipts. No new transcript
-- journal, delivery loop or payload column is introduced here.
CREATE TABLE derived_payloads (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    session_id text NOT NULL,
    source_ref text NOT NULL,
    source_revision bigint NOT NULL CHECK (source_revision>0),
    dependency text NOT NULL CHECK (dependency IN ('context','summary','memory-candidates','history-search','provider-cache','context-cache','temporary-payloads')),
    payload_id text NOT NULL,
    PRIMARY KEY (tenant_id,id),
    UNIQUE (tenant_id,session_id,source_ref,dependency,payload_id),
    FOREIGN KEY (tenant_id,session_id) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,source_ref) REFERENCES source_fences(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,payload_id) REFERENCES payload_refs(tenant_id,id) ON DELETE RESTRICT
);
CREATE INDEX derived_payloads_source ON derived_payloads(tenant_id,session_id,source_ref,dependency,id);
CREATE INDEX derived_payloads_fence ON derived_payloads(tenant_id,source_ref);
CREATE INDEX derived_payloads_payload ON derived_payloads(tenant_id,payload_id);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON derived_payloads
    FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE FUNCTION platform_derived_link_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE source record; payload record;
BEGIN
    SELECT closed,watermark INTO source FROM source_fences WHERE tenant_id=NEW.tenant_id AND id=NEW.source_ref FOR UPDATE;
    IF NOT FOUND OR source.closed OR source.watermark<>NEW.source_revision THEN
        RAISE EXCEPTION 'derived source closed or stale' USING ERRCODE='23514';
    END IF;
    SELECT session_id,purge_pending_at,purged_at,retention_until INTO payload FROM payload_refs
        WHERE tenant_id=NEW.tenant_id AND id=NEW.payload_id FOR UPDATE;
    IF NOT FOUND OR payload.session_id IS DISTINCT FROM NEW.session_id OR payload.purge_pending_at IS NOT NULL
        OR payload.purged_at IS NOT NULL OR payload.retention_until<=clock_timestamp() THEN
        RAISE EXCEPTION 'derived payload unavailable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER source_guard BEFORE INSERT ON derived_payloads FOR EACH ROW EXECUTE FUNCTION platform_derived_link_guard();

CREATE TABLE derived_cleanup_receipts (
    tenant_id text NOT NULL,
    id text NOT NULL CHECK (length(id) BETWEEN 1 AND 128),
    version bigint NOT NULL DEFAULT 0 CHECK (version>=0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    session_id text NOT NULL,
    source_ref text NOT NULL,
    dependency text NOT NULL,
    watermark bigint NOT NULL CHECK (watermark>0),
    payload_hash text NOT NULL CHECK (payload_hash ~ '^[0-9a-f]{64}$'),
    erased_count bigint CHECK (erased_count>0),
    completed_at timestamptz,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,session_id) REFERENCES sessions(tenant_id,id) ON DELETE RESTRICT,
    FOREIGN KEY (tenant_id,source_ref) REFERENCES source_fences(tenant_id,id) ON DELETE RESTRICT,
    CHECK ((erased_count IS NULL)=(completed_at IS NULL))
);
CREATE INDEX derived_cleanup_receipts_session ON derived_cleanup_receipts(tenant_id,session_id);
CREATE INDEX derived_cleanup_receipts_source ON derived_cleanup_receipts(tenant_id,source_ref);
CREATE TRIGGER version_guard BEFORE UPDATE ON derived_cleanup_receipts FOR EACH ROW EXECUTE FUNCTION platform_version_guard();
CREATE TRIGGER identity_guard BEFORE UPDATE ON derived_cleanup_receipts FOR EACH ROW
    EXECUTE FUNCTION platform_identity_guard('session_id','source_ref','dependency','watermark','payload_hash');
CREATE FUNCTION platform_derived_completed_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE source record; total bigint; remaining bigint;
BEGIN
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'preserve derived cleanup evidence' USING ERRCODE='23514';
    END IF;
    IF TG_OP='UPDATE' AND (OLD.completed_at IS NOT NULL OR NEW.completed_at IS NULL) THEN
        RAISE EXCEPTION 'cleanup completion is monotonic' USING ERRCODE='23514';
    END IF;
    SELECT closed,watermark INTO source FROM source_fences WHERE tenant_id=NEW.tenant_id AND id=NEW.source_ref FOR UPDATE;
    IF NOT FOUND OR NOT source.closed OR source.watermark<NEW.watermark THEN
        RAISE EXCEPTION 'source cleanup not fenced' USING ERRCODE='23514';
    END IF;
    IF NEW.completed_at IS NULL THEN RETURN NEW; END IF;
    SELECT count(*),count(*) FILTER (WHERE p.purged_at IS NULL) INTO total,remaining
        FROM derived_payloads d JOIN payload_refs p ON p.tenant_id=d.tenant_id AND p.id=d.payload_id
        WHERE d.tenant_id=NEW.tenant_id AND d.session_id=NEW.session_id AND d.source_ref=NEW.source_ref AND d.dependency=NEW.dependency;
    IF total=0 OR total<>NEW.erased_count OR remaining<>0 THEN
        RAISE EXCEPTION 'physical cleanup incomplete or no registered coverage' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER completed_guard BEFORE INSERT OR UPDATE OR DELETE ON derived_cleanup_receipts FOR EACH ROW EXECUTE FUNCTION platform_derived_completed_guard();
