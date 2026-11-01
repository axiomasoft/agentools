CREATE TABLE {{schema}}.profile_bundles (
    tenant_id text NOT NULL,
    id text NOT NULL,
    version bigint NOT NULL CHECK (version BETWEEN 1 AND 9007199254740991),
    content_hash text NOT NULL CHECK (content_hash ~ '^sha-256:[0-9a-f]{64}$'),
    manifest jsonb NOT NULL CHECK (jsonb_typeof(manifest)='object'),
    state text NOT NULL CHECK (state IN ('draft','validated','published','deprecated','retired')),
    revision bigint NOT NULL CHECK (revision BETWEEN 1 AND 9007199254740991),
    issues jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(issues)='array'),
    PRIMARY KEY (tenant_id,id,version),
    UNIQUE (tenant_id,id,version,content_hash),
    CHECK ((manifest->>'id'=id AND (manifest->>'version')::bigint=version AND manifest->>'content_hash'=content_hash) IS TRUE),
    CHECK ((manifest->'owner'=jsonb_build_object('kind','tenant','tenant_id',tenant_id)) IS TRUE)
);

CREATE FUNCTION {{schema}}.profile_bundle_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        IF NEW.state<>'draft' OR NEW.revision<>1 THEN RAISE EXCEPTION 'Publication starts in draft' USING ERRCODE='23514'; END IF;
        RETURN NEW;
    END IF;
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Profile history is retained' USING ERRCODE='23514'; END IF;
    IF (NEW.tenant_id,NEW.id,NEW.version) IS DISTINCT FROM (OLD.tenant_id,OLD.id,OLD.version)
        OR NEW.revision<>OLD.revision+1 THEN
        RAISE EXCEPTION 'Profile identity/revision conflict' USING ERRCODE='23514';
    END IF;
    IF OLD.state<>'draft' AND (NEW.manifest,NEW.content_hash) IS DISTINCT FROM (OLD.manifest,OLD.content_hash) THEN
        RAISE EXCEPTION 'Validated/published content is immutable' USING ERRCODE='23514';
    END IF;
    IF NOT ((OLD.state='draft' AND NEW.state IN ('draft','validated'))
        OR (OLD.state='validated' AND NEW.state='published')
        OR (OLD.state='published' AND NEW.state='deprecated')
        OR (OLD.state='deprecated' AND NEW.state='retired')) THEN
        RAISE EXCEPTION 'Illegal publication transition' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER profile_bundle_guard BEFORE INSERT OR UPDATE OR DELETE ON {{schema}}.profile_bundles
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.profile_bundle_guard();

CREATE TABLE {{schema}}.profile_assignments (
    tenant_id text NOT NULL,
    id text NOT NULL,
    revision bigint NOT NULL CHECK (revision BETWEEN 1 AND 9007199254740991),
    bundle_id text NOT NULL,
    bundle_version bigint NOT NULL,
    bundle_hash text NOT NULL,
    definition jsonb NOT NULL,
    PRIMARY KEY (tenant_id,id),
    FOREIGN KEY (tenant_id,bundle_id,bundle_version,bundle_hash) REFERENCES {{schema}}.profile_bundles(tenant_id,id,version,content_hash)
);

CREATE TABLE {{schema}}.profile_session_versions (
    tenant_id text NOT NULL,
    session_id text NOT NULL,
    revision bigint NOT NULL CHECK (revision BETWEEN 1 AND 9007199254740991),
    bundle_id text NOT NULL,
    bundle_version bigint NOT NULL,
    bundle_hash text NOT NULL,
    reason text NOT NULL CHECK (length(reason) BETWEEN 1 AND 1024),
    compatibility_ref text NOT NULL,
    actor_ref text NOT NULL,
    command_ref text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,session_id,revision),
    FOREIGN KEY (tenant_id,bundle_id,bundle_version,bundle_hash) REFERENCES {{schema}}.profile_bundles(tenant_id,id,version,content_hash)
);
CREATE TABLE {{schema}}.profile_session_heads (
    tenant_id text NOT NULL,
    session_id text NOT NULL,
    instance_id text NOT NULL,
    revision bigint NOT NULL,
    PRIMARY KEY (tenant_id,session_id),
    FOREIGN KEY (tenant_id,instance_id) REFERENCES {{schema}}.profile_assignments(tenant_id,id),
    FOREIGN KEY (tenant_id,session_id,revision) REFERENCES {{schema}}.profile_session_versions(tenant_id,session_id,revision) DEFERRABLE INITIALLY DEFERRED
);
CREATE TABLE {{schema}}.profile_commands (
    tenant_id text NOT NULL,
    id text NOT NULL,
    request_hash text NOT NULL,
    receipt jsonb NOT NULL,
    PRIMARY KEY (tenant_id,id)
);
CREATE FUNCTION {{schema}}.profile_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Immutable profile receipt/history' USING ERRCODE='23514';
END $$;
CREATE TRIGGER profile_history_guard BEFORE UPDATE OR DELETE ON {{schema}}.profile_session_versions
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.profile_append_only();
CREATE TRIGGER profile_command_guard BEFORE UPDATE OR DELETE ON {{schema}}.profile_commands
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.profile_append_only();

CREATE FUNCTION {{schema}}.profile_assignment_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Instance assignment identity is retained' USING ERRCODE='23514'; END IF;
    IF (NEW.tenant_id,NEW.id,NEW.definition) IS DISTINCT FROM (OLD.tenant_id,OLD.id,OLD.definition)
        OR NEW.revision<>OLD.revision+1 THEN
        RAISE EXCEPTION 'Immutable instance definition / assignment CAS' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER profile_assignment_guard BEFORE UPDATE OR DELETE ON {{schema}}.profile_assignments
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.profile_assignment_guard();
CREATE FUNCTION {{schema}}.profile_head_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Session pin identity is retained' USING ERRCODE='23514'; END IF;
    IF (NEW.tenant_id,NEW.session_id,NEW.instance_id) IS DISTINCT FROM (OLD.tenant_id,OLD.session_id,OLD.instance_id)
        OR NEW.revision<>OLD.revision+1 THEN
        RAISE EXCEPTION 'Session retarget requires next audited revision' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER profile_head_guard BEFORE UPDATE OR DELETE ON {{schema}}.profile_session_heads
    FOR EACH ROW EXECUTE FUNCTION {{schema}}.profile_head_guard();
