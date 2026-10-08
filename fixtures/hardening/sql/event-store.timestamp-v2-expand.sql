-- Explicit expansion for existing installations. Run before upgrading either SDK.
-- Backfill ts_text from trusted original envelopes and verify existing hashes.
-- Do NOT derive it from ts: TIMESTAMPTZ has discarded the original text/precision.
ALTER TABLE agentools_events ADD COLUMN IF NOT EXISTS ts_text TEXT;
-- After verified backfill, separately enforce:
-- ALTER TABLE agentools_events ALTER COLUMN ts_text SET NOT NULL;
