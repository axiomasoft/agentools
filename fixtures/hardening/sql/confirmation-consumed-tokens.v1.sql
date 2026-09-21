-- confirmation_consumed_tokens v1
-- Unique (tenant_id, token_id) is the durable CNF-20 arbiter. Partial unique
-- indexes on unexpired rows are forbidden.

CREATE TABLE IF NOT EXISTS confirmation_consumed_tokens (
    tenant_id TEXT NOT NULL,
    token_id TEXT NOT NULL,
    token_digest BYTEA NOT NULL,
    purpose TEXT NOT NULL,
    outcome TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    presented_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (tenant_id, token_id),
    CONSTRAINT confirmation_consumed_tokens_outcome_chk
        CHECK (outcome IN ('presented', 'authorized', 'authorization_denied', 'policy_denied'))
);
