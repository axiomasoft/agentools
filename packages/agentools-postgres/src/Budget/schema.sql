CREATE TABLE {{schema}}.accounting_reservation (
    tenant_id text NOT NULL, id text NOT NULL, request_hash text NOT NULL, request jsonb NOT NULL,
    reservation_ref text, admission jsonb,
    status text NOT NULL CHECK (status IN ('pending','active','settling','settled','releasing','released','unknown')),
    exposure text NOT NULL DEFAULT 'unknown' CHECK (exposure IN ('not_sent','known','unknown')),
    not_sent_evidence text, version bigint NOT NULL DEFAULT 0 CHECK (version >= 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,reservation_ref),
    CHECK (tenant_id <> '' AND id <> ''), CHECK ((exposure='not_sent')=(not_sent_evidence IS NOT NULL))
);
CREATE TABLE {{schema}}.accounting_operation (
    tenant_id text NOT NULL, id text NOT NULL, admission_key text NOT NULL,
    kind text NOT NULL CHECK (kind IN ('admit','settle','release','renew')),
    payload_hash text NOT NULL, payload jsonb NOT NULL,
    state text NOT NULL CHECK (state IN ('inflight','unknown','confirmed','not_applied','manual_resolution_required')),
    epoch bigint NOT NULL DEFAULT 1 CHECK (epoch > 0), attempts integer NOT NULL DEFAULT 1 CHECK (attempts > 0),
    lookups integer NOT NULL DEFAULT 0 CHECK (lookups >= 0),
    lease_until timestamptz NOT NULL, proof_ref text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,admission_key) REFERENCES {{schema}}.accounting_reservation(tenant_id,id)
);
CREATE UNIQUE INDEX accounting_one_pending_operation ON {{schema}}.accounting_operation(tenant_id,admission_key)
    WHERE state IN ('inflight','unknown','manual_resolution_required');
