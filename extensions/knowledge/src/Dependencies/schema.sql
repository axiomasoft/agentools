CREATE TABLE IF NOT EXISTS dependency_nodes (
    tenant text NOT NULL, node_key text NOT NULL, kind text NOT NULL, ref text NOT NULL,
    version text NOT NULL, content_hash text NOT NULL CHECK(content_hash ~ '^[a-f0-9]{64}$'),
    parents_hash text NOT NULL CHECK(parents_hash ~ '^[a-f0-9]{64}$'),
    PRIMARY KEY(tenant,node_key), UNIQUE(tenant,kind,ref,version)
);
CREATE TABLE IF NOT EXISTS dependency_edges (
    tenant text NOT NULL, child text NOT NULL, parent text NOT NULL,
    PRIMARY KEY(tenant,child,parent), CHECK(child<>parent),
    FOREIGN KEY(tenant,child) REFERENCES dependency_nodes,
    FOREIGN KEY(tenant,parent) REFERENCES dependency_nodes
);
CREATE INDEX IF NOT EXISTS dependency_reverse_edges ON dependency_edges(tenant,parent,child);
CREATE TABLE IF NOT EXISTS dependency_tombstones (
    tenant text NOT NULL, event_id text NOT NULL, event_hash text NOT NULL,
    source text NOT NULL, reason text NOT NULL CHECK(reason IN
        ('source_corrected','source_deleted','source_revoked','consent_revoked')),
    PRIMARY KEY(tenant,event_id), FOREIGN KEY(tenant,source) REFERENCES dependency_nodes
);
CREATE TABLE IF NOT EXISTS dependency_runs (
    tenant text NOT NULL, run_ref text NOT NULL, content_hash text NOT NULL,
    PRIMARY KEY(tenant,run_ref)
);
CREATE TABLE IF NOT EXISTS dependency_run_nodes (
    tenant text NOT NULL, run_ref text NOT NULL, node_key text NOT NULL,
    PRIMARY KEY(tenant,run_ref,node_key),
    FOREIGN KEY(tenant,run_ref) REFERENCES dependency_runs,
    FOREIGN KEY(tenant,node_key) REFERENCES dependency_nodes
);
CREATE TABLE IF NOT EXISTS dependency_resources (
    tenant text NOT NULL, request_id text NOT NULL, node_key text NOT NULL,
    payload text NOT NULL, content_hash text NOT NULL,
    PRIMARY KEY(tenant,request_id), FOREIGN KEY(tenant,node_key) REFERENCES dependency_nodes
);
CREATE TABLE IF NOT EXISTS dependency_deletions (
    tenant text NOT NULL, request_id text NOT NULL,
    PRIMARY KEY(tenant,request_id), FOREIGN KEY(tenant,request_id) REFERENCES dependency_resources
);
CREATE TABLE IF NOT EXISTS dependency_delete_attempts (
    tenant text NOT NULL, request_id text NOT NULL, attempt text NOT NULL, request_hash text NOT NULL,
    PRIMARY KEY(tenant,request_id,attempt), FOREIGN KEY(tenant,request_id) REFERENCES dependency_deletions
);
CREATE TABLE IF NOT EXISTS dependency_delete_results (
    tenant text NOT NULL, request_id text NOT NULL, attempt text NOT NULL,
    status text NOT NULL CHECK(status IN ('succeeded','failed','unknown','unsupported','pending')),
    receipt_ref text, content_hash text NOT NULL,
    CHECK(status<>'succeeded' OR (receipt_ref IS NOT NULL AND length(receipt_ref)>0)),
    PRIMARY KEY(tenant,request_id,attempt),
    FOREIGN KEY(tenant,request_id,attempt) REFERENCES dependency_delete_attempts
);
CREATE OR REPLACE FUNCTION dependency_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'immutable dependency record' USING ERRCODE = '23514';
END;
$$;
DO $$
DECLARE target text;
BEGIN
    FOREACH target IN ARRAY ARRAY['dependency_nodes','dependency_edges','dependency_tombstones',
        'dependency_runs','dependency_run_nodes','dependency_resources','dependency_deletions',
        'dependency_delete_attempts','dependency_delete_results'] LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid=target::regclass AND tgname='dependency_no_mutation') THEN
            EXECUTE format('CREATE TRIGGER dependency_no_mutation BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION dependency_immutable()',target);
        END IF;
    END LOOP;
END;
$$;
