CREATE TABLE {{schema}}.model_cache_warm (
 tenant_id text NOT NULL,id text NOT NULL,request_hash text NOT NULL,key_id text NOT NULL,profile_hash text NOT NULL,
 estimate bigint NOT NULL CHECK(estimate>=0),created_at timestamptz NOT NULL,expires_at timestamptz NOT NULL,
 intent jsonb NOT NULL,status text NOT NULL CHECK(status IN ('pending','sent','known','unknown','accounted')),
 reservation_ref text,usage jsonb NOT NULL,usage_hash text NOT NULL,descriptor jsonb,
 version bigint NOT NULL DEFAULT 0 CHECK(version>=0),updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,id),CHECK(tenant_id<>'' AND id<>''),CHECK(expires_at>created_at)
);
CREATE TABLE {{schema}}.model_cache_fence (
 tenant_id text NOT NULL,id text NOT NULL,reason text NOT NULL,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,id)
);
CREATE TABLE {{schema}}.model_cache_entry (
 tenant_id text NOT NULL,id text NOT NULL,document jsonb NOT NULL,state text NOT NULL CHECK(state IN ('active','invalidated')),
 created_at timestamptz NOT NULL,expires_at timestamptz NOT NULL,last_used timestamptz NOT NULL,warm_id text,invalidated_at timestamptz,
 version bigint NOT NULL DEFAULT 0 CHECK(version>=0),PRIMARY KEY(tenant_id,id),
 FOREIGN KEY(tenant_id,warm_id) REFERENCES {{schema}}.model_cache_warm(tenant_id,id),CHECK(expires_at>created_at)
);
CREATE TABLE {{schema}}.model_cache_delete (
 tenant_id text NOT NULL,id text NOT NULL,key_id text NOT NULL,descriptor jsonb NOT NULL,
 status text NOT NULL CHECK(status IN ('pending','unknown','unsupported','deleted','expired','local_only')),
 receipt_ref text,expires_at timestamptz NOT NULL,next_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 version bigint NOT NULL DEFAULT 0 CHECK(version>=0),created_at timestamptz NOT NULL DEFAULT clock_timestamp(),updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(tenant_id,id),CHECK((status='deleted')=(receipt_ref IS NOT NULL))
);
CREATE INDEX model_cache_warm_pending ON {{schema}}.model_cache_warm(tenant_id,updated_at) WHERE status<>'accounted';
CREATE INDEX model_cache_delete_pending ON {{schema}}.model_cache_delete(tenant_id,next_at) WHERE status IN ('pending','unknown','unsupported');
