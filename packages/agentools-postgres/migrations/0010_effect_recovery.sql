-- Additive technical recovery state. Domain outcome remains authoritative at the owner.
ALTER TABLE effect_intents ADD COLUMN retry_strategy text NOT NULL DEFAULT 'unsupported'
    CHECK (retry_strategy IN ('native_key','read_before_write','unsupported'));
ALTER TABLE effect_intents ADD COLUMN attempt_count integer NOT NULL DEFAULT 1 CHECK (attempt_count BETWEEN 1 AND 3);
ALTER TABLE effect_intents ADD COLUMN retry_after timestamptz NOT NULL DEFAULT clock_timestamp();
CREATE TRIGGER effect_strategy_identity BEFORE UPDATE ON effect_intents FOR EACH ROW
    EXECUTE FUNCTION platform_identity_guard('retry_strategy');

CREATE FUNCTION platform_effect_outcome_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.outcome IN ('success','failed') AND
       (NEW.outcome IS DISTINCT FROM OLD.outcome OR NEW.receipt_ref IS DISTINCT FROM OLD.receipt_ref) THEN
        RAISE EXCEPTION 'accepted effect outcome is immutable' USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER effect_outcome_guard BEFORE UPDATE ON effect_intents FOR EACH ROW
    EXECUTE FUNCTION platform_effect_outcome_guard();
