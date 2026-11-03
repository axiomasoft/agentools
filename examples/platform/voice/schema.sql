-- Synthetic external host/media owner state, never production Loqum or Chatom tables.
CREATE TABLE voice_demo_state (
 tenant_id text PRIMARY KEY, allowed boolean NOT NULL DEFAULT true,
 subject_ref text NOT NULL DEFAULT 'subject:1',locale text NOT NULL DEFAULT 'ru-RU',
 context_revision bigint NOT NULL DEFAULT 1,media_epoch bigint NOT NULL DEFAULT 1,
 policy_revision text NOT NULL DEFAULT 'policy-1',recording boolean NOT NULL DEFAULT true,
 memory boolean NOT NULL DEFAULT true,export boolean NOT NULL DEFAULT true,text_allowed boolean NOT NULL DEFAULT true,
 retention_applied boolean NOT NULL DEFAULT false
);
CREATE TABLE voice_demo_vault (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,sealed text NOT NULL,scope_hash text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_snapshots (tenant_id text NOT NULL,id text NOT NULL,revision bigint NOT NULL,context_revision bigint NOT NULL,manifest jsonb NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_admissions (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_provider (tenant_id text NOT NULL,id text NOT NULL,request_hash text NOT NULL,deployment_ref text NOT NULL,attempts integer NOT NULL DEFAULT 1,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_media (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,offset_bytes bigint NOT NULL,artifact_ref text NOT NULL,physical_plays integer NOT NULL DEFAULT 0,receipt jsonb,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_display (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,response_ref text NOT NULL,receipt_ref text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_messages (tenant_id text NOT NULL,id text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_commands (tenant_id text NOT NULL,id text NOT NULL,hash text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_stops (tenant_id text NOT NULL,id text NOT NULL,epoch bigint NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_operations (tenant_id text NOT NULL,id text NOT NULL,kind text NOT NULL,PRIMARY KEY(tenant_id,id));
CREATE TABLE voice_demo_checkpoints (tenant_id text NOT NULL,id text NOT NULL,state jsonb NOT NULL,PRIMARY KEY(tenant_id,id));
