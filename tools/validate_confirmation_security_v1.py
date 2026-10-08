#!/usr/bin/env python3
"""Validate the P2 confirmation-security contract and adversarial manifest."""
from __future__ import annotations
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "fixtures/hardening/confirmation-security-v1.json"
PLAN = ROOT / "verification"
CONTRACT = PLAN / "root/contracts/confirmation-security-v1.md"
MIGRATION = PLAN / "findings/P2-confirmation-migration.md"
REQUIRED = {
 "caller_actor_injection_absent", "caller_clock_injection_absent",
 "caller_descriptor_injection_absent", "caller_authorizer_injection_absent",
 "caller_policy_injection_absent", "caller_approval_injection_absent",
 "caller_dual_control_override_absent", "null_proposer", "same_proposer",
 "cross_tenant_resource", "policy_missing", "policy_downgraded", "policy_changed",
 "approval_wrong_proposal", "approval_wrong_version", "approval_wrong_args",
 "approval_wrong_descriptor", "approval_wrong_policy", "duplicate_approver",
 "insufficient_quorum", "revoked_approval", "expired_approval",
 "authorization_revoked", "redacted_denial",
}

def main() -> int:
 data = json.loads(MANIFEST.read_text(encoding="utf-8"))
 assert data.get("schema_version") == "confirmation-security-cases/v1"
 cases = data.get("cases")
 assert isinstance(cases, list) and cases
 ids, scenarios, codes = [], set(), set()
 for case in cases:
  assert isinstance(case, dict)
  case_id = case.get("id")
  assert isinstance(case_id, str) and case_id.startswith("CNF-SAFE-")
  ids.append(case_id)
  scenario = case.get("scenario")
  assert isinstance(scenario, str) and scenario
  scenarios.add(scenario)
  assert case.get("phase") in {"prepare", "commit"}
  assert case.get("category") in {"success", "validation", "precondition", "permission"}
  assert case.get("result") in {"allow", "deny"}
  assert case.get("retryable") is False
  assert isinstance(case.get("trust_seam"), str) and case["trust_seam"]
  assert case.get("applicability") == ["php", "dart"]
  code = case.get("semantic_code")
  assert isinstance(code, str) and code
  if code != "ok": codes.add(code)
 assert len(ids) == len(set(ids)), "case IDs must be unique"
 assert REQUIRED <= scenarios, sorted(REQUIRED - scenarios)
 contract = CONTRACT.read_text(encoding="utf-8")
 for code in codes: assert code in contract, f"contract missing {code}"
 for phrase in ("tenant ID", "policy ID and revision", "distinct counted approver", "P3", "P5", "P6", "P7"):
  assert phrase in contract, f"contract missing {phrase}"
 migration = MIGRATION.read_text(encoding="utf-8")
 for consumer in ("SliceToolCoordinator.php", "SliceToolCoordinatorFactory.php", "DayEndOrchestrator.php", "ConfirmationProtocolTest.php", "confirmation_protocol_test.dart", "ConfirmationService.php", "confirmation_service.dart", "PSR-4", "agentools.dart"):
  assert consumer in migration, f"migration missing {consumer}"
 print(json.dumps({"schema_version": data["schema_version"], "cases": len(cases), "missing": [], "status": "green"}, sort_keys=True))
 return 0

if __name__ == "__main__":
 raise SystemExit(main())
