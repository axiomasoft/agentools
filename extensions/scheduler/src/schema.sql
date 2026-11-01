CREATE TABLE scheduler_versions (
    tenant_id text NOT NULL, schedule_id text NOT NULL, version bigint NOT NULL CHECK (version > 0),
    content_hash text NOT NULL CHECK (content_hash ~ '^sha-256:[0-9a-f]{64}$'),
    config jsonb NOT NULL CHECK (jsonb_typeof(config)='object'),
    PRIMARY KEY (tenant_id,schedule_id,version)
);
CREATE FUNCTION scheduler_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'immutable scheduler record' USING ERRCODE='23514'; END $$;
CREATE TRIGGER scheduler_version_immutable BEFORE UPDATE OR DELETE ON scheduler_versions
    FOR EACH ROW EXECUTE FUNCTION scheduler_immutable_guard();
CREATE TABLE scheduler_state (
    tenant_id text NOT NULL, schedule_id text NOT NULL, current_version bigint NOT NULL,
    enabled boolean NOT NULL DEFAULT true, cursor_at timestamptz, active_batch text,
    PRIMARY KEY (tenant_id,schedule_id),
    FOREIGN KEY (tenant_id,schedule_id,current_version) REFERENCES scheduler_versions(tenant_id,schedule_id,version)
);
CREATE TABLE scheduler_batches (
    tenant_id text NOT NULL, schedule_id text NOT NULL, id text NOT NULL, config_version bigint NOT NULL,
    through_at timestamptz NOT NULL, slots jsonb NOT NULL CHECK (jsonb_typeof(slots)='array' AND jsonb_array_length(slots) BETWEEN 1 AND 100),
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,schedule_id,id),
    FOREIGN KEY (tenant_id,schedule_id,config_version) REFERENCES scheduler_versions(tenant_id,schedule_id,version)
);
CREATE TRIGGER scheduler_batch_immutable BEFORE UPDATE OR DELETE ON scheduler_batches
    FOR EACH ROW EXECUTE FUNCTION scheduler_immutable_guard();
ALTER TABLE scheduler_state ADD CONSTRAINT scheduler_active_batch_fk FOREIGN KEY (tenant_id,schedule_id,active_batch)
    REFERENCES scheduler_batches(tenant_id,schedule_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE scheduler_occurrences (
    tenant_id text NOT NULL, schedule_id text NOT NULL, planned_at timestamptz NOT NULL,
    id text NOT NULL, config_version bigint NOT NULL, timezone text NOT NULL, batch_id text NOT NULL,
    content_hash text NOT NULL CHECK (content_hash ~ '^sha-256:[0-9a-f]{64}$'),
    turn_id text NOT NULL, run_id text NOT NULL, input_ref text NOT NULL, input_hash text NOT NULL,
    conversation_ref text, message_ref text, utterance_ref text,
    PRIMARY KEY (tenant_id,schedule_id,planned_at), UNIQUE (tenant_id,id),
    FOREIGN KEY (tenant_id,schedule_id,config_version) REFERENCES scheduler_versions(tenant_id,schedule_id,version),
    FOREIGN KEY (tenant_id,schedule_id,batch_id) REFERENCES scheduler_batches(tenant_id,schedule_id,id),
    FOREIGN KEY (tenant_id,turn_id) REFERENCES turns(tenant_id,id) DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (tenant_id,run_id) REFERENCES runs(tenant_id,id) DEFERRABLE INITIALLY DEFERRED
);
CREATE TRIGGER scheduler_occurrence_immutable BEFORE UPDATE OR DELETE ON scheduler_occurrences
    FOR EACH ROW EXECUTE FUNCTION scheduler_immutable_guard();
