#!/usr/bin/env python3
"""Validate the P4 lease/fencing contract and adversarial manifest."""
from __future__ import annotations
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "fixtures/hardening/idempotency-lease-v1.json"
PLAN = ROOT / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
CONTRACT = PLAN / "root/contracts/idempotency-lease-v1.md"
MIGRATION = PLAN / "findings/P4-idempotency-migration.md"
PHASES = {"acquire", "heartbeat", "complete", "fail", "cleanup", "capability"}
CATEGORIES = {"success", "validation", "precondition", "permission", "conflict"}
REQUIRED = {
    "acquire_one_winner_of_fifty",
    "in_flight_immediate",
    "fingerprint_reuse",
    "takeover_after_expiry",
    "stale_complete_lost_lease",
    "stale_fail_lost_lease",
    "stale_heartbeat_lost_lease",
    "completed_error_replay",
    "in_progress_survives_record_ttl",
    "completed_expiry_becomes_new",
    "high_risk_unknown_deny_before_insert",
    "at_most_once_takeover_stores_ambiguous",
    "native_key_passes_same_key",
}
REQUIRED_CONSUMERS = (
    "IdempotencyStore.php", "idempotency_store.dart",
    "InMemoryDeduplicationStore.php", "in_memory_deduplication_store.dart",
    "SqliteIdempotencyStore.php", "DeduplicationResolution.php",
    "deduplication_resolution.dart", "ScenarioRunner.php", "scenario_runner.dart",
    "SliceToolCoordinator.php", "SliceToolCoordinatorFactory.php",
    "InMemoryDeduplicationStoreTest.php", "in_memory_deduplication_store_test.dart",
    "SqliteIdempotencyStoreTest.php", "ToolErrorCode.php",
    "idempotency_error_codes.dart", "PSR-4", "agentools.dart",
)
REFUSAL_CODES = {
    "agentools.validation.idempotency_key_missing",
    "agentools.validation.idempotency_key_reuse",
    "agentools.conflict.idempotency_in_flight",
    "agentools.conflict.idempotency_lost_lease",
    "agentools.precondition.idempotency_ambiguous_effect",
    "agentools.precondition.idempotency_capability_unknown",
}


def main() -> int:
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert data.get("schema_version") == "idempotency-lease-cases/v1"
    cases = data.get("cases")
    assert isinstance(cases, list) and cases
    ids, scenarios, codes = [], set(), set()
    for case in cases:
        assert isinstance(case, dict)
        case_id = case.get("id")
        assert isinstance(case_id, str) and case_id.startswith("IDE-LEASE-")
        ids.append(case_id)
        scenario = case.get("scenario")
        assert isinstance(scenario, str) and scenario
        scenarios.add(scenario)
        assert case.get("phase") in PHASES
        assert case.get("category") in CATEGORIES
        assert case.get("result") in {"allow", "deny"}
        assert isinstance(case.get("retryable"), bool)
        assert isinstance(case.get("trust_seam"), str) and case["trust_seam"]
        assert case.get("applicability") == ["php", "dart"]
        code = case.get("semantic_code")
        assert isinstance(code, str) and code
        if code != "ok":
            codes.add(code)
    assert len(ids) == len(set(ids)), "case IDs must be unique"
    assert all(case_id for case_id in ids)
    missing = sorted(REQUIRED - scenarios)
    assert not missing, missing
    contract = CONTRACT.read_text(encoding="utf-8")
    for code in codes | REFUSAL_CODES:
        assert code in contract, f"contract missing {code}"
    mapped = {case["semantic_code"] for case in cases if case["semantic_code"] != "ok"}
    unmapped = sorted(REFUSAL_CODES - mapped)
    assert not unmapped, unmapped
    for phrase in (
        "acquire", "heartbeat", "complete(lease)", "fail(lease)",
        "in_progress", "completed", "DedupState", "lease_token",
        "ON CONFLICT", "DO NOTHING", "RETURNING", "native_key",
        "read_before_write", "compensatable", "at_most_once_only",
        "exactly-once", "P5", "P6", "P7", "P8", "[RAG:✅]",
        "complete(request)", "ALC epoch",
    ):
        assert phrase in contract, f"contract missing {phrase}"
    for marker in (
        "https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html",
        "https://www.postgresql.org/docs/current/sql-update.html",
        "https://www.postgresql.org/docs/current/sql-insert.html",
        "https://docs.aws.amazon.com/powertools/python/latest/utilities/idempotency/",
        "https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-idempotency-key-header-07",
    ):
        assert marker in contract, f"contract missing primary source {marker}"
    assert contract.count("[RAG:✅]") >= 4
    migration = MIGRATION.read_text(encoding="utf-8")
    for consumer in REQUIRED_CONSUMERS:
        assert consumer in migration, f"migration missing {consumer}"
    print(json.dumps({
        "schema_version": data["schema_version"],
        "cases": len(cases),
        "missing": [],
        "status": "green",
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
