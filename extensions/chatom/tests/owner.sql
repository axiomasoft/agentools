-- Isolated scripted destination owns these tables. Production bridge never queries them.
CREATE TABLE fixture_membership (
 tenant_id text NOT NULL, destination text NOT NULL, allowed boolean NOT NULL DEFAULT true,
 visible boolean NOT NULL DEFAULT true, held boolean NOT NULL DEFAULT false, relay text NOT NULL DEFAULT 'platform',
 PRIMARY KEY (tenant_id,destination)
);
CREATE TABLE fixture_messages (
 tenant_id text NOT NULL, destination text NOT NULL, delivery_key text NOT NULL,
 payload_hash text NOT NULL, message_ref text NOT NULL, body text NOT NULL,
 PRIMARY KEY (tenant_id,destination,delivery_key)
);
