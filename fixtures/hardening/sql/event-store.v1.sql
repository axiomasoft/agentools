-- agentools event store v1
-- Stream identity is (tenant_id, action_id). Storage assigns seq and
-- global_position. Classify replay/collision/partial BEFORE INSERT.
-- ON CONFLICT DO NOTHING is not the classifier. Application DELETE is forbidden.

CREATE TABLE IF NOT EXISTS agentools_event_streams (
    tenant_id TEXT NOT NULL,
    action_id TEXT NOT NULL,
    current_seq BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant_id, action_id)
);

CREATE TABLE IF NOT EXISTS agentools_events (
    tenant_id TEXT NOT NULL,
    action_id TEXT NOT NULL,
    seq BIGINT NOT NULL,
    event_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    event_version INTEGER NOT NULL,
    spec_rev TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    actor_id TEXT NOT NULL,
    ts TIMESTAMPTZ NOT NULL,
    ts_text TEXT NOT NULL, -- exact wire timestamp bytes used by both content and chain hashes
    payload_json TEXT NOT NULL,
    epoch BIGINT,
    prev_hash TEXT,
    content_hash TEXT NOT NULL,
    expected_prev_seq BIGINT NOT NULL,
    global_position BIGINT GENERATED ALWAYS AS IDENTITY,
    PRIMARY KEY (tenant_id, action_id, seq),
    UNIQUE (tenant_id, event_id),
    UNIQUE (global_position),
    CONSTRAINT agentools_events_seq_chk CHECK (seq >= 1),
    CONSTRAINT agentools_events_expected_chk CHECK (expected_prev_seq >= 0)
);
