#!/usr/bin/env python3
"""P10.2 documentation truth gate: manifest, lexicon and matrix linkage."""

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
MATRIX = PLAN / "artifacts/P10-acceptance-matrix.json"
ARTIFACT = PLAN / "artifacts/P10-docs.json"
FINDING = PLAN / "findings/P10-docs.md"

SCHEMA_VERSION = "release-docs-receipt/v1"

PUBLIC_DOCS = [
    "README.md",
    "SECURITY.md",
    "SUPPORT.md",
    "CHANGELOG.md",
    "RELEASE_NOTES.md",
    "docs/installation.md",
    "docs/migrations.md",
    "docs/operations.md",
    "php/README.md",
    "php/API.md",
    "php/UPGRADING.md",
    "dart/README.md",
    "dart/agentools/README.md",
    "dart/agentools/API.md",
    "dart/agentools/UPGRADING.md",
    "dart/agentools_mcp_dart/README.md",
    "dart/agentools_mcp_dart/API.md",
]

FORBIDDEN_PATTERNS = [
    (re.compile(r"\bP0\b"), "historical P0 phase label"),
    (re.compile(r"plans/AGENTOOLS"), "internal plan path"),
    (re.compile(r"00_MASTER_PLAN"), "vault plan reference"),
    (re.compile(r"\bP10\.\d\b"), "internal item id"),
    (re.compile(r"MCP\s+compliant", re.I), "forbidden MCP certification claim"),
    (
        re.compile(
            r"exactly[- ]once(?!(?:\s+external\s+side\s+effects?\s+are\s+not|\s+is\s+not))",
            re.I,
        ),
        "unqualified exactly-once",
    ),
    (re.compile(r"packagist\.org/packages", re.I), "public registry URL"),
    (re.compile(r"pub\.dev/packages", re.I), "public registry URL"),
]

REQUIRED_SUBSTRINGS = [
    ("README.md", "2026-09"),
    ("README.md", "proprietary"),
    ("SUPPORT.md", "private"),
    ("SECURITY.md", "report"),
]

SNIPPET_CHECKS = [
    ("php/README.md", "bash", ["composer", "test", "--working-dir=php"], None),
    ("dart/agentools/README.md", "bash", ["dart", "analyze"], "dart/agentools"),
]


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def load_matrix() -> dict[str, Any]:
    if not MATRIX.is_file():
        raise RuntimeError(f"missing matrix: {MATRIX}")
    return json.loads(MATRIX.read_text(encoding="utf-8"))


def scan_lexicon() -> list[str]:
    errors: list[str] = []
    for rel in PUBLIC_DOCS:
        path = REPO / rel
        if not path.is_file():
            errors.append(f"missing doc: {rel}")
            continue
        text = path.read_text(encoding="utf-8")
        if re.search(r"revision\s+`?2026-08`?\s+is\s+current", text, re.I):
            errors.append(f"stale spec current claim: {rel}")
        if re.search(r"идёт\s+P0", text, re.I):
            errors.append(f"P0 wording: {rel}")
        for pat, label in FORBIDDEN_PATTERNS:
            if pat.search(text):
                errors.append(f"{rel}: {label}")
    for rel, needle in REQUIRED_SUBSTRINGS:
        path = REPO / rel
        if path.is_file() and needle not in path.read_text(encoding="utf-8"):
            errors.append(f"{rel}: missing required phrase {needle!r}")
    return errors


def matrix_linkage(matrix: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    row_ids = {r["id"] for r in matrix.get("rows", [])}
    for rel in PUBLIC_DOCS:
        path = REPO / rel
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8")
        for gid in re.findall(r"G-[A-Z]+-\d{3}", text):
            if gid not in row_ids:
                errors.append(f"{rel}: undocumented guarantee reference {gid}")
    return errors


def run_snippet_smoke() -> list[str]:
    errors: list[str] = []
    for rel, _lang, argv, cwd_rel in SNIPPET_CHECKS:
        cwd = REPO / (cwd_rel or ".")
        proc = subprocess.run(argv, cwd=cwd, capture_output=True, text=True, check=False)
        if proc.returncode != 0:
            errors.append(f"snippet smoke failed for {rel}: {proc.stderr[:200]}")
    return errors


def build_receipt() -> dict[str, Any]:
    matrix = load_matrix()
    errors = scan_lexicon() + matrix_linkage(matrix) + run_snippet_smoke()
    docs = []
    for rel in PUBLIC_DOCS:
        path = REPO / rel
        if path.is_file():
            docs.append({"path": rel, "digest": sha256_file(path), "bytes": path.stat().st_size})
    payload = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": now_iso(),
        "matrix_digest": sha256_file(MATRIX),
        "documents": docs,
        "errors": errors,
        "verdict": "green" if not errors else "red",
    }
    payload["manifest_sha256"] = hashlib.sha256(canonical_json(payload).encode("utf-8")).hexdigest()
    return payload


def render_finding(doc: dict[str, Any]) -> str:
    err_lines = [f"- {e}" for e in doc.get("errors", [])] or ["- none"]
    return "\n".join(
        [
            "# P10.2 documentation gate",
            "",
            f"**Verdict:** {doc.get('verdict')}",
            f"**Documents:** {len(doc.get('documents', []))}",
            f"**Matrix digest:** `{doc.get('matrix_digest')}`",
            "",
            "## Errors",
            "",
            *err_lines,
            "",
        ]
    ) + "\n"


def cmd_run(_: argparse.Namespace) -> int:
    doc = build_receipt()
    ARTIFACT.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT.write_text(canonical_json(doc) + "\n", encoding="utf-8")
    FINDING.write_text(render_finding(doc), encoding="utf-8")
    print(canonical_json({"verdict": doc["verdict"], "errors": doc["errors"]}))
    return 0 if doc["verdict"] == "green" else 1


class DocsGateTests(unittest.TestCase):
    def test_public_docs_list_present(self) -> None:
        missing = [rel for rel in PUBLIC_DOCS if not (REPO / rel).is_file()]
        self.assertEqual([], missing)

    def test_forbidden_mcp_cert_in_fixture(self) -> None:
        sample = "This SDK is MCP compliant."
        self.assertTrue(any(p.search(sample) for p, _ in FORBIDDEN_PATTERNS))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run", help="scan docs and write P10-docs.json")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "run":
        return cmd_run(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(DocsGateTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
