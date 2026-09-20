#!/usr/bin/env python3
"""Pre-refactor baseline collector for PLAN1 P1.1 (stdlib-only)."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable

SCHEMA_VERSION = "agentools.hardening-baseline/v1"
ROOT = Path(__file__).resolve().parents[1]

ENV_ALLOWLIST = (
    "HOME",
    "LANG",
    "LC_ALL",
    "PATH",
    "PWD",
    "SHELL",
    "TERM",
    "USER",
    "CI",
    "GITHUB_ACTIONS",
    "PHP_BINARY",
    "COMPOSER_HOME",
    "PUB_CACHE",
)

DEFAULT_PROBE_TIMEOUT_S = 1800


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_text(text: str) -> str:
    return sha256_bytes(text.encode("utf-8"))


def canonical_json(document: Any) -> str:
    return json.dumps(document, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def disposition_for(exit_code: int | None, error: str | None) -> str:
    if error:
        return "unavailable"
    if exit_code == 0:
        return "green"
    return "red"


def redact_output(text: str) -> str:
    patterns = (
        (re.compile(r"(?i)(api[_-]?key|token|secret|password)\s*[:=]\s*\S+"), r"\1=<redacted>"),
        (re.compile(r"Bearer\s+[A-Za-z0-9._~+/=-]+"), "Bearer <redacted>"),
    )
    result = text
    for pattern, repl in patterns:
        result = pattern.sub(repl, result)
    return result


def collect_env_snapshot() -> dict[str, Any]:
    present = [name for name in ENV_ALLOWLIST if name in os.environ]
    return {"allowlisted_names_present": sorted(present)}


@dataclass(frozen=True)
class Probe:
    probe_id: str
    title: str
    argv: list[str]
    cwd: str
    setup: Callable[[Path], None] | None = None


def probe_conformance_install(worktree: Path) -> None:
    run_simple(
        worktree,
        ["composer", "install", "--working-dir=conformance", "--no-interaction", "--prefer-dist"],
        timeout_s=600,
    )


def run_simple(worktree: Path, argv: list[str], timeout_s: int) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        argv,
        cwd=worktree,
        capture_output=True,
        text=True,
        timeout=timeout_s,
        check=False,
    )


def default_probes() -> list[Probe]:
    return [
        Probe("ci.layer1", "ci_gate layer 1", ["python3", "tools/ci_gate.py", "--layer", "1"], ".", probe_conformance_install),
        Probe("ci.layer2", "ci_gate layer 2", ["python3", "tools/ci_gate.py", "--layer", "2"], ".", None),
        Probe("ci.layer3", "ci_gate layer 3", ["python3", "tools/ci_gate.py", "--layer", "3"], ".", None),
        Probe("ci.layer4", "ci_gate layer 4", ["python3", "tools/ci_gate.py", "--layer", "4"], ".", None),
        Probe(
            "php.composer.install",
            "PHP composer install",
            ["composer", "install", "--no-interaction", "--prefer-dist"],
            "php",
            None,
        ),
        Probe("php.composer.validate", "PHP composer validate", ["composer", "validate", "--strict"], "php", None),
        Probe("php.test", "PHP phpunit", ["composer", "test"], "php", None),
        Probe("php.analyse", "PHP phpstan", ["composer", "analyse"], "php", None),
        Probe(
            "php.archive",
            "PHP composer archive",
            ["composer", "archive", "--format=zip", "--file=../out/baseline-php.zip"],
            "php",
            None,
        ),
        Probe("dart.core.pub_get", "Dart core pub get", ["dart", "pub", "get"], "dart/agentools", None),
        Probe("dart.core.analyze", "Dart core analyze", ["dart", "analyze", "--fatal-infos"], "dart/agentools", None),
        Probe("dart.core.test", "Dart core test", ["dart", "test"], "dart/agentools", None),
        Probe("dart.core.publish_dry_run", "Dart core publish dry-run", ["dart", "pub", "publish", "--dry-run"], "dart/agentools", None),
        Probe("dart.mcp.pub_get", "Dart MCP pub get", ["dart", "pub", "get"], "dart/agentools_mcp_dart", None),
        Probe("dart.mcp.analyze", "Dart MCP analyze", ["dart", "analyze", "--fatal-infos"], "dart/agentools_mcp_dart", None),
        Probe("dart.mcp.test", "Dart MCP test", ["dart", "test"], "dart/agentools_mcp_dart", None),
        Probe(
            "dart.mcp.publish_dry_run",
            "Dart MCP publish dry-run",
            ["dart", "pub", "publish", "--dry-run"],
            "dart/agentools_mcp_dart",
            None,
        ),
    ]


def run_probe(worktree: Path, probe: Probe, timeout_s: int) -> dict[str, Any]:
    cwd = worktree / probe.cwd
    started = time.monotonic()
    error: str | None = None
    exit_code: int | None = None
    stdout = ""
    stderr = ""
    try:
        if probe.setup is not None:
            probe.setup(worktree)
        result = subprocess.run(
            probe.argv,
            cwd=cwd,
            capture_output=True,
            text=True,
            timeout=timeout_s,
            check=False,
        )
        exit_code = result.returncode
        stdout = redact_output(result.stdout or "")
        stderr = redact_output(result.stderr or "")
    except (OSError, subprocess.TimeoutExpired) as exc:
        error = type(exc).__name__
        if isinstance(exc, subprocess.TimeoutExpired):
            stdout = redact_output((exc.stdout or b"").decode("utf-8", errors="replace"))
            stderr = redact_output((exc.stderr or b"").decode("utf-8", errors="replace"))
    duration_ms = int((time.monotonic() - started) * 1000)
    combined = stdout + ("\n" if stdout and stderr else "") + stderr
    excerpt = combined[:8000]
    return {
        "probe_id": probe.probe_id,
        "title": probe.title,
        "argv": probe.argv,
        "cwd": probe.cwd,
        "duration_ms": duration_ms,
        "exit_code": exit_code,
        "disposition": disposition_for(exit_code, error),
        "error": error,
        "stdout_sha256": sha256_text(stdout),
        "stderr_sha256": sha256_text(stderr),
        "combined_output_sha256": sha256_text(combined),
        "combined_output_excerpt": excerpt,
    }


def git_head_commit(repo_root: Path) -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout.strip()


def git_tree_status(repo_root: Path) -> dict[str, Any]:
    result = subprocess.run(
        ["git", "status", "--porcelain"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=True,
    )
    lines = [line for line in result.stdout.splitlines() if line.strip()]
    return {"porcelain_lines": lines, "dirty": bool(lines)}


def tool_versions(repo_root: Path) -> dict[str, str]:
    versions: dict[str, str] = {}
    for name, argv in (
        ("python3", ["python3", "--version"]),
        ("git", ["git", "--version"]),
        ("php", ["php", "-v"]),
        ("composer", ["composer", "--version"]),
        ("dart", ["dart", "--version"]),
    ):
        try:
            result = subprocess.run(argv, cwd=repo_root, capture_output=True, text=True, check=False, timeout=30)
            versions[name] = (result.stdout or result.stderr or "").strip().splitlines()[0]
        except (OSError, subprocess.TimeoutExpired):
            versions[name] = "unavailable"
    return versions


def php_reachable_classes(worktree: Path) -> list[str]:
    classes: list[str] = []
    for base, ns_prefix in (("php/src", "AgenTools\\"), ("php/reference", "AgenTools\\Reference\\")):
        root = worktree / base
        if not root.exists():
            continue
        for path in sorted(root.rglob("*.php")):
            rel = path.relative_to(root).with_suffix("")
            class_name = ns_prefix + str(rel).replace("/", "\\")
            classes.append(class_name)
    return sorted(classes)


def dart_barrel_exports(worktree: Path) -> dict[str, list[str]]:
    exports: dict[str, list[str]] = {}
    for package_rel in ("dart/agentools", "dart/agentools_mcp_dart"):
        lib = worktree / package_rel / "lib"
        if not lib.exists():
            continue
        package_exports: list[str] = []
        for dart_file in sorted(lib.glob("*.dart")):
            text = dart_file.read_text(encoding="utf-8")
            for match in re.finditer(r"^export\s+'([^']+)';", text, flags=re.MULTILINE):
                package_exports.append(f"{dart_file.name}:{match.group(1)}")
        exports[package_rel] = sorted(package_exports)
    return exports


def archive_inventory(worktree: Path, probes: list[dict[str, Any]]) -> dict[str, Any]:
    inventories: dict[str, Any] = {}
    php_zip = worktree / "out" / "baseline-php.zip"
    if php_zip.exists():
        import zipfile

        with zipfile.ZipFile(php_zip) as zf:
            inventories["php.composer.archive"] = sorted(zf.namelist())
    for probe in probes:
        if probe["probe_id"] in {"dart.core.publish_dry_run", "dart.mcp.publish_dry_run"} and probe["disposition"] == "green":
            lines = [
                line.strip()
                for line in probe.get("combined_output_excerpt", "").splitlines()
                if line.strip().startswith("|--") or line.strip().endswith(".dart")
            ]
            inventories[probe["probe_id"]] = lines
    return inventories


def vector_counts(worktree: Path) -> dict[str, int]:
    fixtures = worktree / "fixtures" / "2026-08"
    count = 0
    if fixtures.exists():
        count = sum(1 for path in fixtures.rglob("*.json") if path.is_file())
    return {"fixture_json_files": count}


def dependency_locks(worktree: Path) -> dict[str, str]:
    paths = (
        "php/composer.lock",
        "dart/agentools/pubspec.lock",
        "dart/agentools_mcp_dart/pubspec.lock",
        "conformance/composer.lock",
    )
    locks: dict[str, str] = {}
    for rel in paths:
        path = worktree / rel
        if path.exists():
            locks[rel] = sha256_bytes(path.read_bytes())
    return locks


def create_worktree(repo_root: Path, commit: str) -> tuple[Path, Path]:
    temp_base = Path(tempfile.mkdtemp(prefix="agentools-baseline-"))
    worktree = temp_base / "tree"
    (worktree.parent / "out").mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["git", "worktree", "add", "--detach", str(worktree), commit],
        cwd=repo_root,
        check=True,
        capture_output=True,
        text=True,
    )
    (worktree / "out").mkdir(parents=True, exist_ok=True)
    return temp_base, worktree


def remove_worktree(repo_root: Path, temp_base: Path, worktree: Path) -> list[str]:
    errors: list[str] = []
    try:
        subprocess.run(
            ["git", "worktree", "remove", "--force", str(worktree)],
            cwd=repo_root,
            capture_output=True,
            text=True,
            check=False,
        )
    except OSError as exc:
        errors.append(f"worktree remove: {exc}")
    try:
        shutil.rmtree(temp_base, ignore_errors=False)
    except OSError as exc:
        errors.append(f"rmtree: {exc}")
    return errors


def build_manifest(repo_root: Path, commit: str, probes: list[dict[str, Any]], worktree: Path) -> dict[str, Any]:
    inventory = {
        "php_psr4_reachable_classes": php_reachable_classes(worktree),
        "dart_barrel_exports": dart_barrel_exports(worktree),
        "archive_listings": archive_inventory(worktree, probes),
        "vector_counts": vector_counts(worktree),
        "dependency_lock_sha256": dependency_locks(worktree),
    }
    inventory_digest = sha256_text(canonical_json(inventory))
    manifest = {
        "schema_version": SCHEMA_VERSION,
        "recorded_commit": commit,
        "captured_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "source_tree_status_at_capture_anchor": git_tree_status(repo_root),
        "tool_versions": tool_versions(repo_root),
        "environment": collect_env_snapshot(),
        "probes": sorted(probes, key=lambda row: row["probe_id"]),
        "inventory": inventory,
        "inventory_digest_sha256": inventory_digest,
        "known_anomalies": [
            {
                "id": "publish_to_none",
                "note": "Dart packages and PHP license are private/proprietary; publish dry-run may be red by policy.",
            }
        ],
    }
    manifest["manifest_digest_sha256"] = sha256_text(
        canonical_json({k: v for k, v in manifest.items() if k != "manifest_digest_sha256"})
    )
    return manifest


def render_report(manifest: dict[str, Any]) -> str:
    lines = [
        "# P1 pre-refactor baseline",
        "",
        f"- Recorded commit: `{manifest['recorded_commit']}`",
        f"- Captured at (UTC): {manifest['captured_at_utc']}",
        f"- Manifest digest: `{manifest['manifest_digest_sha256']}`",
        f"- Inventory digest: `{manifest['inventory_digest_sha256']}`",
        "",
        "## Probe matrix",
        "",
        "| probe_id | disposition | exit | duration_ms |",
        "|:--|:--|:--:|--:|",
    ]
    for probe in manifest["probes"]:
        exit_code = probe["exit_code"] if probe["exit_code"] is not None else "—"
        lines.append(
            f"| `{probe['probe_id']}` | {probe['disposition']} | {exit_code} | {probe['duration_ms']} |"
        )
    inv = manifest["inventory"]
    lines.extend(
        [
            "",
            "## Inventory",
            "",
            f"- PHP reachable classes (candidate surface, not stable API): **{len(inv['php_psr4_reachable_classes'])}**",
            f"- Fixture JSON files: **{inv['vector_counts'].get('fixture_json_files', 0)}**",
            "",
            "### Dependency lock SHA-256",
            "",
        ]
    )
    for path, digest in sorted(inv["dependency_lock_sha256"].items()):
        lines.append(f"- `{path}`: `{digest}`")
    lines.extend(["", "### Dart barrel exports", ""])
    for pkg, exports in sorted(inv["dart_barrel_exports"].items()):
        lines.append(f"**{pkg}** ({len(exports)} export lines)")
        for entry in exports[:20]:
            lines.append(f"- {entry}")
        if len(exports) > 20:
            lines.append(f"- … and {len(exports) - 20} more")
    lines.extend(["", "## Environmental limits", "", "- Disposable git worktree at recorded commit.", "- Probe timeouts may mark long jobs unavailable.", ""])
    return "\n".join(lines) + "\n"


def cmd_capture(output: Path, report: Path, timeout_s: int) -> int:
    repo_root = ROOT
    commit = git_head_commit(repo_root)
    temp_base: Path | None = None
    worktree: Path | None = None
    cleanup_errors: list[str] = []
    try:
        temp_base, worktree = create_worktree(repo_root, commit)
        probes = [run_probe(worktree, probe, timeout_s) for probe in default_probes()]
        manifest = build_manifest(repo_root, commit, probes, worktree)
        output.parent.mkdir(parents=True, exist_ok=True)
        report.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(canonical_json(manifest) + "\n", encoding="utf-8")
        report.write_text(render_report(manifest), encoding="utf-8")
    finally:
        if temp_base is not None and worktree is not None:
            cleanup_errors = remove_worktree(repo_root, temp_base, worktree)
    if cleanup_errors:
        print("\n".join(cleanup_errors), file=sys.stderr)
        return 1
    print(f"baseline written: {output}")
    print(f"report written: {report}")
    return 0


def cmd_verify(baseline: Path) -> int:
    if not baseline.exists():
        print(f"missing baseline: {baseline}", file=sys.stderr)
        return 1
    manifest = json.loads(baseline.read_text(encoding="utf-8"))
    recomputed = sha256_text(
        canonical_json({k: v for k, v in manifest.items() if k != "manifest_digest_sha256"})
    )
    if recomputed != manifest.get("manifest_digest_sha256"):
        print("manifest digest mismatch", file=sys.stderr)
        return 1
    probes = manifest.get("probes", [])
    if not probes:
        print("no probes recorded", file=sys.stderr)
        return 1
    for probe in probes:
        if probe.get("disposition") not in {"green", "red", "unavailable"}:
            print(f"invalid disposition: {probe.get('probe_id')}", file=sys.stderr)
            return 1
    print(f"verify ok: commit={manifest.get('recorded_commit')} probes={len(probes)}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    capture = sub.add_parser("capture", help="capture baseline into JSON + Markdown report")
    capture.add_argument("--output", type=Path, required=True)
    capture.add_argument("--report", type=Path, required=True)
    capture.add_argument("--timeout-s", type=int, default=DEFAULT_PROBE_TIMEOUT_S)
    verify = sub.add_parser("verify", help="verify baseline JSON integrity")
    verify.add_argument("--baseline", type=Path, required=True)
    args = parser.parse_args(argv)
    if args.command == "capture":
        return cmd_capture(args.output, args.report, args.timeout_s)
    if args.command == "verify":
        return cmd_verify(args.baseline)
    return 2


class HardeningBaselineTests(unittest.TestCase):
    def test_canonical_json_sorted(self) -> None:
        payload = {"b": 1, "a": 2}
        self.assertEqual(canonical_json(payload), '{"a":2,"b":1}')

    def test_disposition(self) -> None:
        self.assertEqual(disposition_for(0, None), "green")
        self.assertEqual(disposition_for(1, None), "red")
        self.assertEqual(disposition_for(None, "TimeoutExpired"), "unavailable")

    def test_redact_output(self) -> None:
        text = "api_key=supersecret Bearer abc.def"
        redacted = redact_output(text)
        self.assertNotIn("supersecret", redacted)
        self.assertIn("<redacted>", redacted)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "-m":
        raise SystemExit(unittest.main(argv=sys.argv[2:]))
    raise SystemExit(main())
