#!/usr/bin/env python3
"""Generate and validate official MCP applicability matrix for 2026-07-28."""
from __future__ import annotations

import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
MCP_ROOT = REPO / "conformance/mcp"
YAML_PATH = MCP_ROOT / "node_modules/@modelcontextprotocol/conformance/requirements/2026-07-28.yaml"
MATRIX_PATH = MCP_ROOT / "applicability-2026-07-28.json"
PIN_VERSION = "0.2.0-alpha.11"
PIN_INTEGRITY = (
    "sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA=="
)
LOCAL_BINARY = MCP_ROOT / "node_modules/.bin/conformance"

APPLICABLE_CLIENT = {
    "http-custom-headers",
    "http-invalid-tool-headers",
    "http-standard-headers",
    "json-schema-ref-no-deref",
    "request-metadata",
    "sep-2322-client-request-state",
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
    elif scenario.startswith("auth/"):
        applicability = "non_applicable"
        reason = "public agentools_mcp_dart exposes no auth or OAuth configuration boundary"
    elif scored in ("extension", "added-after-release", "pending"):
        applicability = "non_applicable"
        reason = (
            f"not_scored row ({scored}); outside the frozen public agentools_mcp_dart boundary"
        )
    else:
        applicability = "non_applicable"
        reason = (
            "scenario requires a client operation outside public connect, discoverTools, "
            "invokeTool and clarification-resume boundaries"
        )
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
        "execution_status": "pending" if applicability == "applicable" else "not_run",
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
        "schema_version": "mcp-applicability/v2",
        "summary_phrase": "official MCP scenario compatibility",
        "conformance_package": f"@modelcontextprotocol/conformance@{PIN_VERSION}",
        "conformance_integrity": PIN_INTEGRITY,
        "requirements_revision": "2026-07-28",
        "frozen_required_client_total": 32,
        "generated_by": "tools/mcp_applicability.py",
        "rows": rows,
    }


def local_binary(path: Path = LOCAL_BINARY) -> Path:
    if not path.is_file():
        raise FileNotFoundError(
            f"missing local MCP conformance binary: {path}; run npm ci --ignore-scripts --prefix conformance/mcp"
        )
    return path


def verify_inventory_sets(matrix_ids: set[str], listed: set[str]) -> None:
    missing = sorted(listed - matrix_ids)
    extra = sorted(matrix_ids - listed)
    if missing or extra:
        raise ValueError(f"inventory drift missing={missing} extra={extra}")


def verify_inventory(matrix: dict) -> None:
    binary = local_binary()
    proc = subprocess.run(
        [str(binary), "list", "--requirements", "2026-07-28"],
        cwd=MCP_ROOT,
        check=False,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise SystemExit(f"conformance list failed: {proc.stderr}")
    listed = inventory_from_list_output(proc.stdout)
    matrix_ids = {row["scenario_id"] for row in matrix["rows"]}
    try:
        verify_inventory_sets(matrix_ids, listed)
    except ValueError as error:
        raise SystemExit(str(error)) from error


def verify_driver_scenarios(actual: set[str]) -> None:
    if actual != APPLICABLE_CLIENT:
        raise ValueError(
            f"driver scenario drift missing={sorted(APPLICABLE_CLIENT - actual)} "
            f"extra={sorted(actual - APPLICABLE_CLIENT)}"
        )


def verify_driver() -> None:
    proc = subprocess.run(
        ["dart", "run", "tool/conformance_client.dart", "--list-scenarios"],
        cwd=REPO / "dart/agentools_mcp_dart",
        check=False,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise SystemExit(f"conformance driver inventory failed: {proc.stderr}")
    try:
        verify_driver_scenarios(set(proc.stdout.splitlines()))
    except ValueError as error:
        raise SystemExit(str(error)) from error


def validate_matrix(matrix: dict) -> None:
    if matrix.get("schema_version") != "mcp-applicability/v2":
        raise ValueError("matrix must use schema v2")
    applicable = {row["scenario_id"] for row in matrix["rows"] if row.get("applicability") == "applicable"}
    if applicable != APPLICABLE_CLIENT:
        raise ValueError(f"applicable set drift: {sorted(applicable)}")
    for row in matrix["rows"]:
        applicability = row.get("applicability")
        execution = row.get("execution_status")
        if applicability not in {"applicable", "non_applicable", "blocked_dependency"}:
            raise ValueError(f"mixed applicability axis: {row.get('scenario_id')}")
        if execution not in {"pending", "passed", "failed", "not_run"}:
            raise ValueError(f"invalid execution axis: {row.get('scenario_id')}")
        if applicability == "applicable" and not row.get("receipt_path"):
            raise ValueError(f"applicable row lacks receipt: {row.get('scenario_id')}")
        if applicability != "applicable":
            reason = row.get("reason", "")
            if len(reason) < 24 or "boundary" not in reason and "server" not in reason:
                raise ValueError(f"non-applicable row lacks concrete boundary reason: {row.get('scenario_id')}")


def summarize_receipts(matrix: dict, reports_dir: Path) -> dict:
    validate_matrix(matrix)
    rows = []
    for source in matrix["rows"]:
        row = dict(source)
        if row["applicability"] == "applicable":
            receipt = reports_dir / f"{row['scenario_id']}.json"
            if not receipt.is_file():
                raise ValueError(f"applicable receipt missing: {row['scenario_id']}")
            data = json.loads(receipt.read_text(encoding="utf-8"))
            row["execution_status"] = "passed" if data.get("passed") is True else "failed"
        rows.append(row)
    applicable = [row for row in rows if row["applicability"] == "applicable"]
    passed = sum(row["execution_status"] == "passed" for row in applicable)
    frozen_total = matrix["frozen_required_client_total"]
    return {
        "schema_version": "mcp-execution-summary/v2",
        "summary_phrase": matrix["summary_phrase"],
        "requirements_revision": matrix["requirements_revision"],
        "conformance_package": matrix["conformance_package"],
        "applicable_passed": passed,
        "applicable_total": len(applicable),
        "frozen_required_client_total": frozen_total,
        "summary": f"{passed}/{len(applicable)} applicable; {len(applicable)}/{frozen_total} frozen required client scenarios applicable",
        "rows": rows,
    }


class ApplicabilityTests(unittest.TestCase):
    def test_missing_local_binary_is_actionable(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(FileNotFoundError, "npm ci --ignore-scripts"):
                local_binary(Path(directory) / "conformance")

    def test_applicable_receipt_is_required(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "receipt missing"):
                summarize_receipts(build_matrix(), Path(directory))

    def test_failed_receipt_stays_failed_without_mutating_applicability(self) -> None:
        matrix = build_matrix()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for scenario in APPLICABLE_CLIENT:
                (root / f"{scenario}.json").write_text(
                    json.dumps({"passed": scenario != "tools_call"}), encoding="utf-8"
                )
            summary = summarize_receipts(matrix, root)
        row = next(row for row in summary["rows"] if row["scenario_id"] == "tools_call")
        self.assertEqual("applicable", row["applicability"])
        self.assertEqual("failed", row["execution_status"])

    def test_mixed_axes_are_rejected(self) -> None:
        matrix = build_matrix()
        matrix["rows"][0]["applicability"] = "passed"
        with self.assertRaisesRegex(ValueError, "mixed applicability"):
            validate_matrix(matrix)

    def test_driver_scenario_set_is_exact(self) -> None:
        self.assertEqual(7, len(APPLICABLE_CLIENT))
        with self.assertRaisesRegex(ValueError, "driver scenario drift"):
            verify_driver_scenarios(APPLICABLE_CLIENT - {"tools_call"})

    def test_non_applicable_reason_names_boundary(self) -> None:
        matrix = build_matrix()
        row = next(row for row in matrix["rows"] if row["applicability"] == "non_applicable")
        row["reason"] = "unsupported"
        with self.assertRaisesRegex(ValueError, "concrete boundary reason"):
            validate_matrix(matrix)

    def test_inventory_drift_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "inventory drift"):
            verify_inventory_sets({"tools_call"}, {"tools_call", "new-scenario"})


def main() -> int:
    if "test" in sys.argv:
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ApplicabilityTests)
        return 0 if unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful() else 1
    if "--summarize" in sys.argv:
        index = sys.argv.index("--summarize")
        try:
            reports_dir = Path(sys.argv[index + 1])
            summary_path = Path(sys.argv[index + 2])
        except IndexError as error:
            raise SystemExit("--summarize requires REPORTS_DIR SUMMARY_PATH") from error
        matrix = json.loads(MATRIX_PATH.read_text(encoding="utf-8"))
        try:
            summary = summarize_receipts(matrix, reports_dir)
        except (OSError, ValueError, json.JSONDecodeError) as error:
            print(f"mcp_applicability: summary failed: {error}", file=sys.stderr)
            return 1
        summary_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        print(f"mcp/run: official MCP scenario compatibility — {summary['summary']}")
        return 0 if summary["applicable_passed"] == summary["applicable_total"] else 1
    mode = "--check" if "--check" in sys.argv else "--write"
    matrix = build_matrix()
    validate_matrix(matrix)
    rendered = json.dumps(matrix, indent=2, ensure_ascii=False) + "\n"
    if mode == "--write":
        MATRIX_PATH.write_text(rendered, encoding="utf-8")
        print(f"mcp_applicability: wrote {MATRIX_PATH} ({len(matrix['rows'])} rows)")
        return 0
    if MATRIX_PATH.read_text(encoding="utf-8") != rendered:
        print("mcp_applicability: stale matrix", file=sys.stderr)
        return 1
    verify_inventory(matrix)
    verify_driver()
    print("mcp_applicability: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
