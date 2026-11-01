CREATE TABLE IF NOT EXISTS {{schema_versions}} (revision integer PRIMARY KEY, source_hash text NOT NULL);
CREATE TABLE IF NOT EXISTS {{instances}} (
 tenant_id text NOT NULL, id text NOT NULL, revision bigint NOT NULL CHECK (revision > 0), state jsonb NOT NULL,
 PRIMARY KEY (tenant_id,id)
);
CREATE TABLE IF NOT EXISTS {{mutations}} (
 tenant_id text NOT NULL, id text NOT NULL, request_hash text NOT NULL, receipt jsonb NOT NULL,
 PRIMARY KEY (tenant_id,id)
);
CREATE TABLE IF NOT EXISTS {{pins}} (
 tenant_id text NOT NULL, session_id text NOT NULL, instance_id text NOT NULL, state jsonb NOT NULL,
 PRIMARY KEY (tenant_id,session_id), FOREIGN KEY (tenant_id,instance_id) REFERENCES {{instances}}(tenant_id,id)
);
