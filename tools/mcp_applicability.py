#!/usr/bin/env python3
"""Generate and validate official MCP applicability matrix for 2026-07-28."""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
MCP_ROOT = REPO / "conformance/mcp"
YAML_PATH = MCP_ROOT / "node_modules/@modelcontextprotocol/conformance/requirements/2026-07-28.yaml"
MATRIX_PATH = MCP_ROOT / "applicability-2026-07-28.json"
PIN_VERSION = "0.2.0-alpha.11"
PIN_INTEGRITY = (
    "sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA=="
)

APPLICABLE_CLIENT = {
    "tools_call",
}


def parse_requirements_yaml(text: str) -> list[dict]:
    rows: list[dict] = []
    section: str | None = None
    current: dict | None = None
    for line in text.splitlines():
        if line.startswith("server:"):
            section = "server"
            continue
        if line.startswith("client:"):
            section = "client"
            continue
        if line.startswith("not_scored:"):
            section = "not_scored"
            continue
        if section in ("server", "client") and line.strip().startswith("- "):
            scenario = line.strip()[2:].strip()
            rows.append(
                {
                    "scenario_id": scenario,
                    "role": section,
                    "scored_class": "required",
                }
            )
            continue
        if section == "not_scored":
            if line.strip().startswith("- scenario:"):
                if current:
                    rows.append(current)
                current = {
                    "scenario_id": line.split(":", 1)[1].strip(),
                    "role": "unknown",
                    "scored_class": "extension",
                }
                continue
            if current is not None and line.strip().startswith("leg:"):
                current["role"] = line.split(":", 1)[1].strip()
            if current is not None and line.strip().startswith("reason:"):
                current["scored_class"] = line.split(":", 1)[1].strip()
    if current:
        rows.append(current)
    return rows


def classify(row: dict) -> dict:
    scenario = row["scenario_id"]
    role = row["role"]
    scored = row["scored_class"]
    if role == "server":
        applicability = "non_applicable"
        reason = "repository ships no MCP server; server-role requirements are not product claims (D11)"
    elif scenario in APPLICABLE_CLIENT:
        applicability = "applicable"
        reason = "exercised through public agentools_mcp_dart McpDartTransport boundary"
    elif scenario == "sep-2322-client-request-state":
        applicability = "non_applicable"
        reason = (
            "MRTR request-state isolation probes require wire-level client behavior; "
            "public McpDartTransport exposes invoke/resume only for product tool calls"
        )
    elif scenario == "request-metadata":
        applicability = "non_applicable"
        reason = "unsupported-protocol retry probe is not exposed on public McpDartTransport (require2026-only)"
    elif scenario.startswith("auth/"):
        applicability = "non_applicable"
        reason = "agentools_mcp_dart exposes no OAuth client surface"
    elif scenario.startswith("http-") or scenario == "json-schema-ref-no-deref":
        applicability = "non_applicable"
        reason = "HTTP header mapping is not on the public McpDartTransport contract"
    elif scored in ("extension", "added-after-release", "pending"):
        applicability = "non_applicable"
        reason = f"not_scored row ({scored}); outside bounded agentools_mcp_dart claim"
    else:
        applicability = "non_applicable"
        reason = "not reachable through declared agentools_mcp_dart client boundary"
    receipt = (
        f"conformance/reports/mcp-official/{scenario}.json"
        if applicability == "applicable"
        else None
    )
    return {
        **row,
        "requirements_revision": "2026-07-28",
        "applicability": applicability,
        "reason": reason,
        "receipt_path": receipt,
        "execution_status": "pending" if applicability == "applicable" else None,
    }


def inventory_from_list_output(text: str) -> set[str]:
    ids: set[str] = set()
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("- "):
            token = line[2:].strip()
            if " [" in token:
                token = token.split(" [", 1)[0]
            ids.add(token)
    return ids


def build_matrix() -> dict:
    if not YAML_PATH.is_file():
        raise SystemExit(f"missing pinned requirements at {YAML_PATH}; run npm ci in conformance/mcp")
    rows = [classify(row) for row in parse_requirements_yaml(YAML_PATH.read_text(encoding="utf-8"))]
    rows.sort(key=lambda r: (r["role"], r["scenario_id"]))
    return {
        "schema_version": "mcp-applicability/v1",
        "summary_phrase": "official MCP scenario compatibility",
        "conformance_package": f"@modelcontextprotocol/conformance@{PIN_VERSION}",
        "conformance_integrity": PIN_INTEGRITY,
        "requirements_revision": "2026-07-28",
        "generated_by": "tools/mcp_applicability.py",
        "rows": rows,
    }


def verify_inventory(matrix: dict) -> None:
    proc = subprocess.run(
        ["npm", "exec", "--", "conformance", "list", "--requirements", "2026-07-28"],
        cwd=MCP_ROOT,
        check=False,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise SystemExit(f"conformance list failed: {proc.stderr}")
    listed = inventory_from_list_output(proc.stdout)
    matrix_ids = {row["scenario_id"] for row in matrix["rows"]}
    missing = sorted(listed - matrix_ids)
    extra = sorted(matrix_ids - listed)
    if missing or extra:
        raise SystemExit(f"inventory drift missing={missing} extra={extra}")


def main() -> int:
    mode = "--check" if "--check" in sys.argv else "--write"
    matrix = build_matrix()
    rendered = json.dumps(matrix, indent=2, ensure_ascii=False) + "\n"
    if mode == "--write":
        MATRIX_PATH.write_text(rendered, encoding="utf-8")
        print(f"mcp_applicability: wrote {MATRIX_PATH} ({len(matrix['rows'])} rows)")
        return 0
    if MATRIX_PATH.read_text(encoding="utf-8") != rendered:
        print("mcp_applicability: stale matrix", file=sys.stderr)
        return 1
    verify_inventory(matrix)
    print("mcp_applicability: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
