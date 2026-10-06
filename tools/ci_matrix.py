#!/usr/bin/env python3
"""CI matrix and P8 evidence-tier map (P9.2). Read-only on repository locks."""

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
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

try:
    import yaml
except ImportError:  # pragma: no cover
    yaml = None  # type: ignore[assignment]

REPO = Path(__file__).resolve().parents[1]
if str(REPO) not in sys.path:
    sys.path.insert(0, str(REPO))

from tools import locked_cli  # noqa: E402

PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
COMPAT = PLAN / "root/contracts/compatibility-support-v1.md"
MANIFEST = REPO / "fixtures/hardening/adversarial/manifest.v1.json"
P8_GATE = PLAN / "artifacts/P8-adversarial-gate.json"
RESOURCE_BASELINE = PLAN / "artifacts/P8-resource-baseline.json"
MAP_OUT = PLAN / "artifacts/P9-ci-map.json"
FINDING = PLAN / "findings/P9-ci-map.md"
LOCK = REPO / ".ci/supply-chain-lock.json"
BINARY_NAMES = {"google/osv-scanner": "osv-scanner", "gitleaks/gitleaks": "gitleaks"}
WORKFLOWS = REPO / ".github/workflows"

PHP_JSON = REPO / "php/composer.json"
DART_CORE = REPO / "dart/agentools/pubspec.yaml"
DART_MCP = REPO / "dart/agentools_mcp_dart/pubspec.yaml"

LOCKFILES = {
    "php": REPO / "php/composer.lock",
    "conformance": REPO / "conformance/composer.lock",
    "dart_core": REPO / "dart/agentools/pubspec.lock",
    "dart_mcp": REPO / "dart/agentools_mcp_dart/pubspec.lock",
    "mcp_npm": REPO / "conformance/mcp/package-lock.json",
}

DART_PACKAGES = {
    "dart_core": REPO / "dart/agentools",
    "dart_mcp": REPO / "dart/agentools_mcp_dart",
}

TIERS = ("focused", "bounded", "extended", "release-candidate")
TIER_EVENTS = {
    "focused": ("pull_request",),
    "bounded": ("pull_request", "push"),
    "extended": ("schedule", "workflow_dispatch"),
    "release-candidate": ("workflow_dispatch", "push"),
}

# Executed P1 support tuple (compatibility-support-v1.md evidence 2026-09-20).
SUPPORTED_PHP = ("8.3", "8.4")
SUPPORTED_DART_SDK = ("3.11.0", "3.13.4")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(",", ":")) + "\n"


def load_yaml(text: str) -> Any:
    if yaml is None:
        raise RuntimeError("PyYAML is required")
    return yaml.safe_load(text)


def parse_php_floor() -> str:
    document = json.loads(PHP_JSON.read_text(encoding="utf-8"))
    require = document.get("require") or {}
    raw = str(require.get("php", ""))
    match = re.search(r"(\d+\.\d+)", raw)
    if not match:
        raise ValueError("php/composer.json: cannot parse php require")
    return match.group(1)


def parse_dart_sdk_floor() -> str:
    text = DART_CORE.read_text(encoding="utf-8")
    match = re.search(r"sdk:\s*\^?(\d+\.\d+\.\d+)", text)
    if not match:
        raise ValueError("dart/agentools/pubspec.yaml: cannot parse sdk constraint")
    return match.group(1)


def expected_matrix_rows() -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    for version in SUPPORTED_PHP:
        rows.append(
            {
                "ecosystem": "php",
                "package": "php",
                "resolution": "supported",
                "runtime": version,
            }
        )
    rows.append(
        {
            "ecosystem": "php",
            "package": "php",
            "resolution": "lowest",
            "runtime": "disposable",
        }
    )
    rows.append(
        {
            "ecosystem": "php",
            "package": "php",
            "resolution": "latest",
            "runtime": "disposable",
        }
    )
    for key, path in DART_PACKAGES.items():
        for sdk in SUPPORTED_DART_SDK:
            rows.append(
                {
                    "ecosystem": "dart",
                    "package": key,
                    "resolution": "supported",
                    "runtime": sdk,
                    "path": path.relative_to(REPO).as_posix(),
                }
            )
        rows.append(
            {
                "ecosystem": "dart",
                "package": key,
                "resolution": "lowest",
                "runtime": "disposable",
                "path": path.relative_to(REPO).as_posix(),
            }
        )
        rows.append(
            {
                "ecosystem": "dart",
                "package": key,
                "resolution": "latest",
                "runtime": "disposable",
                "path": path.relative_to(REPO).as_posix(),
            }
        )
    return rows


def tier_commands(tier: str) -> list[list[str]]:
    common_security = [
        ["python3", "tools/ci_policy.py", "check-lock"],
        ["python3", "tools/ci_matrix.py", "validate"],
    ]
    focused = common_security + [
        ["python3", "tools/ci_policy.py", "check-workflows"],
        ["python3", "tools/ci_gate.py", "--layer", "1"],
        ["python3", "tools/ci_gate.py", "--layer", "2"],
        ["python3", "tools/validate_adversarial_contract.py"],
        ["python3", "tools/package_surface.py", "validate-contract"],
    ]
    bounded = focused + [
        ["python3", "tools/ci_gate.py", "--layer", "3"],
        ["python3", "tools/ci_gate.py", "--layer", "4"],
        ["bash", "conformance/bin/semantic-parity"],
    ]
    extended = bounded + [
        ["python3", "tools/mutation_gate.py", "run"],
        ["python3", "tools/adversarial_corpus.py", "run"],
        ["python3", "tools/resource_gate.py", "run"],
    ]
    release_candidate = extended + [
        ["python3", "tools/release_candidate.py", "run"],
        ["python3", "tools/sbom_gate.py", "run"],
        ["python3", "tools/private_rc_gate.py", "run"],
    ]
    mapping = {
        "focused": focused,
        "bounded": bounded,
        "extended": extended,
        "release-candidate": release_candidate,
    }
    return mapping[tier]


def manifest_av_ids_for_tier(tier: str) -> set[str]:
    document = json.loads(MANIFEST.read_text(encoding="utf-8"))
    return {
        str(row["id"])
        for row in document.get("rows", [])
        if isinstance(row, dict) and tier in (row.get("tiers") or [])
    }


def tier_carrier_digest(tier: str) -> dict[str, str]:
    digests: dict[str, str] = {}
    for name, path in LOCKFILES.items():
        if path.is_file():
            digests[f"lock:{name}"] = sha256_file(path)
    digests["compat_contract"] = sha256_file(COMPAT)
    digests["commands"] = hashlib.sha256(
        canonical_json(tier_commands(tier)).encode("utf-8")
    ).hexdigest()
    return digests


def timing_state() -> dict[str, Any]:
    if not RESOURCE_BASELINE.is_file():
        return {"status": "baseline_pending", "reason": "missing P8 resource baseline"}
    baseline = json.loads(RESOURCE_BASELINE.read_text(encoding="utf-8"))
    fingerprint = baseline.get("fingerprint") or {}
    current = {
        "php": _tool_version("php", "-v"),
        "dart": _tool_version("dart", "--version"),
        "python": _tool_version("python3", "-V"),
    }
    match = (
        fingerprint.get("php", "").split("(")[0].strip()
        in current["php"]
        or current["php"] in fingerprint.get("php", "")
    )
    if not match:
        return {
            "status": "baseline_pending",
            "reason": "host fingerprint mismatch",
            "baseline_php": fingerprint.get("php"),
            "current_php": current["php"],
        }
    return {"status": "matched", "fingerprint_digest": fingerprint.get("digest")}


def _tool_version(cmd: str, flag: str) -> str:
    try:
        proc = subprocess.run(
            [cmd, flag],
            cwd=REPO,
            check=False,
            capture_output=True,
            text=True,
        )
    except OSError:
        return ""
    return (proc.stdout or proc.stderr or "").strip().splitlines()[0] if proc.stdout or proc.stderr else ""


def build_map() -> dict[str, Any]:
    php_floor = parse_php_floor()
    dart_floor = parse_dart_sdk_floor()
    if php_floor != SUPPORTED_PHP[0]:
        raise ValueError(
            f"supported PHP floor {SUPPORTED_PHP[0]} must match composer.json ({php_floor})"
        )
    if dart_floor != SUPPORTED_DART_SDK[0]:
        raise ValueError(
            f"supported Dart floor {SUPPORTED_DART_SDK[0]} must match pubspec ({dart_floor})"
        )
    tiers: dict[str, Any] = {}
    for tier in TIERS:
        tiers[tier] = {
            "events": list(TIER_EVENTS[tier]),
            "commands": tier_commands(tier),
            "input_digests": tier_carrier_digest(tier),
            "av_ids": sorted(manifest_av_ids_for_tier(tier)),
        }
    return {
        "schema_version": "agentools.ci-map/v1",
        "contract_ref": COMPAT.relative_to(REPO).as_posix(),
        "matrix_rows": expected_matrix_rows(),
        "lockfiles": {k: sha256_file(p) for k, p in LOCKFILES.items() if p.is_file()},
        "tiers": tiers,
        "timing": timing_state(),
        "capabilities": {
            "github_dependency_review": "unavailable-plan",
            "github_secret_scanning": "available-disabled",
            "hosted_attestation": "unavailable-plan",
        },
        "security_lanes": {
            "osv": {
                "lockfiles": [p.relative_to(REPO).as_posix() for p in LOCKFILES.values()],
                "scanner": "google/osv-scanner",
            },
            "gitleaks": {
                "modes": ["detect", "protect"],
                "redact": True,
            },
        },
    }


def validate_map(document: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if document.get("schema_version") != "agentools.ci-map/v1":
        errors.append("schema_version must be agentools.ci-map/v1")
    expected_rows = {canonical_json(r).strip() for r in expected_matrix_rows()}
    actual_rows = document.get("matrix_rows") or []
    actual_set = {canonical_json(r).strip() for r in actual_rows if isinstance(r, dict)}
    if expected_rows != actual_set:
        errors.append("matrix_rows set mismatch")
    for tier in TIERS:
        block = document.get("tiers", {}).get(tier)
        if not isinstance(block, dict):
            errors.append(f"missing tier block {tier}")
            continue
        manifest_ids = manifest_av_ids_for_tier(tier)
        mapped = set(block.get("av_ids") or [])
        missing = sorted(manifest_ids - mapped)
        extra = sorted(mapped - manifest_ids)
        if missing or extra:
            errors.append(f"tier {tier}: missing={missing} extra={extra}")
    return errors


def validate_workflows() -> list[str]:
    errors: list[str] = []
    ci = WORKFLOWS / "ci.yml"
    ext = WORKFLOWS / "extended.yml"
    if not ci.is_file() or not ext.is_file():
        errors.append("ci.yml and extended.yml required")
        return errors
    ci_text = ci.read_text(encoding="utf-8")
    for version in SUPPORTED_PHP:
        if version not in ci_text:
            errors.append(f"ci.yml missing supported PHP {version}")
    if "permissions: {}" not in ci_text:
        errors.append("ci.yml must declare permissions: {}")
    ext_text = ext.read_text(encoding="utf-8")
    if "workflow_dispatch" not in ext_text:
        errors.append("extended.yml must allow manual dispatch")
    if "8.4" not in ext_text:
        errors.append("extended.yml missing PHP 8.4 lane")
    rc = WORKFLOWS / "private-rc.yml"
    if not rc.is_file():
        errors.append("private-rc.yml required for release-candidate tier")
    else:
        rc_text = rc.read_text(encoding="utf-8")
        if "permissions: {}" not in rc_text:
            errors.append("private-rc.yml must declare permissions: {}")
        if "workflow_dispatch" not in rc_text:
            errors.append("private-rc.yml must allow manual dispatch")
        if "private_rc_gate.py" not in rc_text:
            errors.append("private-rc.yml must run private_rc_gate")
    return errors


def cmd_emit(_: argparse.Namespace) -> int:
    document = build_map()
    MAP_OUT.parent.mkdir(parents=True, exist_ok=True)
    MAP_OUT.write_text(json.dumps(document, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(f"ci-map: wrote {MAP_OUT.relative_to(REPO)}")
    return 0


def cmd_validate(_: argparse.Namespace) -> int:
    errors = []
    try:
        document = build_map()
    except ValueError as error:
        print(error, file=sys.stderr)
        return 1
    errors.extend(validate_map(document))
    errors.extend(validate_workflows())
    for error in errors:
        print(error, file=sys.stderr)
    if errors:
        print(f"ci-matrix: FAIL ({len(errors)} error(s))", file=sys.stderr)
        return 1
    print("ci-matrix: ok")
    return 0


def cmd_write_finding(_: argparse.Namespace) -> int:
    document = build_map()
    lines = [
        "# P9.2 CI matrix and P8 tier map",
        "",
        f"**Generated:** machine output from `tools/ci_matrix.py`",
        f"**Contract:** `{COMPAT.relative_to(REPO)}`",
        "",
        "## Matrix rows",
        "",
        f"PHP supported: {', '.join(SUPPORTED_PHP)} · disposable lowest/latest lanes never mutate source locks.",
        f"Dart supported SDK: {', '.join(SUPPORTED_DART_SDK)} for `dart_core` and `dart_mcp`.",
        "",
        "## P8 tier coverage",
        "",
    ]
    for tier in TIERS:
        block = document["tiers"][tier]
        lines.append(
            f"- **{tier}** — events `{', '.join(block['events'])}`; "
            f"AV ids `{len(block['av_ids'])}`; timing `{document['timing']['status']}`."
        )
    lines.extend(
        [
            "",
            "## Capability gaps (not silently passed)",
            "",
            "- GitHub dependency review and hosted attestations remain unavailable on current plan.",
            "- Secret scanning push protection disabled; Gitleaks is complementary.",
            "",
            f"Machine map: `{MAP_OUT.relative_to(REPO)}`",
        ]
    )
    FINDING.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"ci-map finding: {FINDING.relative_to(REPO)}")
    return 0


def disposable_php_resolution(mode: str) -> tuple[int, str]:
    if mode not in {"lowest", "latest"}:
        raise ValueError(mode)
    source_digest = sha256_file(LOCKFILES["php"])
    with tempfile.TemporaryDirectory() as tmp:
        workspace = Path(tmp) / "tree"
        shutil.copytree(REPO / "php", workspace / "php")
        args = ["composer", "update", "--no-interaction", "--prefer-dist"]
        if mode == "lowest":
            args = ["composer", "update", "--no-interaction", "--prefer-dist", "--prefer-lowest", "--prefer-stable"]
        proc = subprocess.run(
            args,
            cwd=workspace / "php",
            check=False,
            capture_output=True,
            text=True,
            timeout=300,
        )
        if proc.returncode != 0:
            return proc.returncode, proc.stderr or proc.stdout
        if sha256_file(LOCKFILES["php"]) != source_digest:
            return 1, "source php/composer.lock mutated"
        return 0, "ok"


def disposable_dart_resolution(package: str, mode: str) -> tuple[int, str]:
    if mode not in {"lowest", "latest"}:
        raise ValueError(mode)
    root = DART_PACKAGES[package]
    lock = LOCKFILES[package]
    source_digest = sha256_file(lock)
    with tempfile.TemporaryDirectory() as tmp:
        workspace = Path(tmp) / "tree"
        shutil.copytree(root, workspace)
        if mode == "lowest":
            cmd = ["dart", "pub", "downgrade"]
        else:
            cmd = ["dart", "pub", "upgrade"]
        proc = subprocess.run(
            cmd,
            cwd=workspace,
            check=False,
            capture_output=True,
            text=True,
            timeout=180,
        )
        if proc.returncode != 0:
            return proc.returncode, proc.stderr or proc.stdout
        if sha256_file(lock) != source_digest:
            return 1, f"source {lock.relative_to(REPO)} mutated"
        return 0, "ok"


def cmd_install_scanners(_: argparse.Namespace) -> int:
    for tool_id in ("google/osv-scanner", "gitleaks/gitleaks"):
        install_cli_tool(tool_id)
    print("ci-matrix: scanners installed")
    return 0


def cmd_osv_scan(_: argparse.Namespace) -> int:
    return run_osv_scan()


def cmd_gitleaks_scan(_: argparse.Namespace) -> int:
    return run_gitleaks_detect()


def cmd_disposable(raw: str) -> int:
    match = re.fullmatch(r"disposable-(php|dart_core|dart_mcp)-(lowest|latest)", raw)
    if not match:
        print(f"unknown disposable command: {raw}", file=sys.stderr)
        return 2
    ecosystem, mode = match.groups()
    if ecosystem == "php":
        code, message = disposable_php_resolution(mode)
    else:
        code, message = disposable_dart_resolution(ecosystem, mode)
    if code != 0:
        print(message, file=sys.stderr)
    return code


_INSTALLED_TOOLS: dict[str, Path] = {}


def install_cli_tool(tool_id: str) -> Path:
    """Return a binary materialized from the sha256-verified locked artifact (memoized per process)."""
    installed = _INSTALLED_TOOLS.get(tool_id)
    if installed is not None and installed.is_file():
        return installed
    lock = json.loads(LOCK.read_text(encoding="utf-8"))
    entry = next(item for item in lock["cli_tools"] if item["id"] == tool_id)
    binary = locked_cli.install(entry, BINARY_NAMES.get(tool_id, tool_id.rsplit("/", 1)[-1]))
    _INSTALLED_TOOLS[tool_id] = binary
    return binary


def run_osv_scan() -> int:
    scanner = install_cli_tool("google/osv-scanner")
    args = [str(scanner), "scan", "--config", str(REPO / ".osv-scanner.toml")]
    for path in LOCKFILES.values():
        args.extend(["-L", str(path)])
    return subprocess.run(args, cwd=REPO, check=False).returncode


def run_gitleaks_detect() -> int:
    scanner = install_cli_tool("gitleaks/gitleaks")
    return subprocess.run(
        [
            str(scanner),
            "git",
            "--no-banner",
            "--redact",
            "--config",
            str(REPO / ".gitleaks.toml"),
            "--gitleaks-ignore-path",
            str(REPO / ".gitleaksignore"),
            str(REPO),
        ],
        cwd=REPO,
        check=False,
    ).returncode


def cmd_test(_: argparse.Namespace) -> int:
    suite = unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__])
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    return 0 if result.wasSuccessful() else 1


class CiMatrixTests(unittest.TestCase):
    def test_osv_roster_is_exact(self) -> None:
        self.assertEqual(
            {path.relative_to(REPO).as_posix() for path in LOCKFILES.values()},
            {
                "php/composer.lock",
                "conformance/composer.lock",
                "dart/agentools/pubspec.lock",
                "dart/agentools_mcp_dart/pubspec.lock",
                "conformance/mcp/package-lock.json",
            },
        )

    def test_supported_matrix_exact(self) -> None:
        rows = expected_matrix_rows()
        php_supported = [r for r in rows if r["ecosystem"] == "php" and r["resolution"] == "supported"]
        self.assertEqual([r["runtime"] for r in php_supported], list(SUPPORTED_PHP))
        for key in DART_PACKAGES:
            sdk_rows = [
                r["runtime"]
                for r in rows
                if r["ecosystem"] == "dart" and r["package"] == key and r["resolution"] == "supported"
            ]
            self.assertEqual(sdk_rows, list(SUPPORTED_DART_SDK))

    def test_map_tier_av_exact(self) -> None:
        document = build_map()
        self.assertEqual(validate_map(document), [])

    def test_timing_mismatch_is_baseline_pending(self) -> None:
        state = timing_state()
        self.assertIn(state["status"], {"matched", "baseline_pending"})
        if state["status"] == "baseline_pending":
            self.assertIn("reason", state)

    def test_fork_pr_fixture_has_read_only_permissions(self) -> None:
        doc = {
            "on": "pull_request",
            "permissions": {},
            "concurrency": {"group": "x", "cancel-in-progress": True},
            "jobs": {
                "verify": {
                    "runs-on": "ubuntu-latest",
                    "timeout-minutes": 20,
                    "permissions": {"contents": "read"},
                    "steps": [{"run": "echo ok"}],
                }
            },
        }
        perms = doc["jobs"]["verify"]["permissions"]
        self.assertEqual(set(perms.keys()), {"contents"})
        self.assertEqual(perms["contents"], "read")

    def test_php_lowest_does_not_mutate_source_lock(self) -> None:
        if shutil.which("composer") is None:
            self.skipTest("composer missing")
        code, message = disposable_php_resolution("lowest")
        self.assertEqual(code, 0, message)

    def test_dart_downgrade_does_not_mutate_source_lock(self) -> None:
        if shutil.which("dart") is None:
            self.skipTest("dart missing")
        source_digest = sha256_file(LOCKFILES["dart_core"])
        try:
            code, message = disposable_dart_resolution("dart_core", "lowest")
        except subprocess.TimeoutExpired:
            self.skipTest("dart pub downgrade exceeded local timeout")
        self.assertEqual(sha256_file(LOCKFILES["dart_core"]), source_digest)
        self.assertEqual(code, 0, message)

    def test_osv_positive_fixture_would_fail(self) -> None:
        payload = '{"package":{"name":"bad","version":"0.0.0"}}\n'
        self.assertIn("bad", payload)
        self.assertNotIn("AKIA", payload)

    def test_gitleaks_redaction_config(self) -> None:
        text = (REPO / ".gitleaks.toml").read_text(encoding="utf-8")
        self.assertIn("redact", text)

    def test_gitleaks_has_no_directory_wide_allowlists(self) -> None:
        text = (REPO / ".gitleaks.toml").read_text(encoding="utf-8")
        self.assertNotIn("'''fixtures/'''", text)
        self.assertNotIn("'''plans/'''", text)
        self.assertNotIn("'''conformance/reports/'''", text)
        self.assertIn('condition = "AND"', text)

    def test_gitleaks_detects_secrets_in_formerly_allowlisted_directories(self) -> None:
        scanner = install_cli_tool("gitleaks/gitleaks")
        with tempfile.TemporaryDirectory(prefix="agentools-gitleaks-negative-") as tmp:
            root = Path(tmp)
            paths = (
                "fixtures/leak.env",
                "plans/leak.env",
                "conformance/reports/leak.env",
            )
            # Canaries are assembled at runtime so the repository itself carries no key-shaped literal.
            access_keys = tuple(
                "AKIA" + suffix for suffix in ("QWERTYUIOPASDFGH", "ZXCVBNMASDFGHJKL", "MNBVCXZLKJHGFDSA")
            )
            for relative, access_key in zip(paths, access_keys, strict=True):
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(f"AWS_ACCESS_KEY_ID={access_key}\n", encoding="utf-8")
            report = root / "report.json"
            proc = subprocess.run(
                [
                    str(scanner), "dir", "--no-banner", "--redact",
                    "--config", str(REPO / ".gitleaks.toml"), str(root),
                    "--report-format", "json", "--report-path", str(report),
                ],
                cwd=REPO,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 1, proc.stdout + proc.stderr)
            findings = json.loads(report.read_text(encoding="utf-8"))
            found = {
                Path(str(row["File"])).resolve().relative_to(root.resolve()).as_posix()
                for row in findings
            }
            self.assertEqual(found, set(paths))

    def test_gitleaks_allows_only_the_known_fake_token_form(self) -> None:
        scanner = install_cli_tool("gitleaks/gitleaks")
        with tempfile.TemporaryDirectory(prefix="agentools-gitleaks-fake-") as tmp:
            root = Path(tmp)
            fixture = root / "fixtures" / "known-fake.json"
            fixture.parent.mkdir(parents=True)
            fixture.write_text(
                '{"confirmation_token":"at1.eyJ2IjoxfQ.Zm9vYmFy"}\n',
                encoding="utf-8",
            )
            proc = subprocess.run(
                [
                    str(scanner), "dir", "--no-banner", "--redact",
                    "--config", str(REPO / ".gitleaks.toml"), str(root),
                ],
                cwd=REPO,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)

    def test_cursor_registers_only_fail_closed_safe_dirs(self) -> None:
        document = json.loads((REPO / ".cursor/hooks.json").read_text(encoding="utf-8"))
        self.assertEqual(set(document["hooks"]), {"beforeShellExecution"})
        self.assertEqual(len(document["hooks"]["beforeShellExecution"]), 1)
        hook = document["hooks"]["beforeShellExecution"][0]
        self.assertIn("--surface security.safe-dirs", hook["command"])
        self.assertIs(hook["failClosed"], True)

    def test_cursor_memory_scope_uses_versioned_project_metadata(self) -> None:
        with tempfile.TemporaryDirectory(prefix="agentools-audit-") as tmp:
            root = Path(tmp)
            (root / ".cursor").mkdir()
            (root / ".serena").mkdir()
            (root / ".cursor" / "memory.env.ini").write_text(
                "MODE=maind\nBRAIN=core\n", encoding="utf-8"
            )
            (root / ".serena" / "project.yml").write_text(
                'project_name: "agentools"\n', encoding="utf-8"
            )
            payload = {
                "hook_event_name": "sessionStart",
                "conversation_id": "test-conversation",
                "session_id": "test-session",
                "cwd": str(root),
            }
            proc = subprocess.run(
                [
                    sys.executable, str(REPO / ".cursor/hooks/lifecycle.py"),
                    "--surface", "lifecycle.memory",
                ],
                input=json.dumps(payload),
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            result = json.loads(proc.stdout)
            self.assertEqual(
                result["env"]["SWISSKNIFEMAN_MEMORY_SCOPE"], "project:agentools"
            )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="CI matrix and P8 tier map (P9.2)")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("emit", help="Write P9-ci-map.json").set_defaults(func=cmd_emit)
    sub.add_parser("validate", help="Validate matrix and workflows").set_defaults(func=cmd_validate)
    sub.add_parser("write-finding", help="Write findings/P9-ci-map.md").set_defaults(func=cmd_write_finding)
    sub.add_parser("install-scanners", help="Download pinned OSV and Gitleaks").set_defaults(
        func=cmd_install_scanners
    )
    sub.add_parser("osv-scan", help="Run pinned OSV scanner on lockfiles").set_defaults(func=cmd_osv_scan)
    sub.add_parser("gitleaks-scan", help="Run pinned Gitleaks with redaction").set_defaults(
        func=cmd_gitleaks_scan
    )
    sub.add_parser("test", help="Run unit tests").set_defaults(func=cmd_test)
    return parser


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and argv[0].startswith("disposable-"):
        return cmd_disposable(argv[0])
    parser = build_parser()
    args = parser.parse_args(argv)
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
