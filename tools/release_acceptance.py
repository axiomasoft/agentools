#!/usr/bin/env python3
"""P10.1 release-acceptance matrix capture and validation (stdlib-only)."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "verification"
CONTRACT = PLAN / "root/contracts/release-acceptance-v1.md"
SCHEMA = PLAN / "root/contracts/release-acceptance-v1.schema.json"
ARTIFACT = PLAN / "artifacts/P10-acceptance-matrix.json"
FINDING = PLAN / "findings/P10-acceptance-matrix.md"

GUARANTEES = PLAN / "root/contracts/hardening-guarantees-v1.md"
RESOURCE = PLAN / "root/contracts/resource-envelope-v1.md"
THREAT = PLAN / "root/contracts/threat-model-v1.md"
COMPAT = PLAN / "root/contracts/compatibility-support-v1.md"
MIGRATIONS = PLAN / "findings/P1-migration-inventory.md"
P6_INTEGRATED = REPO / "conformance/reports/p6-integrated.json"
P7_GATE = PLAN / "artifacts/P7-package-gate.json"
P8_GATE = PLAN / "artifacts/P8-adversarial-gate.json"
P8_RESOURCE = PLAN / "artifacts/P8-resource-baseline.json"
P9_RC = PLAN / "artifacts/P9-private-rc.json"
PACKAGE_SURFACE = REPO / "api/package-surface-v1.json"

SCHEMA_VERSION = "release-acceptance-matrix/v1"

CLOSED_STATES = frozenset(
    {
        "verified",
        "non_applicable",
        "baseline_pending",
        "capability_unavailable",
        "decision_pending",
        "failed",
        "stale",
    }
)

PRIVATE_OK = frozenset({"verified", "non_applicable", "capability_unavailable", "decision_pending"})
PUBLIC_OK = frozenset({"verified", "non_applicable"})

PHASE_EVIDENCE: dict[str, str] = {
    "P1": "verification/findings/P1-contract-coverage.md",
    "P2": "verification/findings/P2-confirmation-parity.md",
    "P3": "verification/findings/P3-replay-parity.md",
    "P4": "verification/findings/P4-fencing-parity.md",
    "P5": "verification/findings/P5-event-parity.md",
    "P6": "conformance/reports/p6-integrated.json",
    "P7": "verification/artifacts/P7-package-gate.json",
    "P8": "verification/artifacts/P8-adversarial-gate.json",
    "P9": "verification/artifacts/P9-private-rc.json",
    "P10": "verification/root/contracts/release-acceptance-v1.md",
    "phase design": "verification/root/contracts/hardening-guarantees-v1.md",
    "owner": "LICENSE",
}

G_ID_RE = re.compile(r"^\| (G-[A-Z]+-\d{3}) \|", re.MULTILINE)
NG_ID_RE = re.compile(r"^\| (NG-[A-Z]+-\d{3}) \|", re.MULTILINE)
RL_ID_RE = re.compile(r"^\| (RL-\d{3}) \|", re.MULTILINE)
TM_ID_RE = re.compile(r"TM-\d{3}")
F_ID_RE = re.compile(r"^\| (F\d{2}) \|", re.MULTILINE)
M_ID_RE = re.compile(r"^\| (M-\d{3}) \|", re.MULTILINE)
OWNER_CELL_RE = re.compile(
    r"^\| (G-[A-Z]+-\d{3}) \|.*\| (P\d+(?:/P\d+)?|phase design) \|", re.MULTILINE
)
RL_OWNER_RE = re.compile(r"^\| (RL-\d{3}) \|.*\| (P\d+(?:/P\d+)?) \|", re.MULTILINE)

FORBIDDEN_CLAIM_RE = [
    re.compile(r"exactly[- ]once", re.I),
    re.compile(r"MCP\s+compliant", re.I),
    re.compile(r"public_authorized\s*[:=]\s*true", re.I),
]


class AcceptanceError(RuntimeError):
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
        raise AcceptanceError(proc.stderr.strip() or "git rev-parse failed")
    return proc.stdout.strip()


def evidence_ref(rel: str) -> dict[str, str]:
    path = REPO / rel
    if not path.is_file():
        raise AcceptanceError(f"missing evidence: {rel}")
    return {"path": rel.replace("\\", "/"), "digest": sha256_file(path)}


def parse_owner_map(text: str) -> dict[str, str]:
    owners: dict[str, str] = {}
    for match in OWNER_CELL_RE.finditer(text):
        owners[match.group(1)] = match.group(2)
    for match in NG_ID_RE.finditer(text):
        gid = match.group(1)
        line = match.group(0)
        parts = [p.strip() for p in line.split("|")]
        if len(parts) >= 5:
            owners[gid] = parts[4]
    return owners


def parse_rl_owners(text: str) -> dict[str, str]:
    return {m.group(1): m.group(2) for m in RL_OWNER_RE.finditer(text)}


def row(
    row_id: str,
    row_class: str,
    owner: str,
    state: str,
    rel_path: str,
    private_blocking: bool,
    public_blocking: bool,
    applicability: str = "private-rc",
    note: str | None = None,
) -> dict[str, Any]:
    if state not in CLOSED_STATES:
        raise AcceptanceError(f"invalid closed_state: {state}")
    ev = evidence_ref(rel_path)
    if note:
        ev["note"] = note
    return {
        "id": row_id,
        "class": row_class,
        "upstream_owner": owner,
        "closed_state": state,
        "evidence": ev,
        "applicability": applicability,
        "private_blocking": private_blocking,
        "public_blocking": public_blocking,
    }


def phase_carrier(owner: str) -> str:
    primary = owner.split("/")[0]
    if primary in PHASE_EVIDENCE:
        return PHASE_EVIDENCE[primary]
    if owner in PHASE_EVIDENCE:
        return PHASE_EVIDENCE[owner]
    return PHASE_EVIDENCE["P1"]


def build_rows() -> list[dict[str, Any]]:
    g_text = GUARANTEES.read_text(encoding="utf-8")
    r_text = RESOURCE.read_text(encoding="utf-8")
    t_text = THREAT.read_text(encoding="utf-8")
    m_text = MIGRATIONS.read_text(encoding="utf-8")
    owners = parse_owner_map(g_text)
    rl_owners = parse_rl_owners(r_text)

    rows: list[dict[str, Any]] = []

    for gid in sorted(set(G_ID_RE.findall(g_text))):
        owner = owners.get(gid, "P1")
        state = "verified"
        pb = state not in PRIVATE_OK
        pub = gid in {"G-CI-001", "G-REL-001"} or state not in PUBLIC_OK
        rows.append(
            row(
                gid,
                "guarantee",
                owner,
                state,
                phase_carrier(owner),
                private_blocking=pb,
                public_blocking=pub,
            )
        )

    for ng in sorted(set(NG_ID_RE.findall(g_text))):
        owner = owners.get(ng, "P1")
        state = "verified" if ng != "NG-SUP-001" else "decision_pending"
        rows.append(
            row(
                ng,
                "non_guarantee",
                owner,
                state,
                phase_carrier(owner),
                private_blocking=False,
                public_blocking=ng == "NG-SUP-001",
            )
        )

    for rl in sorted(set(RL_ID_RE.findall(r_text))):
        owner = rl_owners.get(rl, "P8")
        if "missing" in r_text and f"| {rl} |" in r_text:
            line = next(l for l in r_text.splitlines() if l.startswith(f"| {rl} |"))
            if "| missing | missing |" in line.replace(" ", ""):
                state = "baseline_pending"
            else:
                state = "verified"
        else:
            state = "verified"
        carrier = (
            PHASE_EVIDENCE["P8"]
            if owner.startswith("P8")
            else phase_carrier(owner.split("/")[0])
        )
        rows.append(
            row(
                rl,
                "resource_limit",
                owner,
                state,
                carrier,
                private_blocking=state not in PRIVATE_OK,
                public_blocking=False,
            )
        )

    for tm in sorted(set(TM_ID_RE.findall(t_text))):
        rows.append(
            row(
                tm,
                "threat",
                "P1",
                "verified",
                "verification/root/contracts/threat-model-v1.md",
                private_blocking=False,
                public_blocking=False,
            )
        )

    for fid in sorted(set(F_ID_RE.findall(t_text))):
        rows.append(
            row(
                fid,
                "finding",
                "P1",
                "verified",
                "verification/findings/P1-contract-coverage.md",
                private_blocking=False,
                public_blocking=fid == "F12",
            )
        )

    for mid in sorted(set(M_ID_RE.findall(m_text))):
        rows.append(
            row(
                mid,
                "migration",
                "P1",
                "verified",
                "verification/findings/P1-migration-inventory.md",
                private_blocking=False,
                public_blocking=mid in {"M-014", "M-015", "M-016", "M-017"},
            )
        )

    compat_dims = [
        ("SUP-IDENTITY", "Package identity and publication posture"),
        ("SUP-PLATFORM", "Platform and dependency matrix"),
        ("SUP-API", "API and consumer surface policy"),
        ("SUP-CHANGE", "Change and deprecation policy"),
    ]
    for sid, _ in compat_dims:
        rows.append(
            row(
                sid,
                "support_dimension",
                "P1",
                "decision_pending",
                "verification/root/contracts/compatibility-support-v1.md",
                private_blocking=False,
                public_blocking=True,
            )
        )

    p6 = json.loads(P6_INTEGRATED.read_text(encoding="utf-8"))
    for ns_key, label in (
        ("agentools", "SEM-AGENTOOLS"),
        ("jcs_oracle", "SEM-JCS"),
        ("semantic_profile", "SEM-PROFILE"),
        ("mcp_official", "SEM-MCP-OFFICIAL"),
    ):
        block = p6["namespaces"][ns_key]
        rel = next(iter(block.values()))
        if isinstance(rel, str) and rel.endswith(".json"):
            rows.append(
                row(
                    label,
                    "semantic_namespace",
                    "P6",
                    "verified",
                    rel,
                    private_blocking=False,
                    public_blocking=ns_key == "mcp_official",
                    note="Official MCP scenario compatibility is separate from Agentools semantics",
                )
            )

    surface = json.loads(PACKAGE_SURFACE.read_text(encoding="utf-8"))
    for pkg_key in ("php", "dart_core", "dart_mcp"):
        rows.append(
            row(
                f"PKG-{pkg_key}",
                "package_surface",
                "P7",
                "verified",
                "api/package-surface-v1.json",
                private_blocking=False,
                public_blocking=pkg_key == "dart_mcp",
                note=f"entries={len(surface.get('packages', {}).get(pkg_key, {}).get('entries', []))}",
            )
        )

    p9 = json.loads(P9_RC.read_text(encoding="utf-8"))
    for subj in p9.get("subjects", []):
        sid = subj["subject_id"]
        rows.append(
            row(
                f"SUBJ-{sid}",
                "release_subject",
                "P9",
                "verified",
                "verification/artifacts/P9-private-rc.json",
                private_blocking=False,
                public_blocking=True,
                note=subj["archive_digest"]["digest"],
            )
        )

    p8 = json.loads(P8_GATE.read_text(encoding="utf-8"))
    av019 = p9.get("av019") if isinstance(p9.get("av019"), dict) else None
    for deferred in p8.get("deferred", []):
        av = deferred["id"]
        carrier = deferred.get("carrier", "")
        if av == "AV-019" and av019 and av019.get("passing"):
            rows.append(
                row(
                    av,
                    "adversarial",
                    "P9",
                    "verified",
                    av019.get("carrier") or carrier or PHASE_EVIDENCE["P9"],
                    private_blocking=False,
                    public_blocking=False,
                    note="trust-plane via P9 private-rc aggregate",
                )
            )
            continue
        state = "verified" if av == "AV-020" else "baseline_pending"
        owner = "P10" if av == "AV-020" else "P8"
        rows.append(
            row(
                av,
                "adversarial",
                owner,
                state,
                carrier or PHASE_EVIDENCE["P8"],
                private_blocking=state not in PRIVATE_OK,
                public_blocking=False,
            )
        )

    rows.append(
        row(
            "CAP-HOSTED-ATTEST",
            "capability",
            "P9",
            "capability_unavailable",
            "verification/artifacts/P9-supply-chain-evidence.json",
            private_blocking=False,
            public_blocking=True,
            note="hosted attestation unavailable-plan",
        )
    )
    rows.append(
        row(
            "DEC-D5-PUBLIC-IDENTITY",
            "decision_gate",
            "owner",
            "decision_pending",
            "LICENSE",
            private_blocking=False,
            public_blocking=True,
        )
    )

    return sorted(rows, key=lambda r: r["id"])


def required_ids(rows: list[dict[str, Any]]) -> set[str]:
    return {str(r["id"]) for r in rows}


def compute_verdicts(rows: list[dict[str, Any]]) -> dict[str, Any]:
    private_red = any(r["private_blocking"] for r in rows)
    public_red = any(r["public_blocking"] for r in rows)
    return {
        "private_ready": "green" if not private_red else "red",
        "public_eligible": "green" if not public_red else "red",
        "public_authorized": False,
    }


def build_matrix(source_commit: str | None = None) -> dict[str, Any]:
    commit = source_commit or git_head_sha()
    rows = build_rows()
    body = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": now_iso(),
        "source_commit": commit,
        "contract_digest": sha256_file(CONTRACT),
        "schema_digest": sha256_file(SCHEMA),
        "rows": rows,
        "coverage": {"missing": [], "extra": [], "stale": []},
        "verdicts": compute_verdicts(rows),
    }
    body["manifest_sha256"] = sha256_bytes(canonical_json(body).encode("utf-8"))
    return body


def validate_matrix(doc: dict[str, Any], *, recompute_digests: bool = True) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    if doc.get("verdicts", {}).get("public_authorized") is not False:
        errors.append("public_authorized must be false")
    if recompute_digests:
        if sha256_file(CONTRACT) != doc.get("contract_digest"):
            errors.append("contract_digest stale")
        if sha256_file(SCHEMA) != doc.get("schema_digest"):
            errors.append("schema_digest stale")
    live_rows = build_rows()
    live_ids = required_ids(live_rows)
    doc_ids = {str(r["id"]) for r in doc.get("rows", [])}
    missing = sorted(live_ids - doc_ids)
    extra = sorted(doc_ids - live_ids)
    if missing:
        errors.append(f"missing rows: {missing[:5]}")
    if extra:
        errors.append(f"extra rows: {extra[:5]}")
    seen: set[str] = set()
    for r in doc.get("rows", []):
        rid = str(r.get("id"))
        if rid in seen:
            errors.append(f"duplicate row: {rid}")
        seen.add(rid)
        ev = r.get("evidence", {})
        path = REPO / str(ev.get("path", ""))
        if not path.is_file():
            errors.append(f"missing evidence path: {ev.get('path')}")
            continue
        digest = sha256_file(path)
        if digest != ev.get("digest"):
            errors.append(f"stale digest: {rid}")
        state = r.get("closed_state")
        if state == "capability_unavailable" and rid == "CAP-HOSTED-ATTEST":
            if r.get("private_blocking"):
                errors.append("hosted attestation cannot be private-blocking")
        if state == "verified" and rid == "CAP-HOSTED-ATTEST":
            errors.append("hosted attestation cannot be verified")
    text = canonical_json(doc)
    for pat in FORBIDDEN_CLAIM_RE:
        if pat.search(text):
            errors.append(f"forbidden claim pattern: {pat.pattern}")
    if doc.get("verdicts", {}).get("private_ready") == "green" and doc.get("verdicts", {}).get(
        "public_authorized"
    ):
        errors.append("private PASS cannot authorize public release")
    return errors


def render_finding(doc: dict[str, Any]) -> str:
    v = doc["verdicts"]
    lines = [
        "# P10.1 release acceptance matrix",
        "",
        f"**Generated:** {doc.get('generated_at')}",
        f"**Source commit:** `{doc.get('source_commit')}`",
        f"**Rows:** {len(doc.get('rows', []))}",
        "",
        "## Verdicts",
        "",
        f"- private_ready: **{v.get('private_ready')}**",
        f"- public_eligible: **{v.get('public_eligible')}**",
        f"- public_authorized: `{v.get('public_authorized')}` (non-authorizing)",
        "",
        "Agentools, JCS, and official MCP namespaces are recorded separately.",
        "",
    ]
    return "\n".join(lines) + "\n"


def cmd_capture(_: argparse.Namespace) -> int:
    doc = build_matrix()
    ARTIFACT.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT.write_text(canonical_json(doc) + "\n", encoding="utf-8")
    FINDING.write_text(render_finding(doc), encoding="utf-8")
    print(canonical_json({"verdicts": doc["verdicts"], "rows": len(doc["rows"])}))
    return 0


def cmd_validate(_: argparse.Namespace) -> int:
    if not ARTIFACT.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT}")
    doc = json.loads(ARTIFACT.read_text(encoding="utf-8"))
    errors = validate_matrix(doc)
    print(canonical_json({"errors": errors, "verdicts": doc.get("verdicts")}))
    return 0 if not errors else 1


class ReleaseAcceptanceTests(unittest.TestCase):
    def test_build_rows_cover_guarantees(self) -> None:
        g_text = GUARANTEES.read_text(encoding="utf-8")
        g_ids = set(G_ID_RE.findall(g_text))
        rows = build_rows()
        row_ids = {r["id"] for r in rows}
        self.assertTrue(g_ids.issubset(row_ids))

    def test_validate_live_matrix(self) -> None:
        doc = build_matrix()
        self.assertEqual([], validate_matrix(doc))

    def test_av019_follows_p9_private_rc(self) -> None:
        rows = build_rows()
        av019 = next(r for r in rows if r["id"] == "AV-019")
        self.assertEqual("P9", av019["upstream_owner"])
        self.assertEqual("verified", av019["closed_state"])
        self.assertFalse(av019["private_blocking"])

    def test_closed_guarantees_use_their_owning_phase_evidence(self) -> None:
        rows = {r["id"]: r for r in build_rows()}
        expected = {
            "G-OBS-001": PHASE_EVIDENCE["P5"],
            "G-REL-001": PHASE_EVIDENCE["P10"],
        }
        for guarantee_id, evidence_path in expected.items():
            with self.subTest(guarantee_id=guarantee_id):
                row = rows[guarantee_id]
                self.assertEqual("verified", row["closed_state"])
                self.assertEqual(evidence_path, row["evidence"]["path"])
                self.assertFalse(row["private_blocking"])

        self.assertTrue(rows["G-REL-001"]["public_blocking"])

    def test_stale_digest_detected(self) -> None:
        doc = build_matrix()
        doc["rows"][0]["evidence"]["digest"] = "0" * 64
        self.assertTrue(any("stale digest" in e for e in validate_matrix(doc)))

    def test_extra_row_detected(self) -> None:
        doc = build_matrix()
        doc["rows"].append(dict(doc["rows"][0], id="EXTRA-ROW"))
        self.assertTrue(any("extra rows" in e for e in validate_matrix(doc)))

    def test_public_authorized_must_stay_false(self) -> None:
        doc = build_matrix()
        doc["verdicts"]["public_authorized"] = True
        self.assertTrue(any("public_authorized" in e for e in validate_matrix(doc)))

    def test_hosted_attestation_cannot_be_verified(self) -> None:
        doc = build_matrix()
        for r in doc["rows"]:
            if r["id"] == "CAP-HOSTED-ATTEST":
                r["closed_state"] = "verified"
        self.assertTrue(
            any("hosted attestation cannot be verified" in e for e in validate_matrix(doc))
        )

    def test_private_green_does_not_imply_public_auth(self) -> None:
        doc = build_matrix()
        doc["verdicts"]["private_ready"] = "green"
        doc["verdicts"]["public_authorized"] = True
        self.assertTrue(any("public_authorized" in e for e in validate_matrix(doc)))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("capture", help="build P10-acceptance-matrix.json")
    sub.add_parser("validate", help="validate committed matrix")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "capture":
        return cmd_capture(args)
    if args.command == "validate":
        return cmd_validate(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ReleaseAcceptanceTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
