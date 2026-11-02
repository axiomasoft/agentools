CREATE TABLE IF NOT EXISTS knowledge_demo_index (
    tenant text NOT NULL,corpus text NOT NULL,release_id text NOT NULL,release_hash text NOT NULL,
    artifact text NOT NULL,start_byte integer NOT NULL,end_byte integer NOT NULL,terms tsvector NOT NULL,
    content_hash text NOT NULL,PRIMARY KEY(tenant,corpus,release_id,artifact)
);
CREATE INDEX IF NOT EXISTS knowledge_demo_terms ON knowledge_demo_index USING gin(terms);
CREATE TABLE IF NOT EXISTS knowledge_demo_calls (
    tenant text NOT NULL,invocation_id text NOT NULL,request_hash text NOT NULL,response_json text NOT NULL,
    PRIMARY KEY(tenant,invocation_id)
);
