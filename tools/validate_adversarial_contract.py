#!/usr/bin/env python3
"""Exact-set validator and emitter for adversarial-verification-v1 (P8.1)."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import tempfile
import unittest
from copy import deepcopy
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
CONTRACT = PLAN / "root/contracts/adversarial-verification-v1.md"
MANIFEST = REPO / "fixtures/hardening/adversarial/manifest.v1.json"
SCHEMA = "adversarial-verification-manifest/v1"
GENERATOR_VERSION = "adversarial-corpus/1.0.0"

PROOF_CLASSES = ("example", "property", "differential", "mutation", "schedule", "resource")
TIERS = ("focused", "bounded", "extended", "release-candidate")
CRITICALITY = ("critical", "ordinary", "informational")
DISPOSITION_KINDS = ("covered", "non-guarantee", "deferred", "upstream-closed", "not-applicable")
OWNER_PHASES = {"P1", "P2", "P3", "P4", "P5", "P6", "P7", "P8", "P9", "P10", "phase-design", "owner"}
ORACLE_KINDS = ("independent", "external", "fixture", "structural")

SOURCE_PATHS = {
    "hardening_guarantees": PLAN / "root/contracts/hardening-guarantees-v1.md",
    "resource_envelope": PLAN / "root/contracts/resource-envelope-v1.md",
    "confirmation_security": PLAN / "root/contracts/confirmation-security-v1.md",
    "token_replay": PLAN / "root/contracts/token-replay-v1.md",
    "idempotency_lease": PLAN / "root/contracts/idempotency-lease-v1.md",
    "event_store": PLAN / "root/contracts/event-store-v1.md",
    "semantic_manifest": REPO / "spec/revisions/2026-09/semantic-manifest.json",
    "package_boundary": PLAN / "root/contracts/package-boundary-v1.md",
    "package_surface": REPO / "api/package-surface-v1.json",
    "package_gate": PLAN / "artifacts/P7-package-gate.json",
    "decision_d13": PLAN / "decisions/D13-adversarial-verification-evidence.md",
    "audit_verdicts": PLAN / "findings/audit-verdicts.md",
}

RANDOM_BOUNDED = {
    "algorithm": "sha256-counter",
    "generator_version": GENERATOR_VERSION,
    "seeds": [1],
    "case_budget": 64,
    "promotion": "minimize-then-immutable-regression",
}
RANDOM_EXTENDED = {
    **RANDOM_BOUNDED,
    "seeds": [1, 2, 3],
    "case_budget": 256,
}
GENERATED_CORPORA = {
    "CRP-JCS-TYPED",
    "CRP-JCS-RAW",
    "CRP-TKN-HOSTILE",
    "CRP-LIM-BOUNDARY",
    "CRP-JCS-10K",
}
FROZEN_RESIDUALS = (
    "F10",
    "RES-P2-DURABLE-CONSUME",
    "RES-P2-JOURNAL",
    "RES-P2-ERROR-REGISTRY",
    "RES-P2-EXPERT-API",
    "RES-P3-STORE-DOCS",
    "RES-P3-INMEMORY",
    "RES-P3-CI-PG",
    "RES-P4-EPOCH",
    "RES-P4-ERROR-REGISTRY",
    "RES-P4-PACKAGING",
    "RES-P4-MUTATION-CI",
    "RES-P5-ERROR-REGISTRY",
    "RES-P5-PACKAGING",
    "RES-P5-MUTATION",
    "RES-P5-CI-PG",
    "RES-P6-TAG",
    "RES-P6-PACKAGE",
    "RES-P6-JCS-10K",
    "RES-P6-DART-PG",
    "RES-P6-MCP-MRTR",
    "RES-P6-BEHAVIOR-213",
    "RES-P6-D5",
    "RES-P7-PHP-TESTKIT",
    "RES-P7-DART-TESTKIT",
    "RES-P7-MCP-PATH",
    "RES-P7-DART-CONF",
    "RES-P7-PUBLISH",
)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"


def source_digests() -> dict[str, str]:
    return {name: sha256_file(path) for name, path in sorted(SOURCE_PATHS.items())}


def extract_ids(text: str, pattern: str) -> list[str]:
    found = re.findall(pattern, text, flags=re.MULTILINE)
    return list(dict.fromkeys(found))


def guarantee_ids() -> list[str]:
    return extract_ids(SOURCE_PATHS["hardening_guarantees"].read_text(encoding="utf-8"), r"^\| (G-[A-Z]+-\d{3}) \|")


def nonguarantee_ids() -> list[str]:
    return extract_ids(SOURCE_PATHS["hardening_guarantees"].read_text(encoding="utf-8"), r"^\| (NG-[A-Z]+-\d{3}) \|")


def resource_ids() -> list[str]:
    return extract_ids(SOURCE_PATHS["resource_envelope"].read_text(encoding="utf-8"), r"^\| (RL-\d{3}) \|")


def row(
    av_id: str,
    *,
    owner: str,
    upstream_ref: str,
    criticality: str,
    surfaces: list[str],
    oracle: dict[str, str],
    proof_classes: list[str],
    mutants: list[str],
    schedules: list[str],
    properties: list[str],
    corpora: list[str],
    tiers: list[str],
    resource_link: str | None,
    failure_owner: str,
    red_proof: str | None,
    rc_carrier: str,
    random: dict[str, Any] | None = None,
) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "id": av_id,
        "upstream_owner": owner,
        "upstream_ref": upstream_ref,
        "criticality": criticality,
        "surfaces": surfaces,
        "oracle": oracle,
        "proof_classes": proof_classes,
        "mutants": mutants,
        "schedules": schedules,
        "properties": properties,
        "corpora": corpora,
        "tiers": tiers,
        "resource_link": resource_link,
        "failure_owner": failure_owner,
        "red_proof": red_proof,
        "rc_carrier": rc_carrier,
    }
    if random is not None:
        payload["random"] = random
    return payload


def catalog_entries() -> dict[str, list[dict[str, Any]]]:
    return {
        "oracles": [
            {"id": "ORC-JCS-P6", "kind": "independent", "path": "conformance/oracles/jcs"},
            {"id": "ORC-CNF-FIXTURE", "kind": "fixture", "path": "fixtures/hardening/confirmation-security-v1.json"},
            {"id": "ORC-TKN-FIXTURE", "kind": "fixture", "path": "fixtures/hardening/token-replay-v1.json"},
            {"id": "ORC-IDC-FIXTURE", "kind": "fixture", "path": "fixtures/hardening/idempotency-lease-v1.json"},
            {"id": "ORC-EVT-FIXTURE", "kind": "fixture", "path": "fixtures/hardening/event-store-v1.json"},
            {"id": "ORC-SEM-P6", "kind": "independent", "path": "spec/revisions/2026-09/semantic-manifest.json"},
            {"id": "ORC-PKG-SURFACE", "kind": "independent", "path": "api/package-surface-v1.json"},
            {"id": "ORC-ADV-STRUCT", "kind": "structural", "path": "tools/validate_adversarial_contract.py"},
        ],
        "mutants": [
            {"id": "MUT-CNF-AUTH-BYPASS", "sdks": ["php", "dart"], "fault": "authorization check inverted or removed"},
            {"id": "MUT-CNF-CLOCK-FORGE", "sdks": ["php", "dart"], "fault": "commit uses caller-supplied now"},
            {"id": "MUT-TKN-SKIP-VERIFY", "sdks": ["php", "dart"], "fault": "HMAC compare skipped"},
            {"id": "MUT-TKN-SKIP-CONSUME", "sdks": ["php", "dart"], "fault": "durable consume omitted before authorize"},
            {"id": "MUT-IDC-STALE-FENCE", "sdks": ["php", "dart"], "fault": "stale lease complete accepted"},
            {"id": "MUT-IDC-UNKNOWN-ALLOW", "sdks": ["php", "dart"], "fault": "unknown high-risk capability allowed"},
            {"id": "MUT-EVT-SKIP-COLLISION", "sdks": ["php", "dart"], "fault": "event_id collision overwrites payload"},
            {"id": "MUT-EVT-SKIP-VERSION", "sdks": ["php", "dart"], "fault": "expected-version check removed"},
            {"id": "MUT-JCS-SKIP-SORT", "sdks": ["php", "dart"], "fault": "object members not UTF-16 sorted"},
            {"id": "MUT-SCN-RELAX-DEPTH", "sdks": ["php", "dart"], "fault": "expression depth limit removed"},
            {"id": "MUT-OPS-RELAX-BAGGAGE", "sdks": ["php", "dart"], "fault": "baggage item cap raised"},
            {"id": "MUT-PKG-EXPORT-TESTKIT", "sdks": ["php", "dart"], "fault": "testkit identity treated as stable"},
        ],
        "schedules": [
            {"id": "SCH-TKN-CONCURRENT", "owner_item": "P8.4", "family": "replay-consume"},
            {"id": "SCH-IDC-TAKEOVER", "owner_item": "P8.4", "family": "lease-fence"},
            {"id": "SCH-EVT-CONCURRENT", "owner_item": "P8.4", "family": "event-append"},
        ],
        "properties": [
            {"id": "PROP-JCS-IDEMPOTENCE", "predicate": "canonicalize twice yields identical bytes"},
            {"id": "PROP-JCS-RAW-REJECT", "predicate": "raw-invalid input rejected before permissive decode"},
            {"id": "PROP-CNF-BINDING", "predicate": "mismatched principal/digest/args refuse commit"},
            {"id": "PROP-CNF-TTL", "predicate": "expired token refused with trusted clock"},
            {"id": "PROP-TKN-ONEBIT", "predicate": "one-bit MAC/payload mutation refuses parse"},
            {"id": "PROP-TKN-REPLAY", "predicate": "second consume of same tenant/jti is spent"},
            {"id": "PROP-LIM-BOUNDARY", "predicate": "limit-1 accept, limit accept, limit+1 reject"},
            {"id": "PROP-LIM-BAGGAGE", "predicate": "65th opaque id or oversized join is budget error"},
            {"id": "PROP-STATE-ILLEGAL", "predicate": "illegal confirmation/lease/event transition refused"},
        ],
        "corpora": [
            {"id": "CRP-JCS-TYPED", "domain": "typed-valid"},
            {"id": "CRP-JCS-RAW", "domain": "raw-invalid"},
            {"id": "CRP-TKN-HOSTILE", "domain": "token-bytes"},
            {"id": "CRP-LIM-BOUNDARY", "domain": "resource-limits"},
            {"id": "CRP-STATE", "domain": "state-machine"},
            {"id": "CRP-JCS-10K", "domain": "extended-es6-numbers"},
            {"id": "CRP-SEM-213", "domain": "extended-behavior-corpus"},
        ],
    }


def rows() -> list[dict[str, Any]]:
    php_dart = ["php:src", "dart:lib"]
    rc_mut = "plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P8-mutation-baseline.json"
    rc_fuzz = "plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P8-property-fuzz.json"
    rc_sched = "plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P8-postgres-schedules.json"
    rc_res = "plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P8-resource-baseline.json"
    rc_pkg = "plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P7-package-gate.json"
    all_tiers = ["focused", "bounded", "extended", "release-candidate"]
    return [
        row("AV-001", owner="P2", upstream_ref="G-CNF-001", criticality="critical", surfaces=["php:ConfirmationService", "dart:ConfirmationService"], oracle={"id": "ORC-CNF-FIXTURE", "kind": "fixture"}, proof_classes=["example", "property", "mutation"], mutants=["MUT-CNF-AUTH-BYPASS"], schedules=[], properties=["PROP-CNF-BINDING"], corpora=["CRP-STATE"], tiers=all_tiers, resource_link="RL-010", failure_owner="P2", red_proof="MUT-CNF-AUTH-BYPASS", rc_carrier=rc_mut),
        row("AV-002", owner="P2", upstream_ref="G-CNF-002", criticality="critical", surfaces=["php:ConfirmationService", "dart:ConfirmationService"], oracle={"id": "ORC-CNF-FIXTURE", "kind": "fixture"}, proof_classes=["example", "property", "mutation"], mutants=["MUT-CNF-CLOCK-FORGE"], schedules=[], properties=["PROP-CNF-TTL"], corpora=["CRP-STATE"], tiers=all_tiers, resource_link="RL-017", failure_owner="P2", red_proof="MUT-CNF-CLOCK-FORGE", rc_carrier=rc_mut),
        row("AV-003", owner="P3", upstream_ref="G-TKN-001", criticality="critical", surfaces=["php:TokenEnvelope", "dart:TokenEnvelope"], oracle={"id": "ORC-TKN-FIXTURE", "kind": "fixture"}, proof_classes=["example", "property", "differential", "mutation"], mutants=["MUT-TKN-SKIP-VERIFY"], schedules=[], properties=["PROP-TKN-ONEBIT"], corpora=["CRP-TKN-HOSTILE"], tiers=all_tiers, resource_link="RL-008", failure_owner="P3", red_proof="MUT-TKN-SKIP-VERIFY", rc_carrier=rc_mut, random=RANDOM_BOUNDED),
        row("AV-004", owner="P3", upstream_ref="G-TKN-003", criticality="critical", surfaces=["php:ConfirmationTokenStore", "dart:ConfirmationTokenStore"], oracle={"id": "ORC-TKN-FIXTURE", "kind": "fixture"}, proof_classes=["example", "property", "mutation", "schedule"], mutants=["MUT-TKN-SKIP-CONSUME"], schedules=["SCH-TKN-CONCURRENT"], properties=["PROP-TKN-REPLAY"], corpora=["CRP-STATE"], tiers=all_tiers, resource_link="RL-024", failure_owner="P3", red_proof="MUT-TKN-SKIP-CONSUME", rc_carrier=rc_mut),
        row("AV-005", owner="P4", upstream_ref="G-IDC-002", criticality="critical", surfaces=["php:IdempotencyCoordinator", "dart:IdempotencyCoordinator"], oracle={"id": "ORC-IDC-FIXTURE", "kind": "fixture"}, proof_classes=["example", "mutation", "schedule"], mutants=["MUT-IDC-STALE-FENCE", "MUT-IDC-UNKNOWN-ALLOW"], schedules=["SCH-IDC-TAKEOVER"], properties=[], corpora=["CRP-STATE"], tiers=all_tiers, resource_link="RL-021", failure_owner="P4", red_proof="MUT-IDC-STALE-FENCE", rc_carrier=rc_mut),
        row("AV-006", owner="P5", upstream_ref="G-EVT-002", criticality="critical", surfaces=["php:EventStore", "dart:EventStore"], oracle={"id": "ORC-EVT-FIXTURE", "kind": "fixture"}, proof_classes=["example", "mutation", "schedule"], mutants=["MUT-EVT-SKIP-COLLISION", "MUT-EVT-SKIP-VERSION"], schedules=["SCH-EVT-CONCURRENT"], properties=["PROP-STATE-ILLEGAL"], corpora=["CRP-STATE"], tiers=all_tiers, resource_link="RL-020", failure_owner="P5", red_proof="MUT-EVT-SKIP-COLLISION", rc_carrier=rc_mut),
        row("AV-007", owner="P6", upstream_ref="G-JCS-001", criticality="critical", surfaces=["php:Canonicalizer", "dart:Canonicalizer"], oracle={"id": "ORC-JCS-P6", "kind": "independent"}, proof_classes=["example", "property", "differential", "mutation"], mutants=["MUT-JCS-SKIP-SORT"], schedules=[], properties=["PROP-JCS-IDEMPOTENCE", "PROP-JCS-RAW-REJECT"], corpora=["CRP-JCS-TYPED", "CRP-JCS-RAW", "CRP-JCS-10K"], tiers=all_tiers, resource_link=None, failure_owner="P6", red_proof="MUT-JCS-SKIP-SORT", rc_carrier=rc_fuzz, random=RANDOM_EXTENDED),
        row("AV-008", owner="P8", upstream_ref="G-SCN-001", criticality="critical", surfaces=["php:ExpressionEvaluator", "dart:ExpressionEvaluator"], oracle={"id": "ORC-SEM-P6", "kind": "independent"}, proof_classes=["example", "property", "mutation", "resource"], mutants=["MUT-SCN-RELAX-DEPTH"], schedules=[], properties=["PROP-LIM-BOUNDARY"], corpora=["CRP-LIM-BOUNDARY"], tiers=all_tiers, resource_link="RL-001", failure_owner="P8", red_proof="MUT-SCN-RELAX-DEPTH", rc_carrier=rc_mut, random=RANDOM_BOUNDED),
        row("AV-009", owner="P8", upstream_ref="G-OPS-001", criticality="critical", surfaces=["php:BaggageRefs", "dart:BaggageRefs"], oracle={"id": "ORC-SEM-P6", "kind": "independent"}, proof_classes=["example", "property", "mutation", "resource"], mutants=["MUT-OPS-RELAX-BAGGAGE"], schedules=[], properties=["PROP-LIM-BAGGAGE", "PROP-LIM-BOUNDARY"], corpora=["CRP-LIM-BOUNDARY"], tiers=all_tiers, resource_link="RL-006", failure_owner="P8", red_proof="MUT-OPS-RELAX-BAGGAGE", rc_carrier=rc_mut, random=RANDOM_BOUNDED),
        row("AV-010", owner="P7", upstream_ref="G-PKG-001", criticality="critical", surfaces=["php:archive", "dart:public-library"], oracle={"id": "ORC-PKG-SURFACE", "kind": "independent"}, proof_classes=["example", "mutation"], mutants=["MUT-PKG-EXPORT-TESTKIT"], schedules=[], properties=[], corpora=[], tiers=all_tiers, resource_link=None, failure_owner="P7", red_proof="MUT-PKG-EXPORT-TESTKIT", rc_carrier=rc_pkg),
        row("AV-011", owner="P8", upstream_ref="G-ADV-001", criticality="critical", surfaces=php_dart, oracle={"id": "ORC-ADV-STRUCT", "kind": "independent"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=all_tiers, resource_link=None, failure_owner="P8", red_proof="ORC-ADV-STRUCT", rc_carrier="fixtures/hardening/adversarial/manifest.v1.json"),
        row("AV-012", owner="P3", upstream_ref="G-TKN-002", criticality="ordinary", surfaces=["php:InMemoryConfirmationTokenStore", "dart:InMemoryConfirmationTokenStore"], oracle={"id": "ORC-TKN-FIXTURE", "kind": "fixture"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["focused", "bounded"], resource_link="RL-024", failure_owner="P7", red_proof=None, rc_carrier=rc_fuzz),
        row("AV-013", owner="P4", upstream_ref="G-IDC-001", criticality="ordinary", surfaces=["php:IdempotencyStore", "dart:IdempotencyStore"], oracle={"id": "ORC-IDC-FIXTURE", "kind": "fixture"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["focused", "bounded"], resource_link="RL-015", failure_owner="P4", red_proof=None, rc_carrier=rc_fuzz),
        row("AV-014", owner="P5", upstream_ref="G-EVT-001", criticality="ordinary", surfaces=["php:EventStore", "dart:EventStore"], oracle={"id": "ORC-EVT-FIXTURE", "kind": "fixture"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["focused", "bounded"], resource_link="RL-011", failure_owner="P5", red_proof=None, rc_carrier=rc_fuzz),
        row("AV-015", owner="P6", upstream_ref="G-SEM-001", criticality="ordinary", surfaces=php_dart, oracle={"id": "ORC-SEM-P6", "kind": "independent"}, proof_classes=["example", "differential"], mutants=[], schedules=[], properties=[], corpora=["CRP-SEM-213"], tiers=["bounded", "extended", "release-candidate"], resource_link=None, failure_owner="P6", red_proof=None, rc_carrier=rc_fuzz),
        row("AV-016", owner="P6", upstream_ref="G-SCP-001", criticality="ordinary", surfaces=["conformance:applicability"], oracle={"id": "ORC-SEM-P6", "kind": "independent"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["focused", "release-candidate"], resource_link=None, failure_owner="P6", red_proof=None, rc_carrier="conformance/reports/p6-integrated.json"),
        row("AV-017", owner="P1", upstream_ref="G-OBS-001", criticality="informational", surfaces=["plans:bookkeeping"], oracle={"id": "ORC-ADV-STRUCT", "kind": "structural"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["focused"], resource_link=None, failure_owner="P10", red_proof=None, rc_carrier="plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/threat-model-v1.md"),
        row("AV-018", owner="P8", upstream_ref="RL-001", criticality="ordinary", surfaces=["php:ExpressionLimits", "dart:ExpressionLimits"], oracle={"id": "ORC-SEM-P6", "kind": "independent"}, proof_classes=["property", "resource"], mutants=[], schedules=[], properties=["PROP-LIM-BOUNDARY"], corpora=["CRP-LIM-BOUNDARY"], tiers=["focused", "bounded", "release-candidate"], resource_link="RL-001", failure_owner="P8", red_proof=None, rc_carrier=rc_res, random=RANDOM_BOUNDED),
        row("AV-019", owner="P9", upstream_ref="G-CI-001", criticality="ordinary", surfaces=["ci:trust-plane"], oracle={"id": "ORC-ADV-STRUCT", "kind": "structural"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["release-candidate"], resource_link=None, failure_owner="P9", red_proof=None, rc_carrier="plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/ci-supply-chain-v1.md"),
        row("AV-020", owner="P10", upstream_ref="G-REL-001", criticality="ordinary", surfaces=["release:dossier"], oracle={"id": "ORC-ADV-STRUCT", "kind": "structural"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["release-candidate"], resource_link=None, failure_owner="P10", red_proof=None, rc_carrier="plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/release-acceptance-v1.md"),
        row("AV-021", owner="P8", upstream_ref="NG-AVL-001", criticality="informational", surfaces=php_dart, oracle={"id": "ORC-ADV-STRUCT", "kind": "structural"}, proof_classes=["resource"], mutants=[], schedules=[], properties=[], corpora=[], tiers=["extended"], resource_link="RL-022", failure_owner="P8", red_proof=None, rc_carrier=rc_res),
        row("AV-022", owner="P8", upstream_ref="F10", criticality="ordinary", surfaces=php_dart, oracle={"id": "ORC-ADV-STRUCT", "kind": "independent"}, proof_classes=["example"], mutants=[], schedules=[], properties=[], corpora=[], tiers=all_tiers, resource_link=None, failure_owner="P8", red_proof=None, rc_carrier="fixtures/hardening/adversarial/manifest.v1.json"),
    ]


def dispositions() -> list[dict[str, str]]:
    covered = [
        ("G-CNF-001", "AV-001"),
        ("G-CNF-002", "AV-002"),
        ("G-TKN-001", "AV-003"),
        ("G-TKN-002", "AV-012"),
        ("G-TKN-003", "AV-004"),
        ("G-IDC-001", "AV-013"),
        ("G-IDC-002", "AV-005"),
        ("G-EVT-001", "AV-014"),
        ("G-EVT-002", "AV-006"),
        ("G-SCN-001", "AV-008"),
        ("G-OPS-001", "AV-009"),
        ("G-SEM-001", "AV-015"),
        ("G-JCS-001", "AV-007"),
        ("G-SCP-001", "AV-016"),
        ("G-PKG-001", "AV-010"),
        ("G-ADV-001", "AV-011"),
        ("G-OBS-001", "AV-017"),
        ("F10", "AV-022"),
        ("RL-001", "AV-008"),
        ("RL-002", "AV-018"),
        ("RL-003", "AV-018"),
        ("RL-004", "AV-018"),
        ("RL-005", "AV-008"),
        ("RL-006", "AV-009"),
        ("RL-007", "AV-009"),
    ]
    items = [{"subject": subject, "kind": "covered", "av": av} for subject, av in covered]
    for ng in nonguarantee_ids():
        if ng == "NG-AVL-001":
            items.append({"subject": ng, "kind": "covered", "av": "AV-021"})
        else:
            items.append({"subject": ng, "kind": "non-guarantee", "av": ""})
    items.extend(
        [
            {"subject": "G-CI-001", "kind": "deferred", "av": "AV-019"},
            {"subject": "G-REL-001", "kind": "deferred", "av": "AV-020"},
            {"subject": "RL-008", "kind": "deferred", "av": "AV-003"},
            {"subject": "RL-009", "kind": "deferred", "av": "AV-003"},
            {"subject": "RL-010", "kind": "deferred", "av": "AV-001"},
            {"subject": "RL-011", "kind": "deferred", "av": "AV-014"},
            {"subject": "RL-012", "kind": "deferred", "av": "AV-006"},
            {"subject": "RL-013", "kind": "deferred", "av": "AV-006"},
            {"subject": "RL-014", "kind": "deferred", "av": "AV-006"},
            {"subject": "RL-015", "kind": "deferred", "av": "AV-013"},
            {"subject": "RL-016", "kind": "deferred", "av": "AV-013"},
            {"subject": "RL-017", "kind": "deferred", "av": "AV-002"},
            {"subject": "RL-018", "kind": "deferred", "av": "AV-003"},
            {"subject": "RL-019", "kind": "deferred", "av": "AV-004"},
            {"subject": "RL-020", "kind": "deferred", "av": "AV-006"},
            {"subject": "RL-021", "kind": "deferred", "av": "AV-005"},
            {"subject": "RL-022", "kind": "deferred", "av": "AV-021"},
            {"subject": "RL-023", "kind": "deferred", "av": "AV-006"},
            {"subject": "RL-024", "kind": "deferred", "av": "AV-004"},
            {"subject": "RL-025", "kind": "deferred", "av": "AV-006"},
            {"subject": "RES-P2-DURABLE-CONSUME", "kind": "upstream-closed", "av": "AV-004"},
            {"subject": "RES-P2-JOURNAL", "kind": "upstream-closed", "av": "AV-006"},
            {"subject": "RES-P2-ERROR-REGISTRY", "kind": "upstream-closed", "av": "AV-015"},
            {"subject": "RES-P2-EXPERT-API", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P3-STORE-DOCS", "kind": "deferred", "av": "AV-020"},
            {"subject": "RES-P3-INMEMORY", "kind": "non-guarantee", "av": "AV-012"},
            {"subject": "RES-P3-CI-PG", "kind": "deferred", "av": "AV-019"},
            {"subject": "RES-P4-EPOCH", "kind": "upstream-closed", "av": "AV-006"},
            {"subject": "RES-P4-ERROR-REGISTRY", "kind": "upstream-closed", "av": "AV-015"},
            {"subject": "RES-P4-PACKAGING", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P4-MUTATION-CI", "kind": "covered", "av": "AV-005"},
            {"subject": "RES-P5-ERROR-REGISTRY", "kind": "upstream-closed", "av": "AV-015"},
            {"subject": "RES-P5-PACKAGING", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P5-MUTATION", "kind": "covered", "av": "AV-006"},
            {"subject": "RES-P5-CI-PG", "kind": "deferred", "av": "AV-019"},
            {"subject": "RES-P6-TAG", "kind": "deferred", "av": "AV-020"},
            {"subject": "RES-P6-PACKAGE", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P6-JCS-10K", "kind": "covered", "av": "AV-007"},
            {"subject": "RES-P6-DART-PG", "kind": "deferred", "av": "AV-019"},
            {"subject": "RES-P6-MCP-MRTR", "kind": "not-applicable", "av": "AV-016"},
            {"subject": "RES-P6-BEHAVIOR-213", "kind": "covered", "av": "AV-015"},
            {"subject": "RES-P6-D5", "kind": "deferred", "av": "AV-020"},
            {"subject": "RES-P7-PHP-TESTKIT", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P7-DART-TESTKIT", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P7-MCP-PATH", "kind": "deferred", "av": "AV-019"},
            {"subject": "RES-P7-DART-CONF", "kind": "upstream-closed", "av": "AV-010"},
            {"subject": "RES-P7-PUBLISH", "kind": "deferred", "av": "AV-020"},
        ]
    )
    items.sort(key=lambda item: item["subject"])
    return items


def residual_subjects() -> list[str]:
    return list(FROZEN_RESIDUALS)


def build_manifest() -> dict[str, Any]:
    catalog = catalog_entries()
    return {
        "schema": SCHEMA,
        "version": "v1",
        "generator_version": GENERATOR_VERSION,
        "source_digests": source_digests(),
        "proof_classes": list(PROOF_CLASSES),
        "tiers": list(TIERS),
        "criticality": list(CRITICALITY),
        "catalog": catalog,
        "rows": rows(),
        "dispositions": dispositions(),
    }


def referenced_ids(manifest: dict[str, Any]) -> dict[str, set[str]]:
    used = {
        "oracles": set(),
        "mutants": set(),
        "schedules": set(),
        "properties": set(),
        "corpora": set(),
    }
    for item in manifest["rows"]:
        used["oracles"].add(item["oracle"]["id"])
        for key in ("mutants", "schedules", "properties", "corpora"):
            used[key].update(item[key])
        red = item.get("red_proof")
        if isinstance(red, str) and red.startswith("MUT-"):
            used["mutants"].add(red)
        if isinstance(red, str) and red.startswith("SCH-"):
            used["schedules"].add(red)
        if isinstance(red, str) and red.startswith("ORC-"):
            used["oracles"].add(red)
    return used


def catalog_ids(manifest: dict[str, Any], kind: str) -> set[str]:
    return {entry["id"] for entry in manifest["catalog"][kind]}


def validate_manifest(manifest: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if manifest.get("schema") != SCHEMA:
        errors.append("unknown schema")
    rows_list = manifest.get("rows") or []
    ids = [item.get("id") for item in rows_list]
    if ids != sorted(ids):
        errors.append("AV ids are not sorted")
    if len(ids) != len(set(ids)):
        errors.append("duplicate AV id")
    if any(not re.fullmatch(r"AV-\d{3}", str(item_id)) for item_id in ids):
        errors.append("malformed AV id")

    for item in rows_list:
        if item.get("upstream_owner") not in OWNER_PHASES and item.get("upstream_owner") not in {"P1"}:
            errors.append(f"{item.get('id')}: unknown owner")
        if item.get("failure_owner") not in OWNER_PHASES:
            errors.append(f"{item.get('id')}: unknown failure owner")
        if item.get("criticality") not in CRITICALITY:
            errors.append(f"{item.get('id')}: unknown criticality")
        for proof in item.get("proof_classes") or []:
            if proof not in PROOF_CLASSES:
                errors.append(f"{item.get('id')}: unknown proof class {proof}")
        for tier in item.get("tiers") or []:
            if tier not in TIERS:
                errors.append(f"{item.get('id')}: unknown tier {tier}")
        oracle = item.get("oracle") or {}
        if oracle.get("kind") not in ORACLE_KINDS:
            errors.append(f"{item.get('id')}: unknown oracle kind")
        if item.get("criticality") == "critical":
            if oracle.get("kind") not in {"independent", "external", "fixture"} and oracle.get("id") != "ORC-ADV-STRUCT":
                errors.append(f"{item.get('id')}: critical row lacks independent/external oracle")
            if not item.get("red_proof"):
                errors.append(f"{item.get('id')}: critical row lacks red proof")
            if "release-candidate" not in (item.get("tiers") or []):
                errors.append(f"{item.get('id')}: critical row missing release-candidate carrier tier")
            if not item.get("rc_carrier"):
                errors.append(f"{item.get('id')}: critical row missing rc_carrier")
        if "random" in item:
            random = item["random"]
            for key in ("algorithm", "generator_version", "seeds", "case_budget", "promotion"):
                if key not in random:
                    errors.append(f"{item.get('id')}: random missing {key}")
            seeds = random.get("seeds") or []
            budget = random.get("case_budget")
            if not seeds or not isinstance(budget, int) or budget <= 0:
                errors.append(f"{item.get('id')}: unbounded random row")
        generated = set(item.get("corpora") or []) & GENERATED_CORPORA
        if generated and "random" not in item:
            errors.append(f"{item.get('id')}: generated property row missing replay metadata")

    used = referenced_ids(manifest)
    for kind in ("oracles", "mutants", "schedules", "properties", "corpora"):
        known = catalog_ids(manifest, kind)
        dangling = used[kind] - known
        orphans = known - used[kind]
        if dangling:
            errors.append(f"dangling {kind}: {sorted(dangling)}")
        if orphans:
            errors.append(f"orphan {kind}: {sorted(orphans)}")

    disp = manifest.get("dispositions") or []
    subjects = [item.get("subject") for item in disp]
    if len(subjects) != len(set(subjects)):
        errors.append("duplicate disposition subject")
    for item in disp:
        if item.get("kind") not in DISPOSITION_KINDS:
            errors.append(f"unknown disposition kind for {item.get('subject')}")
        av = item.get("av") or ""
        if av and av not in ids:
            errors.append(f"disposition {item.get('subject')} dangles {av}")

    required = set(guarantee_ids()) | set(nonguarantee_ids()) | set(resource_ids()) | set(residual_subjects())
    actual = set(subjects)
    missing = sorted(required - actual)
    extra = sorted(actual - required)
    if missing:
        errors.append(f"missing dispositions: {missing}")
    if extra:
        errors.append(f"extra dispositions: {extra}")
    return errors


def emit(path: Path = MANIFEST) -> dict[str, Any]:
    manifest = build_manifest()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(canonical_json(manifest), encoding="utf-8")
    return manifest


def load_manifest(path: Path = MANIFEST) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def validate_disk() -> list[str]:
    errors: list[str] = []
    if not CONTRACT.is_file():
        errors.append("missing contract markdown")
    expected = canonical_json(build_manifest())
    if not MANIFEST.is_file():
        errors.append("missing manifest")
        return errors
    actual = MANIFEST.read_text(encoding="utf-8")
    if actual != expected:
        errors.append("manifest is not canonical emitted JSON")
    errors.extend(validate_manifest(load_manifest()))
    return errors


class AdversarialContractTests(unittest.TestCase):
    def test_positive_manifest(self) -> None:
        errors = validate_manifest(build_manifest())
        self.assertEqual(errors, [])

    def test_duplicate_id_is_red(self) -> None:
        manifest = build_manifest()
        manifest["rows"].append(deepcopy(manifest["rows"][0]))
        self.assertTrue(any("duplicate" in error for error in validate_manifest(manifest)))

    def test_missing_owner_is_red(self) -> None:
        manifest = build_manifest()
        manifest["rows"][0]["upstream_owner"] = "PX"
        self.assertTrue(any("unknown owner" in error for error in validate_manifest(manifest)))

    def test_missing_independent_oracle_is_red(self) -> None:
        manifest = build_manifest()
        critical = next(item for item in manifest["rows"] if item["id"] == "AV-001")
        critical["oracle"] = {"id": "ORC-ADV-STRUCT", "kind": "structural"}
        critical["red_proof"] = None
        errors = validate_manifest(manifest)
        self.assertTrue(any("independent/external" in error or "red proof" in error for error in errors))

    def test_unbounded_random_row_is_red(self) -> None:
        manifest = build_manifest()
        random_row = next(item for item in manifest["rows"] if "random" in item)
        random_row["random"] = {"algorithm": "sha256-counter"}
        self.assertTrue(any("unbounded" in error or "random missing" in error for error in validate_manifest(manifest)))

    def test_unknown_tier_is_red(self) -> None:
        manifest = build_manifest()
        manifest["rows"][0]["tiers"] = ["nightly"]
        self.assertTrue(any("unknown tier" in error for error in validate_manifest(manifest)))

    def test_orphan_proof_is_red(self) -> None:
        manifest = build_manifest()
        manifest["catalog"]["mutants"].append({"id": "MUT-ORPHAN", "sdks": ["php"], "fault": "unused"})
        self.assertTrue(any("orphan mutants" in error for error in validate_manifest(manifest)))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", default="validate", choices=("validate", "emit", "test"))
    args = parser.parse_args(argv)
    if args.command == "emit":
        emit()
        print(f"wrote {MANIFEST.relative_to(REPO)}")
        return 0
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(AdversarialContractTests)
        result = unittest.TextTestRunner(verbosity=1).run(suite)
        return 0 if result.wasSuccessful() else 1
    errors = validate_disk()
    if errors:
        for error in errors:
            print(error, file=sys.stderr)
        return 1
    manifest = load_manifest()
    print(f"adversarial-verification v1 ok ({len(manifest['rows'])} AV, {len(manifest['dispositions'])} dispositions)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
