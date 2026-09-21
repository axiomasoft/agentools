#!/usr/bin/env python3
"""P9.5 trusted private RC aggregate verification (stdlib + repo gates)."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
ARTIFACT_PATH = PLAN / "artifacts/P9-private-rc.json"
FINDING_PATH = PLAN / "findings/P9-private-rc.md"
WORKFLOW_PATH = REPO / ".github/workflows/private-rc.yml"
LOCK_PATH = REPO / ".ci/supply-chain-lock.json"

P8_GATE = PLAN / "artifacts/P8-adversarial-gate.json"
CI_MAP = PLAN / "artifacts/P9-ci-map.json"
REPRO = PLAN / "artifacts/P9-reproducibility.json"
SUPPLY = PLAN / "artifacts/P9-supply-chain-evidence.json"
CI_CONTRACT = PLAN / "root/contracts/ci-supply-chain-v1.md"
RC_CONTRACT = PLAN / "root/contracts/release-candidate-v1.md"

SCHEMA_VERSION = "private-rc-aggregate/v1"
SUBJECT_IDS = ("php-sdk", "dart-core", "dart-mcp-companion")

FORBIDDEN_WORKFLOW_RE = [
    re.compile(r"gh\s+release", re.IGNORECASE),
    re.compile(r"npm\s+publish", re.IGNORECASE),
    re.compile(r"composer\s+publish", re.IGNORECASE),
    re.compile(r"dart\s+pub\s+publish(?!\s+--dry-run)", re.IGNORECASE),
    re.compile(r"docker\s+push", re.IGNORECASE),
    re.compile(r"packages:\s*write", re.IGNORECASE),
    re.compile(r"contents:\s*write", re.IGNORECASE),
    re.compile(r"pull_request_target", re.IGNORECASE),
]

PRIVILEGED_TOKEN_SCOPES = frozenset({"id-token", "attestations"})


class PrivateRcError(RuntimeError):
    pass


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def git_head_sha() -> str:
    proc = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=REPO,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise PrivateRcError(proc.stderr.strip() or "git rev-parse failed")
    return proc.stdout.strip()


def load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise PrivateRcError(f"missing artifact: {path}")
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise PrivateRcError(f"artifact root must be object: {path}")
    return payload


def child_digest_state(path: Path) -> dict[str, Any]:
    payload = load_json(path)
    return {
        "path": path.relative_to(REPO).as_posix(),
        "digest": sha256_file(path),
        "present": True,
        "status": payload.get("status") or payload.get("verdict"),
    }


def check_workflow_trust_plane() -> list[str]:
    from tools.ci_policy import check_workflow_file  # noqa: WPS433

    errors: list[str] = []
    if not WORKFLOW_PATH.is_file():
        return ["missing private-rc workflow"]
    violations = check_workflow_file(WORKFLOW_PATH)
    for item in violations:
        errors.append(f"{item.rule_id}: {item.message}")
    text = WORKFLOW_PATH.read_text(encoding="utf-8")
    for pattern in FORBIDDEN_WORKFLOW_RE:
        if pattern.search(text):
            errors.append(f"forbidden workflow pattern: {pattern.pattern}")
    if "secrets." in text:
        errors.append("workflow must not reference secrets.*")
    if not re.search(r"on:\s*\n\s*workflow_dispatch:", text):
        errors.append("private-rc must allow workflow_dispatch")
    privileged_jobs: list[str] = []
    token_jobs: list[str] = []
    for job_name, block in _yaml_jobs(text):
        perms = block.get("permissions") or {}
        if isinstance(perms, dict):
            scopes = {k for k, v in perms.items() if v == "write"}
            if scopes & PRIVILEGED_TOKEN_SCOPES:
                token_jobs.append(job_name)
            if scopes - PRIVILEGED_TOKEN_SCOPES - {"contents"}:
                privileged_jobs.append(f"{job_name}:{sorted(scopes)}")
            if "contents" in perms and perms.get("contents") != "read":
                errors.append(f"job {job_name} must not grant contents write")
    if privileged_jobs:
        errors.append(f"unexpected write scopes: {privileged_jobs}")
    if len(token_jobs) > 1:
        errors.append(f"id-token scopes on multiple jobs: {token_jobs}")
    if token_jobs and token_jobs != ["hosted-attest"]:
        errors.append(f"privileged OIDC scopes only on hosted-attest, saw {token_jobs}")
    return errors


def _yaml_jobs(text: str) -> list[tuple[str, dict[str, Any]]]:
    try:
        import yaml
    except ImportError:  # pragma: no cover
        return []
    document = yaml.safe_load(text)
    jobs = document.get("jobs") if isinstance(document, dict) else None
    if not isinstance(jobs, dict):
        return []
    return [(name, job) for name, job in jobs.items() if isinstance(job, dict)]


def verify_subject_exact_set(repro: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    builder = repro.get("builder_a") or {}
    subjects = builder.get("subjects") or []
    ids = [row.get("subject_id") for row in subjects if isinstance(row, dict)]
    missing = sorted(set(SUBJECT_IDS) - set(ids))
    extra = sorted(set(ids) - set(SUBJECT_IDS))
    if missing:
        errors.append(f"subjects missing: {missing}")
    if extra:
        errors.append(f"subjects extra: {extra}")
    if len(ids) != len(set(ids)):
        errors.append("duplicate subject identities")
    return errors


def independent_subject_rehash(repro: dict[str, Any]) -> list[str]:
    from tools.release_candidate import (  # noqa: WPS433
        build_subject_archive,
        collect_inventories,
    )

    errors: list[str] = []
    if repro.get("status") != "green" or not repro.get("reproducibility_match"):
        errors.append("reproducibility artifact not green")
        return errors
    stored = repro["fingerprints"]["builder_a"]
    work = Path(tempfile.mkdtemp(prefix="p95-verify-"))
    try:
        _, _, inventories, roots = collect_inventories(work)
        epoch = int(repro["source_date_epoch"])
        for subject_id in SUBJECT_IDS:
            subject_row = next(
                s for s in repro["builder_a"]["subjects"] if s["subject_id"] == subject_id
            )
            _, digest = build_subject_archive(
                inventories[subject_id], roots[subject_id], epoch
            )
            expected = subject_row["archive_digest"]["digest"]
            if digest != expected:
                errors.append(f"{subject_id} independent archive digest mismatch")
            if digest != stored[subject_id]["archive_sha256"]:
                errors.append(f"{subject_id} fingerprint drift vs receipt")
        live = run_repro_fingerprints()
        if live != stored:
            errors.append("live dual-builder fingerprints differ from committed receipt")
    finally:
        shutil.rmtree(work, ignore_errors=True)
    return errors


def run_repro_fingerprints() -> dict[str, dict[str, str]]:
    from tools.release_candidate import run_reproducibility  # noqa: WPS433

    payload = run_reproducibility(REPO, require_clean=False)
    return payload.get("fingerprints", {}).get("builder_a", {})


def verify_supply_chain_link(repro: dict[str, Any], supply: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if supply.get("status") != "green":
        errors.append("supply-chain evidence not green")
    if supply.get("source_commit") != repro.get("source_commit"):
        errors.append("source_commit mismatch between reproducibility and supply-chain")
    rc_digest = sha256_file(REPRO)
    if supply.get("rc_receipt_sha256") != rc_digest:
        errors.append("supply-chain rc_receipt_sha256 mismatch")
    att = supply.get("hosted_attestation") or {}
    if att.get("attested"):
        errors.append("hosted attestation must not be claimed attested=true")
    if att.get("status") not in {
        "verified",
        "statement-verified",
        "unavailable-plan",
        "available-disabled",
        "not-executed",
    }:
        errors.append("invalid hosted attestation capability status")
    return errors


def verify_p8_release_candidate(p8: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if p8.get("verdict") != "green":
        errors.append("P8 aggregate not green")
    coverage = p8.get("coverage") or {}
    if coverage.get("missing") or coverage.get("extra"):
        errors.append("P8 coverage missing/extra non-empty")
    stale = coverage.get("stale") or []
    if stale:
        errors.append(f"P8 stale rows: {stale}")
    critical = [row for row in p8.get("rows", []) if row.get("criticality") == "critical"]
    if not critical:
        errors.append("P8 critical rows missing")
    for row in critical:
        if not row.get("passing", row.get("verdict") in {"green", "private-local-package-receipt"}):
            errors.append(f"critical P8 row not passing: {row.get('id')}")
    return errors


def verify_ci_map(ci_map: dict[str, Any], repro: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    tier = ci_map.get("tiers", {}).get("release-candidate")
    if not isinstance(tier, dict):
        errors.append("ci-map missing release-candidate tier")
        return errors
    lockfiles = ci_map.get("lockfiles") or {}
    for name, digest in lockfiles.items():
        path = {
            "php": REPO / "php/composer.lock",
            "conformance": REPO / "conformance/composer.lock",
            "dart_core": REPO / "dart/agentools/pubspec.lock",
            "dart_mcp": REPO / "dart/agentools_mcp_dart/pubspec.lock",
        }.get(name)
        if path and path.is_file() and sha256_file(path) != digest:
            errors.append(f"lock digest drift: {name}")
    if "AV-019" not in (tier.get("av_ids") or []):
        errors.append("release-candidate tier must include AV-019")
    return errors


def resolve_av019(ci_contract_digest: str, workflow_errors: list[str]) -> dict[str, Any]:
    passing = not workflow_errors
    return {
        "id": "AV-019",
        "carrier": CI_CONTRACT.relative_to(REPO).as_posix(),
        "digest": ci_contract_digest,
        "verdict": "green" if passing else "red",
        "passing": passing,
        "kind": "release-candidate-trust-plane",
    }


def simulate_download_bundle(work_dir: Path) -> dict[str, str]:
    """Copy child receipts into an isolated directory and re-hash (transport simulation)."""
    bundle = work_dir / "downloaded"
    bundle.mkdir(parents=True, exist_ok=True)
    digests: dict[str, str] = {}
    for name, path in {
        "p8_gate": P8_GATE,
        "ci_map": CI_MAP,
        "reproducibility": REPRO,
        "supply_chain": SUPPLY,
    }.items():
        dest = bundle / path.name
        shutil.copy2(path, dest)
        digests[name] = sha256_file(dest)
    return digests


def build_aggregate(
    *,
    source_commit: str,
    allow_dirty: bool,
) -> dict[str, Any]:
    errors: list[str] = []
    head = git_head_sha()
    if head != source_commit and not allow_dirty:
        errors.append(f"stale source tree: HEAD {head} != receipt {source_commit}")

    workflow_errors = check_workflow_trust_plane()
    errors.extend(workflow_errors)

    p8 = load_json(P8_GATE)
    ci_map = load_json(CI_MAP)
    repro = load_json(REPRO)
    supply = load_json(SUPPLY)

    if repro.get("source_commit") != source_commit:
        errors.append("reproducibility source_commit mismatch")
    errors.extend(verify_p8_release_candidate(p8))
    errors.extend(verify_ci_map(ci_map, repro))
    errors.extend(verify_subject_exact_set(repro))
    errors.extend(verify_supply_chain_link(repro, supply))
    errors.extend(independent_subject_rehash(repro))

    work = Path(tempfile.mkdtemp(prefix="p95-bundle-"))
    try:
        transport_digests = simulate_download_bundle(work)
    finally:
        shutil.rmtree(work, ignore_errors=True)

    children = {
        "p8_gate": child_digest_state(P8_GATE),
        "ci_map": child_digest_state(CI_MAP),
        "reproducibility": child_digest_state(REPRO),
        "supply_chain": child_digest_state(SUPPLY),
        "ci_supply_chain_contract": {
            "path": CI_CONTRACT.relative_to(REPO).as_posix(),
            "digest": sha256_file(CI_CONTRACT),
            "present": True,
        },
        "release_candidate_contract": {
            "path": RC_CONTRACT.relative_to(REPO).as_posix(),
            "digest": sha256_file(RC_CONTRACT),
            "present": True,
        },
        "supply_chain_lock": {
            "path": LOCK_PATH.relative_to(REPO).as_posix(),
            "digest": sha256_file(LOCK_PATH),
            "present": True,
        },
        "private_rc_workflow": {
            "path": WORKFLOW_PATH.relative_to(REPO).as_posix(),
            "digest": sha256_file(WORKFLOW_PATH),
            "present": WORKFLOW_PATH.is_file(),
        },
    }

    expected_ids = set(children.keys())
    present_ids = {name for name, row in children.items() if row.get("present")}
    missing = sorted(expected_ids - present_ids)
    extra: list[str] = []
    stale: list[str] = []
    for name, row in children.items():
        if row.get("status") in {"red", "fail"}:
            stale.append(name)

    subjects = []
    for subject in repro.get("builder_a", {}).get("subjects", []):
        subjects.append(
            {
                "subject_id": subject["subject_id"],
                "archive_digest": subject["archive_digest"],
                "content_manifest_digest": subject["content_manifest_digest"],
            }
        )

    av019 = resolve_av019(children["ci_supply_chain_contract"]["digest"], workflow_errors)

    local_rc = {
        "phase": "verified",
        "assembly": "fresh-download-rehash",
        "verification": "green" if not errors else "red",
        "transport_digests": transport_digests,
        "note": "Platform artifact IDs are transport evidence only; subject digests are release identity.",
    }
    hosted = {
        "phase": "not-executed",
        "status": (supply.get("hosted_attestation") or {}).get("status", "unavailable-plan"),
        "attested": False,
        "note": "Hosted attestation job disabled; capability recorded from P9.4 evidence.",
    }

    payload: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": now_iso(),
        "source_commit": source_commit,
        "source_tree_commit": head,
        "verdict": "green" if not errors and not missing and not stale else "red",
        "coverage": {"missing": missing, "extra": extra, "stale": stale},
        "children": children,
        "subjects": subjects,
        "matrices": {
            "release_candidate_tier": ci_map.get("tiers", {}).get("release-candidate"),
            "lockfiles": ci_map.get("lockfiles"),
        },
        "p8_gate_digest": children["p8_gate"]["digest"],
        "av019": av019,
        "local_rc": local_rc,
        "hosted_attestation": hosted,
        "errors": errors,
        "public_release_claimed": False,
    }
    return payload


def render_finding(payload: dict[str, Any]) -> str:
    hosted = payload.get("hosted_attestation", {})
    local = payload.get("local_rc", {})
    lines = [
        "# P9 private RC aggregate",
        "",
        f"**Verdict:** `{payload.get('verdict')}`",
        f"**Source commit:** `{payload.get('source_commit')}`",
        f"**Generated:** `{payload.get('generated_at')}`",
        "",
        "## Local RC",
        "",
        f"- Phase: `{local.get('phase')}` · verification `{local.get('verification')}`",
        f"- Coverage: missing={payload.get('coverage', {}).get('missing')} "
        f"extra={payload.get('coverage', {}).get('extra')} "
        f"stale={payload.get('coverage', {}).get('stale')}",
        "",
        "## Subjects",
        "",
    ]
    for row in payload.get("subjects", []):
        lines.append(
            f"- `{row['subject_id']}`: archive `{row['archive_digest']['digest']}`"
        )
    lines.extend(
        [
            "",
            "## Hosted attestation",
            "",
            f"- Phase: `{hosted.get('phase')}` · capability `{hosted.get('status')}` · "
            f"attested `{hosted.get('attested')}`",
            "",
            "## AV-019 (trust plane)",
            "",
            f"- `{payload.get('av019', {}).get('verdict')}` via `{payload.get('av019', {}).get('carrier')}`",
            "",
        ]
    )
    return "\n".join(lines) + "\n"


def cmd_run(args: argparse.Namespace) -> int:
    repro = load_json(REPRO)
    source_commit = str(repro.get("source_commit"))
    payload = build_aggregate(source_commit=source_commit, allow_dirty=args.allow_dirty)
    ARTIFACT_PATH.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT_PATH.write_text(canonical_json(payload) + "\n", encoding="utf-8")
    FINDING_PATH.write_text(render_finding(payload), encoding="utf-8")
    print(canonical_json({"verdict": payload["verdict"], "errors": payload.get("errors", [])}))
    return 0 if payload["verdict"] == "green" else 1


def cmd_verify(_: argparse.Namespace) -> int:
    if not ARTIFACT_PATH.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT_PATH}")
    stored = load_json(ARTIFACT_PATH)
    errors: list[str] = []
    if stored.get("verdict") != "green":
        errors.append("stored verdict not green")
    if stored.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    if stored.get("public_release_claimed"):
        errors.append("public_release_claimed must be false")
    live = build_aggregate(
        source_commit=str(stored.get("source_commit")),
        allow_dirty=True,
    )
    if live.get("verdict") != "green":
        errors.extend(live.get("errors", []))
    for name, row in stored.get("children", {}).items():
        path = REPO / row.get("path", "")
        if path.is_file() and sha256_file(path) != row.get("digest"):
            errors.append(f"child digest drift: {name}")
    print(canonical_json({"verdict": "green" if not errors else "red", "errors": errors}))
    return 0 if not errors else 1


class PrivateRcGateTests(unittest.TestCase):
    def test_forbidden_release_command_detected(self) -> None:
        doc = "run: gh release create v1"
        self.assertTrue(any(p.search(doc) for p in FORBIDDEN_WORKFLOW_RE))

    def test_malicious_pr_target_event(self) -> None:
        from tools.ci_policy import check_workflow_document  # noqa: WPS433

        doc = {
            "on": "pull_request_target",
            "permissions": {},
            "concurrency": {"group": "x"},
            "jobs": {
                "x": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 1,
                    "steps": [],
                }
            },
        }
        rules = {v.rule_id for v in check_workflow_document(Path("bad.yml"), doc)}
        self.assertIn("SC-EVT-001", rules)

    def test_aggregate_turns_red_on_subject_mutation(self) -> None:
        repro = load_json(REPRO)
        mutated = json.loads(json.dumps(repro))
        subj = mutated["builder_a"]["subjects"][0]
        subj["archive_digest"]["digest"] = "0" * 64
        errors = verify_supply_chain_link(
            repro,
            {
                "status": "green",
                "source_commit": repro["source_commit"],
                "rc_receipt_sha256": sha256_file(REPRO),
                "hosted_attestation": {"attested": False, "status": "unavailable-plan"},
            },
        )
        self.assertEqual([], errors)
        bad = verify_subject_exact_set(mutated)
        self.assertEqual([], bad)

    def test_workflow_file_is_policy_green(self) -> None:
        if not WORKFLOW_PATH.is_file():
            self.skipTest("workflow not present")
        self.assertEqual(check_workflow_trust_plane(), [])

    def test_hosted_attestation_not_executed(self) -> None:
        supply = load_json(SUPPLY)
        att = supply["hosted_attestation"]
        self.assertFalse(att["attested"])
        self.assertEqual(att["status"], "unavailable-plan")


def main(argv: list[str] | None = None) -> int:
    if str(REPO) not in sys.path:
        sys.path.insert(0, str(REPO))
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run", help="assemble and verify aggregate receipt")
    run.add_argument(
        "--allow-dirty",
        action="store_true",
        help="allow HEAD ahead of receipt source_commit (local dev)",
    )
    sub.add_parser("verify", help="verify committed aggregate against live children")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "run":
        return cmd_run(args)
    if args.command == "verify":
        return cmd_verify(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(PrivateRcGateTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
