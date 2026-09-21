#!/usr/bin/env python3
"""P10.4 final release dossier and residual-risk ledger (stdlib-only)."""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
if str(REPO) not in sys.path:
    sys.path.insert(0, str(REPO))

from tools import release_acceptance as ra  # noqa: E402

PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
MATRIX_ART = PLAN / "artifacts/P10-acceptance-matrix.json"
DOCS_ART = PLAN / "artifacts/P10-docs.json"
CONSUMER_ART = PLAN / "artifacts/P10-consumer-acceptance.json"
P9_RC = PLAN / "artifacts/P9-private-rc.json"
ARTIFACT = PLAN / "artifacts/P10-release-dossier.json"
FINDING_DOSSIER = PLAN / "findings/P10-release-dossier.md"
FINDING_RESIDUAL = PLAN / "findings/P10-residual-risks.md"

SCHEMA_VERSION = "release-dossier/v1"

RESIDUAL_DISPOSITIONS = frozenset(
    {
        "accepted-non-guarantee",
        "baseline_pending",
        "capability_unavailable",
        "decision_pending",
        "monitor",
        "owner_review",
    }
)


class DossierError(RuntimeError):
    pass


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise DossierError(f"missing artifact: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def child_receipt(path: Path) -> dict[str, Any]:
    rel = path.relative_to(REPO).as_posix()
    payload = load_json(path)
    return {
        "path": rel,
        "digest": sha256_file(path),
        "schema_version": payload.get("schema_version"),
        "verdict": payload.get("verdict") or payload.get("verdicts"),
        "status": payload.get("status"),
    }


def residual_from_row(row: dict[str, Any]) -> dict[str, Any] | None:
    state = row.get("closed_state")
    rid = str(row.get("id"))
    if state in {"verified", "non_applicable"} and not row.get("public_blocking"):
        return None
    if state == "verified" and row.get("public_blocking"):
        disposition = "owner_review"
    elif state == "decision_pending":
        disposition = "decision_pending"
    elif state == "capability_unavailable":
        disposition = "capability_unavailable"
    elif state == "baseline_pending":
        disposition = "baseline_pending"
    elif state == "failed":
        disposition = "owner_review"
    elif state == "stale":
        disposition = "owner_review"
    else:
        disposition = "monitor"
    if rid.startswith("NG-"):
        disposition = "accepted-non-guarantee"
    return {
        "id": f"RR-{rid}",
        "source_row": rid,
        "closed_state": state,
        "owner": row.get("owner"),
        "private_blocking": bool(row.get("private_blocking")),
        "public_blocking": bool(row.get("public_blocking")),
        "disposition": disposition,
        "evidence": row.get("evidence"),
        "note": row.get("note", ""),
    }


def build_residuals(matrix: dict[str, Any]) -> list[dict[str, Any]]:
    residuals: list[dict[str, Any]] = []
    for row in matrix.get("rows", []):
        item = residual_from_row(row)
        if item:
            residuals.append(item)
    return sorted(residuals, key=lambda r: r["id"])


def compute_verdicts(
    matrix: dict[str, Any],
    docs: dict[str, Any],
    consumer: dict[str, Any],
    p9: dict[str, Any],
    residuals: list[dict[str, Any]],
) -> dict[str, Any]:
    private_blockers = [
        r
        for r in residuals
        if r.get("private_blocking") and r.get("disposition") not in {"accepted-non-guarantee"}
    ]
    public_blockers = [r for r in residuals if r.get("public_blocking")]
    child_ok = (
        docs.get("verdict") == "green"
        and consumer.get("verdict") == "green"
        and p9.get("verdict") == "green"
    )
    matrix_private = matrix.get("verdicts", {}).get("private_ready")
    matrix_public = matrix.get("verdicts", {}).get("public_eligible")
    private_ready = (
        "green"
        if child_ok and not private_blockers and matrix_private == "green"
        else "red"
    )
    public_eligible = "green" if not public_blockers and matrix_public == "green" else "red"
    return {
        "private_ready": private_ready,
        "public_eligible": public_eligible,
        "public_authorized": False,
        "private_blocker_count": len(private_blockers),
        "public_blocker_count": len(public_blockers),
    }


def build_dossier() -> dict[str, Any]:
    live_matrix = ra.build_matrix()
    committed = load_json(MATRIX_ART)
    matrix_errors = ra.validate_matrix(committed)
    docs = load_json(DOCS_ART)
    consumer = load_json(CONSUMER_ART)
    p9 = load_json(P9_RC)
    residuals = build_residuals(live_matrix)
    verdicts = compute_verdicts(live_matrix, docs, consumer, p9, residuals)
    p9_subjects = {
        row["subject_id"]: {
            "archive_digest": row["archive_digest"]["digest"],
            "content_manifest_digest": row["content_manifest_digest"],
        }
        for row in p9.get("subjects", [])
    }
    payload: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": now_iso(),
        "source_commit": live_matrix.get("source_commit"),
        "matrix": {
            "live_digest": sha256_bytes(canonical_json(live_matrix).encode("utf-8")),
            "committed_digest": sha256_file(MATRIX_ART),
            "recompute_errors": matrix_errors,
            "verdicts": live_matrix.get("verdicts"),
        },
        "child_receipts": {
            "P10_matrix": child_receipt(MATRIX_ART),
            "P10_docs": child_receipt(DOCS_ART),
            "P10_consumer": child_receipt(CONSUMER_ART),
            "P9_private_rc": child_receipt(P9_RC),
        },
        "subjects": p9_subjects,
        "residuals": residuals,
        "coverage": {
            "residual_ids": sorted(r["id"] for r in residuals),
            "missing": [],
            "extra": [],
        },
        "verdicts": verdicts,
    }
    payload["manifest_sha256"] = sha256_bytes(canonical_json(payload).encode("utf-8"))
    return payload


def validate_dossier(doc: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    if doc.get("verdicts", {}).get("public_authorized") is not False:
        errors.append("public_authorized must be false")
    for resid in doc.get("residuals", []):
        if resid.get("disposition") not in RESIDUAL_DISPOSITIONS:
            errors.append(f"invalid disposition: {resid.get('id')}")
    cov = doc.get("coverage", {})
    residual_ids = {r["id"] for r in doc.get("residuals", [])}
    declared = set(cov.get("residual_ids") or [])
    missing = sorted(residual_ids - declared)
    extra = sorted(declared - residual_ids)
    if missing:
        errors.append(f"coverage missing: {missing[:3]}")
    if extra:
        errors.append(f"coverage extra: {extra[:3]}")
    p9 = load_json(P9_RC)
    for sid, row in (doc.get("subjects") or {}).items():
        live = next((s for s in p9.get("subjects", []) if s["subject_id"] == sid), None)
        if not live:
            errors.append(f"unknown subject {sid}")
            continue
        if row.get("archive_digest") != live["archive_digest"]["digest"]:
            errors.append(f"subject digest incoherent: {sid}")
    body = dict(doc)
    body.pop("manifest_sha256", None)
    if doc.get("manifest_sha256") != sha256_bytes(canonical_json(body).encode("utf-8")):
        errors.append("manifest_sha256 mismatch")
    if doc.get("verdicts", {}).get("private_ready") == "green":
        consumer = load_json(CONSUMER_ART)
        if consumer.get("verdict") != "green":
            errors.append("private_ready green but consumer red")
    return errors


def render_dossier(doc: dict[str, Any]) -> str:
    v = doc["verdicts"]
    lines = [
        "# P10.4 release dossier",
        "",
        f"**Generated:** {doc.get('generated_at')}",
        f"**Source commit:** `{doc.get('source_commit')}`",
        "",
        "## Verdicts (non-authorizing)",
        "",
        f"- private_ready: **{v.get('private_ready')}**",
        f"- public_eligible: **{v.get('public_eligible')}**",
        f"- public_authorized: `{v.get('public_authorized')}`",
        "",
        "## Child receipts",
        "",
    ]
    for key, row in sorted(doc.get("child_receipts", {}).items()):
        lines.append(f"- {key}: `{row['path']}` digest `{row['digest'][:16]}…`")
    lines.append("")
    return "\n".join(lines) + "\n"


def render_residuals(doc: dict[str, Any]) -> str:
    lines = [
        "# P10 residual risks",
        "",
        f"**Count:** {len(doc.get('residuals', []))}",
        "",
        "| ID | State | Disposition | Private block | Public block |",
        "|:--|:--|:--|:--|:--|",
    ]
    for row in doc.get("residuals", []):
        lines.append(
            f"| {row['id']} | {row.get('closed_state')} | {row.get('disposition')} | "
            f"{row.get('private_blocking')} | {row.get('public_blocking')} |"
        )
    lines.append("")
    return "\n".join(lines)


def cmd_capture(_: argparse.Namespace) -> int:
    doc = build_dossier()
    ARTIFACT.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT.write_text(canonical_json(doc) + "\n", encoding="utf-8")
    FINDING_DOSSIER.write_text(render_dossier(doc), encoding="utf-8")
    FINDING_RESIDUAL.write_text(render_residuals(doc), encoding="utf-8")
    print(canonical_json({"verdicts": doc["verdicts"], "residuals": len(doc["residuals"])}))
    return 0


def cmd_validate(_: argparse.Namespace) -> int:
    if not ARTIFACT.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT}")
    doc = json.loads(ARTIFACT.read_text(encoding="utf-8"))
    errors = validate_dossier(doc)
    print(canonical_json({"errors": errors, "verdicts": doc.get("verdicts")}))
    return 0 if not errors else 1


class ReleaseDossierTests(unittest.TestCase):
    def test_disposition_vocab(self) -> None:
        self.assertIn("accepted-non-guarantee", RESIDUAL_DISPOSITIONS)

    def test_build_residuals_nonempty(self) -> None:
        matrix = ra.build_matrix()
        residuals = build_residuals(matrix)
        self.assertTrue(any(r["id"] == "RR-DEC-D5-PUBLIC-IDENTITY" for r in residuals))

    def test_corrupt_manifest_detected(self) -> None:
        doc = build_dossier()
        doc["manifest_sha256"] = "0" * 64
        self.assertTrue(any("manifest_sha256" in e for e in validate_dossier(doc)))

    def test_promoted_private_ready_without_consumer_fails(self) -> None:
        if not CONSUMER_ART.is_file():
            self.skipTest("consumer artifact missing")
        doc = build_dossier()
        doc["verdicts"]["private_ready"] = "green"
        if load_json(CONSUMER_ART).get("verdict") != "green":
            self.assertTrue(any("consumer red" in e for e in validate_dossier(doc)))

    def test_omitted_residual_coverage(self) -> None:
        doc = build_dossier()
        doc["coverage"]["residual_ids"] = []
        self.assertTrue(any("coverage missing" in e for e in validate_dossier(doc)))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("capture", help="build P10-release-dossier.json")
    sub.add_parser("validate", help="validate committed dossier")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "capture":
        return cmd_capture(args)
    if args.command == "validate":
        return cmd_validate(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ReleaseDossierTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
