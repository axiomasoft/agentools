CREATE TABLE IF NOT EXISTS knowledge_releases (
    tenant text NOT NULL,
    corpus text NOT NULL,
    release_id text NOT NULL,
    material_id text NOT NULL,
    source_version text NOT NULL,
    source_hash text NOT NULL CHECK (source_hash ~ '^[a-f0-9]{64}$'),
    content_hash text NOT NULL CHECK (content_hash ~ '^[a-f0-9]{64}$'),
    payload text NOT NULL,
    PRIMARY KEY (tenant, corpus, release_id)
);
CREATE TABLE IF NOT EXISTS knowledge_invalidations (
    tenant text NOT NULL,
    material_id text NOT NULL,
    source_version text NOT NULL,
    reason text NOT NULL CHECK (length(reason) > 0),
    PRIMARY KEY (tenant, material_id, source_version)
);
CREATE TABLE IF NOT EXISTS knowledge_dependencies (
    tenant text NOT NULL,
    corpus text NOT NULL,
    release_id text NOT NULL,
    kind text NOT NULL CHECK (kind IN ('bundle', 'snapshot', 'cache')),
    dependent_ref text NOT NULL,
    PRIMARY KEY (tenant, kind, dependent_ref),
    FOREIGN KEY (tenant, corpus, release_id) REFERENCES knowledge_releases (tenant, corpus, release_id)
);
CREATE OR REPLACE FUNCTION knowledge_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'immutable knowledge record' USING ERRCODE = '23514';
END;
$$;
DO $$
DECLARE target text;
BEGIN
    FOREACH target IN ARRAY ARRAY['knowledge_releases','knowledge_invalidations','knowledge_dependencies'] LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = target::regclass AND tgname = 'knowledge_no_mutation') THEN
            EXECUTE format('CREATE TRIGGER knowledge_no_mutation BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION knowledge_immutable()', target);
        END IF;
    END LOOP;
END;
$$;
