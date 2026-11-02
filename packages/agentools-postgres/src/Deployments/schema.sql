CREATE TABLE {{schema}}.deployment_catalog (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    admin text NOT NULL DEFAULT 'disabled' CHECK (admin IN ('enabled','draining','disabled','quarantined','retired','incident_blocked')),
    health text NOT NULL DEFAULT 'unknown' CHECK (health IN ('healthy','degraded','unavailable','unknown')),
    state_version bigint NOT NULL DEFAULT 1 CHECK (state_version > 0),
    PRIMARY KEY (tenant_id,id)
);
CREATE TABLE {{schema}}.deployment_qualifications (
    tenant_id text NOT NULL, id text NOT NULL, deployment_id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,deployment_id) REFERENCES {{schema}}.deployment_catalog(tenant_id,id)
);
CREATE TABLE {{schema}}.deployment_emergencies (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    active boolean NOT NULL DEFAULT true, state_version bigint NOT NULL DEFAULT 1 CHECK (state_version > 0),
    PRIMARY KEY (tenant_id,id)
);
CREATE TABLE {{schema}}.deployment_traces (
    tenant_id text NOT NULL, id text NOT NULL, content_hash text NOT NULL, document_json text NOT NULL,
    PRIMARY KEY (tenant_id,id)
);
