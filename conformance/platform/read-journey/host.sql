-- Synthetic external owners; only provisioned inside the verified isolated database schema.
CREATE TABLE demo_config (id text PRIMARY KEY, key_value text NOT NULL);
CREATE TABLE demo_permissions (tenant_id text PRIMARY KEY, allowed boolean NOT NULL DEFAULT true,
 tool_allowed boolean NOT NULL DEFAULT true, context_allowed boolean NOT NULL DEFAULT true,
 disclosure_allowed boolean NOT NULL DEFAULT true, delivery_allowed boolean NOT NULL DEFAULT true,
 emergency boolean NOT NULL DEFAULT false, policy_revision text NOT NULL DEFAULT 'host-policy-v1', budget_state text NOT NULL DEFAULT 'admitted');
CREATE TABLE demo_snapshots (tenant_id text, id text, revision bigint NOT NULL, manifest jsonb NOT NULL, PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_tickets (tenant_id text, id text, subject_ref text NOT NULL, status text NOT NULL,
 private_note text NOT NULL, visible boolean NOT NULL DEFAULT true, PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_vault (tenant_id text, id text, body text NOT NULL, hash text NOT NULL, expires_at timestamptz NOT NULL, PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_admissions (tenant_id text, id text, hash text NOT NULL, PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_provider (tenant_id text, id text, request_hash text NOT NULL, request_ref text NOT NULL, response_ref text NOT NULL, PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_transport_calls (id bigserial PRIMARY KEY, tenant_id text NOT NULL, invocation_id text NOT NULL);
CREATE TABLE demo_submissions (tenant_id text, id text, request_hash text NOT NULL, action_id text NOT NULL, PRIMARY KEY(tenant_id,id), UNIQUE(tenant_id,action_id));
CREATE TABLE demo_reads (tenant_id text, action_id text, payload_ref text NOT NULL, PRIMARY KEY(tenant_id,action_id));
CREATE TABLE demo_delivery_attempts (tenant_id text, id text, attempts int NOT NULL CHECK(attempts BETWEEN 0 AND 3),
 payload_hash text NOT NULL, body_hash text NOT NULL, actor_ref text NOT NULL, policy_revision text NOT NULL, visibility_ref text NOT NULL,
 PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_messages (tenant_id text, id text, message_ref text NOT NULL, payload_hash text NOT NULL, body_hash text NOT NULL, body text NOT NULL,
 PRIMARY KEY(tenant_id,id));
CREATE TABLE demo_signals (tenant_id text, event_key text, payload_hash text NOT NULL, run_id text NOT NULL, PRIMARY KEY(tenant_id,event_key));
CREATE TABLE demo_not_sent (tenant_id text, invocation_id text, request_hash text NOT NULL, denial_code text NOT NULL, claim_epoch bigint NOT NULL, PRIMARY KEY(tenant_id,invocation_id));
