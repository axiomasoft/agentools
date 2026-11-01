-- at1 stays unchanged. Only encrypted preview/token bytes and technical bindings live here.
CREATE TABLE confirmation_previews (
    tenant_id text NOT NULL, id text NOT NULL,
    version bigint NOT NULL DEFAULT 1 CHECK (version=1),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    action_ref text NOT NULL, request_hash text NOT NULL CHECK (request_hash ~ '^sha-256:[0-9a-f]{64}$'),
    binding_hash text NOT NULL CHECK (binding_hash ~ '^sha-256:[0-9a-f]{64}$'), binding jsonb NOT NULL,
    token_digest text NOT NULL CHECK (token_digest ~ '^sha-256:[0-9a-f]{64}$'), sealed text NOT NULL,
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,action_ref), UNIQUE (tenant_id,token_digest),
    FOREIGN KEY (tenant_id,action_ref) REFERENCES action_origins(tenant_id,id)
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON confirmation_previews FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE TABLE confirmation_proofs (
    tenant_id text NOT NULL, id text NOT NULL,
    version bigint NOT NULL DEFAULT 1 CHECK (version=1),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    source_ref text NOT NULL, source_hash text NOT NULL, binding_hash text NOT NULL,
    actor_ref text NOT NULL, channel text NOT NULL CHECK (channel IN ('ui','voice')),
    expires_at timestamptz NOT NULL, journal_version bigint NOT NULL,
    PRIMARY KEY (tenant_id,id), UNIQUE (tenant_id,source_ref),
    FOREIGN KEY (tenant_id,id) REFERENCES confirmation_previews(tenant_id,id)
);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON confirmation_proofs FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
CREATE FUNCTION platform_confirmation_commit_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF TG_TABLE_NAME='confirmation_previews' THEN
        IF NOT EXISTS (SELECT 1 FROM agentools_events WHERE tenant_id=NEW.tenant_id AND action_id=NEW.action_ref
            AND seq=(NEW.binding->>'action_version')::bigint AND kind='confirmation_requested'
            AND payload_json::jsonb->>'proposal_id'=NEW.id AND payload_json::jsonb->>'binding_hash'=NEW.binding_hash) THEN
            RAISE EXCEPTION 'preview requires a committed journal binding' USING ERRCODE='23514';
        END IF;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM confirmation_previews p JOIN agentools_events e
            ON e.tenant_id=p.tenant_id AND e.action_id=p.action_ref
            WHERE p.tenant_id=NEW.tenant_id AND p.id=NEW.id AND p.binding_hash=NEW.binding_hash
            AND NEW.expires_at<=(p.binding->>'expires_at')::timestamptz
            AND e.seq=NEW.journal_version AND e.kind='platform.confirmation.proof'
            AND e.payload_json::jsonb->>'proposal_id'=NEW.id AND e.payload_json::jsonb->>'binding_hash'=NEW.binding_hash
            AND e.payload_json::jsonb->>'source_ref'=NEW.source_ref AND e.payload_json::jsonb->>'source_hash'=NEW.source_hash
            AND e.payload_json::jsonb->>'actor_ref'=NEW.actor_ref AND e.payload_json::jsonb->>'channel'=NEW.channel) THEN
            RAISE EXCEPTION 'proof requires authenticated journal evidence' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER preview_commit AFTER INSERT ON confirmation_previews
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION platform_confirmation_commit_guard();
CREATE CONSTRAINT TRIGGER proof_commit AFTER INSERT ON confirmation_proofs
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION platform_confirmation_commit_guard();
-- Columns used by the existing dated PdoPostgresConfirmationTokenStore are preserved.
CREATE TABLE confirmation_consumed_tokens (
    tenant_id text NOT NULL,
    version bigint NOT NULL DEFAULT 1,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    token_id text NOT NULL, token_digest bytea NOT NULL, purpose text NOT NULL,
    outcome text NOT NULL CHECK (outcome IN ('presented','authorized','authorization_denied','policy_denied')),
    expires_at timestamptz NOT NULL, presented_at timestamptz NOT NULL,
    PRIMARY KEY (tenant_id,token_id)
);
CREATE TABLE action_cancellation_requests (
    tenant_id text NOT NULL, id text NOT NULL,
    version bigint NOT NULL DEFAULT 1 CHECK (version=1),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    action_ref text NOT NULL, request_hash text NOT NULL,
    PRIMARY KEY (tenant_id,id), FOREIGN KEY (tenant_id,action_ref) REFERENCES action_origins(tenant_id,id)
);
CREATE INDEX cancellation_action ON action_cancellation_requests(tenant_id,action_ref);
CREATE TRIGGER append_only BEFORE UPDATE OR DELETE ON action_cancellation_requests FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard();
