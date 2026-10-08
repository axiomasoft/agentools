#!/usr/bin/env python3
"""Validate the P3 token-replay contract and adversarial manifest."""
from __future__ import annotations
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "fixtures/hardening/token-replay-v1.json"
PLAN = ROOT / "verification"
CONTRACT = PLAN / "root/contracts/token-replay-v1.md"
MIGRATION = PLAN / "findings/P3-token-migration.md"
PHASES = {"parse", "verify", "consume", "cleanup"}
CATEGORIES = {"success", "validation", "precondition", "permission", "conflict"}
REQUIRED = {
    "oversize", "extra_segment", "duplicate_json_name", "unknown_alg", "none_alg",
    "unknown_kid", "retired_kid", "compromised_kid", "cross_tenant_key",
    "purpose_mismatch", "iss_mismatch", "aud_mismatch", "nbf_skew", "exp_skew",
    "empty_returning_spent", "digest_jti_collision", "cleanup_unexpired_row",
    "restart_after_consume",
}
REQUIRED_CONSUMERS = (
    "ConfirmationToken.php", "confirmation_token.dart", "HmacTokenSigner.php",
    "hmac_token_signer.dart", "TokenSigner.php", "token_signer.dart",
    "ConfirmationProtocol.php", "confirmation_protocol.dart",
    "ConfirmationService.php", "confirmation_service.dart",
    "TenantHmacTokenSigner.php", "SliceConfirmationDependencies.php",
    "ReferenceConfirmationServiceFactory.php", "ConfirmationProtocolTest.php",
    "confirmation_protocol_test.dart", "PSR-4", "agentools.dart",
)


def main() -> int:
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert data.get("schema_version") == "token-replay-cases/v1"
    cases = data.get("cases")
    assert isinstance(cases, list) and cases
    ids, scenarios, codes = [], set(), set()
    for case in cases:
        assert isinstance(case, dict)
        case_id = case.get("id")
        assert isinstance(case_id, str) and case_id.startswith("CNF-RPLY-")
        ids.append(case_id)
        scenario = case.get("scenario")
        assert isinstance(scenario, str) and scenario
        scenarios.add(scenario)
        assert case.get("phase") in PHASES
        assert case.get("category") in CATEGORIES
        assert case.get("result") in {"allow", "deny"}
        assert case.get("retryable") is False
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
    for code in codes:
        assert code in contract, f"contract missing {code}"
    for phrase in (
        "at1.", "presentation-consume", "token_id", "jti", "token_digest",
        "ON CONFLICT", "DO NOTHING", "RETURNING", "verify_only", "compromised",
        "retired", "dual-read parser is forbidden", "P4", "P5", "P6", "P7", "P8",
        "[RAG:✅]",
    ):
        assert phrase in contract, f"contract missing {phrase}"
    for marker in ("https://www.rfc-editor.org/rfc/rfc8725.html",
                   "https://www.rfc-editor.org/rfc/rfc8259.html",
                   "https://www.postgresql.org/docs/current/sql-insert.html"):
        assert marker in contract, f"contract missing primary source {marker}"
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
