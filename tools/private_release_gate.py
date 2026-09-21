#!/usr/bin/env python3
"""P10.5 private RC acceptance and non-authorizing G-RELEASE decision packet (stdlib-only)."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
if str(REPO) not in sys.path:
    sys.path.insert(0, str(REPO))

from tools import release_acceptance as ra  # noqa: E402
from tools import release_dossier as rd  # noqa: E402

PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
DOSSIER_ART = PLAN / "artifacts/P10-release-dossier.json"
CONSUMER_ART = PLAN / "artifacts/P10-consumer-acceptance.json"
DOCS_ART = PLAN / "artifacts/P10-docs.json"
P9_RC = PLAN / "artifacts/P9-private-rc.json"
ACCEPTANCE_ART = PLAN / "artifacts/P10-private-rc-acceptance.json"
GRELEASE_ART = PLAN / "artifacts/P10-g-release-request.json"
FINDING_ACCEPT = PLAN / "findings/P10-private-rc-acceptance.md"
FINDING_PACKET = PLAN / "findings/P10-g-release-decision-packet.md"
FIXTURES = REPO / "fixtures/hardening/private-release-gate-v1.json"

ACCEPTANCE_SCHEMA = "private-rc-acceptance/v1"
GRELEASE_SCHEMA = "g-release-request/v1"

FORBIDDEN_TRANSCRIPT_RE = [
    re.compile(r"gh\s+release", re.IGNORECASE),
    re.compile(r"git\s+push\s+.*\s+--tags", re.IGNORECASE),
    re.compile(r"composer\s+publish", re.IGNORECASE),
    re.compile(r"dart\s+pub\s+publish(?!\s+--dry-run)", re.IGNORECASE),
    re.compile(r"npm\s+publish", re.IGNORECASE),
]

INVALIDATION_TRIGGERS = [
    "dossier_digest_mismatch",
    "subject_archive_digest_change",
    "source_tree_commit_change",
    "child_receipt_digest_drift",
    "decision_D5_status_change",
    "owner_grant_outside_packet",
]


class PrivateReleaseGateError(RuntimeError):
    pass


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def expiry_iso(days: int = 90) -> str:
    return (
        datetime.now(timezone.utc) + timedelta(days=days)
    ).strftime("%Y-%m-%dT%H:%M:%SZ")


def git_head_sha() -> str:
    proc = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=REPO,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise PrivateReleaseGateError(proc.stderr.strip() or "git rev-parse failed")
    return proc.stdout.strip()


def load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise PrivateReleaseGateError(f"missing artifact: {path}")
    return json.loads(path.read_text(encoding="utf-8"))


def manifest_digest(doc: dict[str, Any]) -> str:
    body = dict(doc)
    body.pop("manifest_sha256", None)
    return sha256_bytes(canonical_json(body).encode("utf-8"))


def subjects_from_p9(p9: dict[str, Any]) -> dict[str, dict[str, str]]:
    out: dict[str, dict[str, str]] = {}
    for row in p9.get("subjects", []):
        sid = str(row["subject_id"])
        out[sid] = {
            "archive_digest": row["archive_digest"]["digest"],
            "content_manifest_digest": str(row["content_manifest_digest"]),
        }
    return out


def consumer_subjects_match(p9: dict[str, Any], consumer: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    p9_subj = subjects_from_p9(p9)
    for row in consumer.get("subjects", []):
        sid = str(row.get("subject_id"))
        live = p9_subj.get(sid)
        if not live:
            errors.append(f"unknown consumer subject {sid}")
            continue
        if row.get("archive_digest") != live["archive_digest"]:
            errors.append(f"consumer subject digest mismatch: {sid}")
    return errors


def compute_acceptance_verdict(
    dossier: dict[str, Any],
    consumer: dict[str, Any],
    p9: dict[str, Any],
    dossier_errors: list[str],
) -> tuple[str, list[str]]:
    reasons: list[str] = []
    if consumer.get("verdict") != "green":
        reasons.append("P10.3 consumer verdict not green")
    if p9.get("verdict") != "green":
        reasons.append("P9 private RC verdict not green")
    reasons.extend(consumer_subjects_match(p9, consumer))
    if dossier_errors:
        reasons.append("dossier validation failed")
    if dossier.get("verdicts", {}).get("private_ready") != "green":
        reasons.append("dossier private_ready not green")
    matrix = dossier.get("matrix", {})
    if matrix.get("recompute_errors"):
        reasons.append("acceptance matrix recompute errors")
    if ra.ARTIFACT.is_file():
        matrix_errors = ra.validate_matrix(load_json(ra.ARTIFACT))
        if matrix_errors:
            reasons.append("committed acceptance matrix invalid")
    verdict = "green" if not reasons else "red"
    return verdict, reasons


def build_acceptance(dossier: dict[str, Any]) -> dict[str, Any]:
    consumer = load_json(CONSUMER_ART)
    p9 = load_json(P9_RC)
    docs = load_json(DOCS_ART)
    dossier_errors = rd.validate_dossier(dossier)
    verdict, reasons = compute_acceptance_verdict(dossier, consumer, p9, dossier_errors)
    dossier_digest = sha256_file(DOSSIER_ART)
    payload: dict[str, Any] = {
        "schema_version": ACCEPTANCE_SCHEMA,
        "generated_at": now_iso(),
        "source_commit": git_head_sha(),
        "dossier": {
            "path": DOSSIER_ART.relative_to(REPO).as_posix(),
            "digest": dossier_digest,
            "private_ready": dossier.get("verdicts", {}).get("private_ready"),
            "public_eligible": dossier.get("verdicts", {}).get("public_eligible"),
        },
        "child_receipts": {
            "P10_consumer": {
                "path": CONSUMER_ART.relative_to(REPO).as_posix(),
                "digest": sha256_file(CONSUMER_ART),
                "verdict": consumer.get("verdict"),
            },
            "P10_docs": {
                "path": DOCS_ART.relative_to(REPO).as_posix(),
                "digest": sha256_file(DOCS_ART),
                "verdict": docs.get("verdict"),
            },
            "P9_private_rc": {
                "path": P9_RC.relative_to(REPO).as_posix(),
                "digest": sha256_file(P9_RC),
                "verdict": p9.get("verdict"),
            },
        },
        "subjects": subjects_from_p9(p9),
        "invalidation_triggers": list(INVALIDATION_TRIGGERS),
        "verdict": verdict,
        "verdict_reasons": reasons,
        "public_authorized": False,
    }
    payload["manifest_sha256"] = manifest_digest(payload)
    return payload


def public_blockers_from_dossier(dossier: dict[str, Any]) -> list[dict[str, Any]]:
    blockers: list[dict[str, Any]] = []
    for row in dossier.get("residuals", []):
        if not row.get("public_blocking"):
            continue
        blockers.append(
            {
                "id": row.get("id"),
                "source_row": row.get("source_row"),
                "disposition": row.get("disposition"),
                "note": row.get("note", ""),
            }
        )
    return sorted(blockers, key=lambda r: str(r.get("id")))


def proposed_actions(subjects: dict[str, dict[str, str]]) -> list[dict[str, Any]]:
    php_digest = subjects["php-sdk"]["archive_digest"]
    dart_digest = subjects["dart-core"]["archive_digest"]
    mcp_digest = subjects["dart-mcp-companion"]["archive_digest"]
    common_pre = [
        "owner_gate:G-RELEASE with exact packet digest",
        "D5 accepted or superseded with recorded legal identity",
        "public_eligible green in fresh dossier",
    ]
    return [
        {
            "action_id": "packagist.publish.php-sdk",
            "channel": "packagist.org",
            "registry_identity": "agentools/sdk",
            "version": "owner-specified-semver",
            "tag": None,
            "subject_id": "php-sdk",
            "subject_archive_digest": php_digest,
            "preconditions": common_pre + ["LICENSE permits redistribution"],
            "preconditions_met": False,
            "credential_path": "packagist-api-token (not read by this gate)",
            "compensating_response": "publish corrective version; Packagist deletion not guaranteed",
            "irreversible_warning": True,
        },
        {
            "action_id": "pub.dev.publish.dart-core",
            "channel": "pub.dev",
            "registry_identity": "agentools",
            "version": "0.0.1-or-owner-specified",
            "tag": None,
            "subject_id": "dart-core",
            "subject_archive_digest": dart_digest,
            "preconditions": common_pre + ["dart pubspec publish_to != none"],
            "preconditions_met": False,
            "credential_path": "pub.dev uploader OAuth (not read by this gate)",
            "compensating_response": "publish new version; unpublish limited by pub.dev policy",
            "irreversible_warning": True,
        },
        {
            "action_id": "pub.dev.publish.dart-mcp-companion",
            "channel": "pub.dev",
            "registry_identity": "agentools_mcp_dart",
            "version": "0.0.1-or-owner-specified",
            "tag": None,
            "subject_id": "dart-mcp-companion",
            "subject_archive_digest": mcp_digest,
            "preconditions": common_pre
            + [
                "remove path dependency on ../agentools",
                "hosted pub package identity for agentools",
            ],
            "preconditions_met": False,
            "credential_path": "pub.dev uploader OAuth (not read by this gate)",
            "compensating_response": "keep private-paired until topology resolved",
            "irreversible_warning": True,
        },
        {
            "action_id": "github.release.spec-tag",
            "channel": "github.com/axiomasoft/agentools",
            "registry_identity": "github-release",
            "version": "spec-2026-09",
            "tag": "spec-2026-09",
            "subject_id": "all-p9-subjects",
            "subject_archive_digest": sha256_bytes(
                canonical_json(
                    {k: v["archive_digest"] for k, v in sorted(subjects.items())}
                ).encode("utf-8")
            ),
            "preconditions": common_pre + ["repository visibility policy allows public release"],
            "preconditions_met": False,
            "credential_path": "contents:write token (not read by this gate)",
            "compensating_response": "new release/tag; immutable releases cannot replace assets",
            "irreversible_warning": True,
        },
    ]


def build_g_release_request(
    acceptance: dict[str, Any], dossier: dict[str, Any]
) -> dict[str, Any]:
    license_digest = sha256_file(REPO / "LICENSE")
    subjects = acceptance.get("subjects") or {}
    public_eligible = dossier.get("verdicts", {}).get("public_eligible")
    blockers = public_blockers_from_dossier(dossier)
    payload: dict[str, Any] = {
        "schema_version": GRELEASE_SCHEMA,
        "generated_at": now_iso(),
        "expires_at": expiry_iso(90),
        "owner_gate": "G-RELEASE",
        "authorized": False,
        "owner_grant_evidence": None,
        "decision_references": {
            "D4": "accepted",
            "D5": "proposed",
            "D12": "accepted",
            "D14": "accepted",
            "D15": "accepted",
        },
        "acceptance": {
            "path": ACCEPTANCE_ART.relative_to(REPO).as_posix(),
            "digest": manifest_digest(acceptance),
            "verdict": acceptance.get("verdict"),
        },
        "dossier": {
            "path": DOSSIER_ART.relative_to(REPO).as_posix(),
            "digest": sha256_file(DOSSIER_ART),
        },
        "source_commit": acceptance.get("source_commit"),
        "subjects": subjects,
        "current_identity": {
            "license_digest": license_digest,
            "license_spdx": "proprietary",
            "composer_license": "proprietary",
            "dart_publish_to": "none",
            "repository_visibility": "private",
            "dart_mcp_path_dependency": "agentools: path: ../agentools",
        },
        "public_eligible": public_eligible,
        "public_authorized": False,
        "public_blockers": blockers,
        "proposed_actions": proposed_actions(subjects),
        "rollback_limits": [
            "Published registry versions and git tags create durable consumer state.",
            "Deletion or retraction is channel-specific and never guaranteed.",
            "This packet does not promise rollback of any future public artifact.",
        ],
        "invalidation_triggers": list(INVALIDATION_TRIGGERS),
    }
    payload["packet_digest"] = manifest_digest(payload)
    return payload


def validate_acceptance(doc: dict[str, Any], *, live_dossier: dict[str, Any] | None = None) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != ACCEPTANCE_SCHEMA:
        errors.append("acceptance schema_version mismatch")
    if doc.get("public_authorized") is not False:
        errors.append("acceptance public_authorized must be false")
    body = dict(doc)
    body.pop("manifest_sha256", None)
    if doc.get("manifest_sha256") != sha256_bytes(canonical_json(body).encode("utf-8")):
        errors.append("acceptance manifest_sha256 mismatch")
    if doc.get("verdict") == "green":
        consumer = load_json(CONSUMER_ART)
        p9 = load_json(P9_RC)
        dossier = live_dossier or load_json(DOSSIER_ART)
        dossier_errors = rd.validate_dossier(dossier)
        expected, reasons = compute_acceptance_verdict(dossier, consumer, p9, dossier_errors)
        if expected != "green":
            errors.append(f"acceptance verdict green but rules say red: {reasons[:2]}")
    stored_dossier_digest = doc.get("dossier", {}).get("digest")
    if DOSSIER_ART.is_file() and stored_dossier_digest != sha256_file(DOSSIER_ART):
        errors.append("acceptance dossier digest stale")
    p9 = load_json(P9_RC)
    for sid, row in (doc.get("subjects") or {}).items():
        live = next((s for s in p9.get("subjects", []) if s["subject_id"] == sid), None)
        if not live:
            errors.append(f"unknown subject {sid}")
            continue
        if row.get("archive_digest") != live["archive_digest"]["digest"]:
            errors.append(f"subject digest incoherent: {sid}")
    return errors


def validate_g_release(doc: dict[str, Any], acceptance: dict[str, Any] | None = None) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != GRELEASE_SCHEMA:
        errors.append("g-release schema_version mismatch")
    if doc.get("authorized") is not False:
        errors.append("authorized must be false")
    if doc.get("owner_grant_evidence"):
        errors.append("owner_grant_evidence must be absent")
    if doc.get("public_authorized") is not False:
        errors.append("public_authorized must be false")
    if doc.get("decision_references", {}).get("D5") not in {"proposed", "accepted"}:
        errors.append("D5 reference must be explicit")
    for action in doc.get("proposed_actions", []):
        channel = str(action.get("channel", ""))
        if "*" in channel or channel.strip() == "*":
            errors.append("wildcard channel forbidden")
        if action.get("preconditions_met") is True:
            errors.append(f"preconditions_met true forbidden: {action.get('action_id')}")
        transcript = json.dumps(action)
        for pattern in FORBIDDEN_TRANSCRIPT_RE:
            if pattern.search(transcript):
                errors.append(f"forbidden action transcript: {action.get('action_id')}")
    if doc.get("public_eligible") == "green" and doc.get("public_blockers"):
        errors.append("public_eligible green with blockers present")
    if doc.get("public_eligible") == "red" and not doc.get("public_blockers"):
        errors.append("public_eligible red requires public_blockers")
    acc = acceptance or (
        load_json(ACCEPTANCE_ART) if ACCEPTANCE_ART.is_file() else None
    )
    if acc:
        bound = doc.get("acceptance", {})
        if bound.get("digest") != manifest_digest(acc):
            errors.append("acceptance digest binding mismatch")
    body = dict(doc)
    body.pop("packet_digest", None)
    if doc.get("packet_digest") != sha256_bytes(canonical_json(body).encode("utf-8")):
        errors.append("packet_digest mismatch")
    return errors


def render_acceptance_finding(doc: dict[str, Any]) -> str:
    lines = [
        "# P10.5 private RC acceptance",
        "",
        f"**Verdict:** `{doc.get('verdict')}` (non-authorizing)",
        f"**Source commit:** `{doc.get('source_commit')}`",
        f"**Dossier private_ready:** `{doc.get('dossier', {}).get('private_ready')}`",
        "",
        "## Reasons",
        "",
    ]
    for reason in doc.get("verdict_reasons") or ["(none)"]:
        lines.append(f"- {reason}")
    lines.extend(["", "## Subjects", ""])
    for sid, row in sorted((doc.get("subjects") or {}).items()):
        lines.append(f"- `{sid}`: `{row.get('archive_digest')}`")
    lines.append("")
    return "\n".join(lines)


def render_packet_finding(doc: dict[str, Any]) -> str:
    lines = [
        "# P10 G-RELEASE decision packet (request only)",
        "",
        f"**Packet digest:** `{doc.get('packet_digest', '')[:16]}…`",
        f"**Expires:** `{doc.get('expires_at')}`",
        f"**Authorized:** `{doc.get('authorized')}`",
        f"**Public eligible:** `{doc.get('public_eligible')}`",
        f"**Public blockers:** {len(doc.get('public_blockers') or [])}",
        "",
        "## Proposed actions (all blocked until owner scope)",
        "",
    ]
    for action in doc.get("proposed_actions", []):
        lines.append(
            f"- `{action.get('action_id')}` → `{action.get('channel')}` "
            f"(preconditions_met={action.get('preconditions_met')})"
        )
    lines.extend(["", "## Rollback limits", ""])
    for row in doc.get("rollback_limits") or []:
        lines.append(f"- {row}")
    lines.append("")
    return "\n".join(lines)


def pin_acceptance_matrix() -> None:
    matrix = ra.build_matrix()
    ra.ARTIFACT.parent.mkdir(parents=True, exist_ok=True)
    ra.ARTIFACT.write_text(ra.canonical_json(matrix) + "\n", encoding="utf-8")


def cmd_capture(_: argparse.Namespace) -> int:
    pin_acceptance_matrix()
    dossier = rd.build_dossier()
    DOSSIER_ART.parent.mkdir(parents=True, exist_ok=True)
    DOSSIER_ART.write_text(canonical_json(dossier) + "\n", encoding="utf-8")
    FINDING_DOSSIER = PLAN / "findings/P10-release-dossier.md"
    FINDING_RESIDUAL = PLAN / "findings/P10-residual-risks.md"
    FINDING_DOSSIER.write_text(rd.render_dossier(dossier), encoding="utf-8")
    FINDING_RESIDUAL.write_text(rd.render_residuals(dossier), encoding="utf-8")

    acceptance = build_acceptance(dossier)
    ACCEPTANCE_ART.write_text(canonical_json(acceptance) + "\n", encoding="utf-8")
    FINDING_ACCEPT.write_text(render_acceptance_finding(acceptance), encoding="utf-8")

    packet = build_g_release_request(acceptance, dossier)
    GRELEASE_ART.write_text(canonical_json(packet) + "\n", encoding="utf-8")
    FINDING_PACKET.write_text(render_packet_finding(packet), encoding="utf-8")

    print(
        canonical_json(
            {
                "acceptance_verdict": acceptance["verdict"],
                "public_eligible": dossier["verdicts"]["public_eligible"],
                "packet_digest": packet["packet_digest"],
            }
        )
    )
    return 0


def cmd_validate(_: argparse.Namespace) -> int:
    errors: list[str] = []
    if not ACCEPTANCE_ART.is_file():
        errors.append("missing acceptance artifact")
    if not GRELEASE_ART.is_file():
        errors.append("missing g-release artifact")
    acceptance = load_json(ACCEPTANCE_ART) if ACCEPTANCE_ART.is_file() else {}
    packet = load_json(GRELEASE_ART) if GRELEASE_ART.is_file() else {}
    errors.extend(validate_acceptance(acceptance))
    errors.extend(validate_g_release(packet, acceptance))
    print(canonical_json({"errors": errors, "acceptance_verdict": acceptance.get("verdict")}))
    return 0 if not errors else 1


def cmd_fixtures(_: argparse.Namespace) -> int:
    if not FIXTURES.is_file():
        raise SystemExit(f"missing fixtures: {FIXTURES}")
    cases = json.loads(FIXTURES.read_text(encoding="utf-8"))
    errors: list[str] = []
    for case in cases.get("negative_g_release", []):
        case_errors = validate_g_release(case["document"])
        if not case_errors:
            errors.append(f"negative case expected errors: {case.get('id')}")
    for case in cases.get("negative_acceptance", []):
        case_errors = validate_acceptance(case["document"])
        if not case_errors:
            errors.append(f"negative acceptance expected errors: {case.get('id')}")
    print(canonical_json({"fixture_errors": errors}))
    return 0 if not errors else 1


class PrivateReleaseGateTests(unittest.TestCase):
    def test_forbidden_self_authorized_packet(self) -> None:
        doc = {"schema_version": GRELEASE_SCHEMA, "authorized": True, "owner_grant_evidence": None}
        self.assertTrue(any("authorized" in e for e in validate_g_release(doc)))

    def test_wildcard_channel_rejected(self) -> None:
        doc = {
            "schema_version": GRELEASE_SCHEMA,
            "authorized": False,
            "owner_grant_evidence": None,
            "public_authorized": False,
            "decision_references": {"D5": "proposed"},
            "proposed_actions": [{"channel": "packagist.*", "preconditions_met": False}],
            "public_eligible": "red",
            "public_blockers": [{"id": "RR-DEC-D5-PUBLIC-IDENTITY"}],
            "packet_digest": "0" * 64,
        }
        self.assertTrue(any("wildcard" in e for e in validate_g_release(doc)))

    def test_build_and_validate_roundtrip(self) -> None:
        if not CONSUMER_ART.is_file():
            self.skipTest("consumer artifact missing")
        dossier = rd.build_dossier()
        acceptance = build_acceptance(dossier)
        packet = build_g_release_request(acceptance, dossier)
        acc_err = validate_acceptance(acceptance, live_dossier=dossier)
        pkt_err = validate_g_release(packet, acceptance)
        self.assertEqual([], pkt_err)
        if acceptance["verdict"] == "green":
            self.assertEqual([], acc_err)

    def test_stale_packet_digest_detected(self) -> None:
        if not CONSUMER_ART.is_file():
            self.skipTest("consumer artifact missing")
        dossier = rd.build_dossier()
        acceptance = build_acceptance(dossier)
        packet = build_g_release_request(acceptance, dossier)
        packet["packet_digest"] = "f" * 64
        self.assertTrue(any("packet_digest" in e for e in validate_g_release(packet, acceptance)))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("capture", help="refresh dossier and write acceptance + G-RELEASE packet")
    sub.add_parser("validate", help="validate committed P10.5 artifacts")
    sub.add_parser("fixtures", help="run offline negative fixtures")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "capture":
        return cmd_capture(args)
    if args.command == "validate":
        return cmd_validate(args)
    if args.command == "fixtures":
        return cmd_fixtures(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(PrivateReleaseGateTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
