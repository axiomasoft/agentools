-- Synthetic external ports, provisioned only inside a proven isolated test schema.
CREATE TABLE write_config (id text PRIMARY KEY, value text NOT NULL);
CREATE TABLE write_controls (tenant_id text NOT NULL,instance_id text NOT NULL,tool_allowed boolean NOT NULL DEFAULT true,
 emergency boolean NOT NULL DEFAULT false,PRIMARY KEY(tenant_id,instance_id));
CREATE TABLE write_payloads (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,ciphertext text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE write_admissions (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE write_provider (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,response_ref text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE write_messages (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,receipt_ref text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE write_signals (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE example_owner_permissions (tenant_id text NOT NULL,actor_ref text NOT NULL,allowed boolean NOT NULL,PRIMARY KEY(tenant_id,actor_ref));
CREATE TABLE example_owner_writes (tenant_id text NOT NULL,operation_key text NOT NULL,request_hash text NOT NULL,payload jsonb NOT NULL,PRIMARY KEY(tenant_id,operation_key));
