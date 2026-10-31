-- Keep historical receipts immutable. Only the latest version, bound to the
-- intent's content and owner receipt, can establish a known delivery outcome.
CREATE FUNCTION platform_delivery_resolved(tenant text, delivery text, result text, receipt text, hash text)
RETURNS boolean LANGUAGE sql STABLE SET search_path FROM CURRENT AS $$
    SELECT result IN ('success','failed') AND btrim(receipt)<>'' AND EXISTS (
        SELECT 1 FROM delivery_receipts c
        WHERE c.tenant_id=tenant AND c.delivery_id=delivery
          AND c.outcome=result AND c.receipt_ref=receipt AND c.payload_hash=hash
          AND NOT EXISTS (SELECT 1 FROM delivery_receipts newer
              WHERE newer.tenant_id=c.tenant_id AND newer.delivery_id=c.delivery_id
                AND newer.receipt_version>c.receipt_version));
$$;

CREATE FUNCTION platform_delivery_resolution_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF NEW.outcome IN ('success','failed') AND (
        platform_delivery_resolved(NEW.tenant_id,NEW.id,NEW.outcome,NEW.receipt_ref,NEW.payload_hash) IS NOT TRUE
        OR NOT EXISTS (SELECT 1 FROM responses r JOIN payload_refs p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
            WHERE r.tenant_id=NEW.tenant_id AND r.id=NEW.response_id
              AND r.payload_hash=NEW.payload_hash AND p.content_hash=NEW.payload_hash)) THEN
        RAISE EXCEPTION 'bound latest delivery receipt required' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER delivery_resolution BEFORE INSERT OR UPDATE OF outcome,receipt_ref ON delivery_intents
    FOR EACH ROW EXECUTE FUNCTION platform_delivery_resolution_guard();

-- A legacy intent may already say success without evidence. Check the receipt
-- head independently at reservation, at completion, and on repository retries.
CREATE OR REPLACE FUNCTION platform_payload_unresolved(tenant text, payload text) RETURNS boolean LANGUAGE sql STABLE SET search_path FROM CURRENT AS $$
    SELECT EXISTS (SELECT 1 FROM outbox WHERE tenant_id=tenant AND payload_ref_id=payload AND status<>'dispatched')
    OR EXISTS (SELECT 1 FROM delivery_intents d JOIN responses r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
        JOIN payload_refs p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
        WHERE r.tenant_id=tenant AND r.payload_ref_id=payload AND (
            d.payload_hash<>r.payload_hash OR d.payload_hash<>p.content_hash
            OR platform_delivery_resolved(d.tenant_id,d.id,d.outcome,d.receipt_ref,d.payload_hash) IS NOT TRUE))
    OR EXISTS (SELECT 1 FROM effect_intents WHERE tenant_id=tenant AND request_ref=payload AND outcome IN ('pending','unknown'))
    OR EXISTS (SELECT 1 FROM invocation_intents i LEFT JOIN invocation_results x ON x.tenant_id=i.tenant_id AND x.invocation_id=i.id
        WHERE i.tenant_id=tenant AND i.request_ref=payload AND (x.outcome IS NULL OR x.outcome='unknown'))
    OR EXISTS (SELECT 1 FROM turns t JOIN runs r ON r.tenant_id=t.tenant_id AND r.turn_id=t.id
        WHERE t.tenant_id=tenant AND t.input_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM run_steps s JOIN runs r ON r.tenant_id=s.tenant_id AND r.id=s.run_id
        WHERE s.tenant_id=tenant AND s.state_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM proposal_map p JOIN runs r ON r.tenant_id=p.tenant_id AND r.id=p.run_id
        WHERE p.tenant_id=tenant AND p.proposal_ref=payload AND r.status NOT IN ('completed','failed','cancelled'))
    OR EXISTS (SELECT 1 FROM invocation_results x JOIN invocation_intents i ON i.tenant_id=x.tenant_id AND i.id=x.invocation_id
        JOIN runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id
        WHERE x.tenant_id=tenant AND x.result_ref=payload AND r.status NOT IN ('completed','failed','cancelled'));
$$;

-- Receipt history can advance independently of the intent. Serialize all such
-- inserts with the payload reservation; a new unknown/mismatched head cannot
-- appear during external deletion. Replays of bound known evidence stay valid.
CREATE FUNCTION platform_delivery_receipt_payload_guard() RETURNS trigger LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
DECLARE item record;
BEGIN
    SELECT p.purge_pending_at,p.purged_at,p.content_hash,r.payload_hash AS response_hash,
        d.outcome,d.receipt_ref,d.payload_hash INTO item
        FROM delivery_intents d JOIN responses r ON r.tenant_id=d.tenant_id AND r.id=d.response_id
        JOIN payload_refs p ON p.tenant_id=r.tenant_id AND p.id=r.payload_ref_id
        WHERE d.tenant_id=NEW.tenant_id AND d.id=NEW.delivery_id FOR UPDATE OF p;
    IF FOUND AND (item.purge_pending_at IS NOT NULL OR item.purged_at IS NOT NULL) AND (
        NEW.outcome NOT IN ('success','failed') OR coalesce(btrim(NEW.receipt_ref),'')=''
        OR NEW.payload_hash<>item.payload_hash OR NEW.payload_hash<>item.response_hash OR NEW.payload_hash<>item.content_hash
        OR (platform_delivery_resolved(NEW.tenant_id,NEW.delivery_id,item.outcome,item.receipt_ref,item.payload_hash) IS TRUE
            AND (NEW.outcome IS DISTINCT FROM item.outcome OR NEW.receipt_ref IS DISTINCT FROM item.receipt_ref))) THEN
        RAISE EXCEPTION 'payload purge prevents unresolved delivery receipt' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER payload_dependency BEFORE INSERT ON delivery_receipts
    FOR EACH ROW EXECUTE FUNCTION platform_delivery_receipt_payload_guard();
