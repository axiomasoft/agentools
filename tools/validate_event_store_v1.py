#!/usr/bin/env python3
"""Validate the P5 EventStore/audit/observability contract and adversarial manifest."""
from __future__ import annotations
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "fixtures/hardening/event-store-v1.json"
PLAN = ROOT / "verification"
CONTRACT = PLAN / "root/contracts/event-store-v1.md"
MIGRATION = PLAN / "findings/P5-eventlog-migration.md"
PHASES = {"append", "load", "collision", "replay", "pagination", "audit", "metrics", "snapshot"}
CATEGORIES = {"success", "validation", "precondition", "permission", "conflict", "budget"}
REQUIRED = {
    "append_one_winner_of_fifty",
    "atomic_two_event_batch_rollback",
    "identical_replay",
    "payload_collision",
    "partial_overlap",
    "duplicate_id_in_batch",
    "empty_batch",
    "page_abut_no_skip",
    "page_limit_over_1000_reject",
    "secret_pii_audit_reject",
    "metric_label_deny_tenant_action",
    "snapshot_hash_mismatch_discard",
    "seq_derived_event_id_reject",
}
REQUIRED_CONSUMERS = (
    "EventLog.php", "event_log.dart",
    "InMemoryEventLog.php", "in_memory_event_log.dart",
    "SqliteEventLog.php",
    "JournalAppender.php", "journal_appender.dart",
    "AuditEventFactory.php", "audit_event_factory.dart",
    "ActionJournal.php", "action_journal.dart",
    "ActionSnapshot.php", "action_snapshot.dart",
    "SessionStateProjection.php", "session_state_projection.dart",
    "SdkConformanceEngine.php", "SqliteJournalExercise.php",
    "CollectingAuditSink.php",
    "PersistenceContractsTest.php", "persistence_contracts_test.dart",
    "SqliteEventLogTest.php",
    "LongOperationCoordinator.php", "EffectCommitter.php",
    "PSR-4", "agentools.dart",
)
REFUSAL_CODES = {
    "agentools.conflict.version_conflict",
    "agentools.conflict.event_id_collision",
    "agentools.conflict.event_batch_partial_overlap",
    "agentools.validation.event_batch_empty",
    "agentools.validation.event_batch_limit",
    "agentools.validation.event_id_duplicate_in_batch",
    "agentools.validation.event_id_seq_derived",
    "agentools.validation.event_stream_identity",
    "agentools.validation.audit_forbidden_field",
    "agentools.validation.metric_label_forbidden",
    "agentools.budget.event_page_limit",
}


def main() -> int:
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert data.get("schema_version") == "event-store-cases/v1"
    cases = data.get("cases")
    assert isinstance(cases, list) and cases
    ids, scenarios, codes = [], set(), set()
    for case in cases:
        assert isinstance(case, dict)
        case_id = case.get("id")
        assert isinstance(case_id, str) and case_id.startswith("EVT-STORE-")
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
        "UncommittedEvent", "ActionEvent", "expected_prev_seq", "global_position",
        "GENERATED ALWAYS AS IDENTITY", "Classify-before-INSERT", "idempotent_replay",
        "content_hash", "sha-256:", "RFC 8785", "Keyset", "OFFSET",
        "AuditSink", "event_kind_class", "tenant_id", "action_id",
        "Exactly-once", "P3", "P4", "P6", "P7", "P8", "[RAG:✅]",
        "EventLog.append(ActionEvent)", "ExpectedVersion.Any", "ev-{action}-{seq}",
        "RL-012", "RL-013", "RL-014", "RL-020",
    ):
        assert phrase in contract, f"contract missing {phrase}"
    for marker in (
        "https://docs.kurrent.io/clients/tcp/dotnet/21.2/appending",
        "https://docs.kurrent.io/clients/python/v1.2/appending-events.md",
        "https://www.postgresql.org/docs/current/sql-insert.html",
        "https://www.postgresql.org/docs/18/ddl-identity-columns.html",
        "https://www.postgresql.org/docs/18/dml-returning.html",
        "https://www.rfc-editor.org/rfc/rfc8785.html",
        "https://prometheus.io/docs/practices/instrumentation/",
        "https://opentelemetry.io/docs/specs/otel/metrics/sdk/",
    ):
        assert marker in contract, f"contract missing primary source {marker}"
    assert contract.count("[RAG:✅]") >= 6
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
