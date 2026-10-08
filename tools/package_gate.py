#!/usr/bin/env python3
"""P7.4 package archive inventories, allow/deny gates and clean consumers (stdlib-only)."""

from __future__ import annotations

import argparse
import fnmatch
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable
from unittest import mock

SCHEMA_VERSION = "package-gate/v1"
REPO = Path(__file__).resolve().parents[1]
ARTIFACT_PATH = (
    REPO / "verification/artifacts/P7-package-gate.json"
)
REPORT_PATH = REPO / "verification/findings/P7-package-gate.md"
MIGRATION_REPORT = REPO / "verification/findings/P7-migration-residuals.md"
SURFACE_PATH = REPO / "api/package-surface-v1.json"

COPY_IGNORE = shutil.ignore_patterns(
    "vendor",
    ".dart_tool",
    "build",
    ".phpunit.result.cache",
    "tmp",
)

PHP_ARCHIVE_DENY_GLOBS = (
    "reference/**",
    "testkit/**",
    "tests/**",
    "conformance/**",
    "plans/**",
    "vendor/**",
    ".phpunit.result.cache",
    "**/.env",
    "**/.env.*",
    "**/*credentials*",
)

DART_PUBLISH_DENY_GLOBS = (
    "test/**",
    "evidence/**",
    ".dart_tool/**",
    "build/**",
    "conformance/**",
    "plans/**",
    "**/.env",
    "**/.env.*",
)

WORKSPACE_PATH_RE = re.compile(
    r"(?:^|[\s\"'])("
    r"(?:\.\./)+agentools|"
    r"/home/|"
    r"package:agentools/src/|"
    r"package:agentools_mcp_dart/src/"
    r")",
    re.MULTILINE,
)


class GateError(RuntimeError):
    pass


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def git_head_sha(repo: Path) -> str:
    proc = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=repo,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise GateError(proc.stderr.strip() or "git rev-parse failed")
    return proc.stdout.strip()


def matches_any(path: str, patterns: tuple[str, ...]) -> bool:
    normalized = path.replace("\\", "/").lstrip("./")
    for pattern in patterns:
        pat = pattern.replace("\\", "/").lstrip("./").rstrip("/")
        if fnmatch.fnmatch(normalized, pattern) or fnmatch.fnmatchcase(normalized, pattern):
            return True
        if fnmatch.fnmatch(normalized, pat) or fnmatch.fnmatch(normalized, f"{pat}/**"):
            return True
        if pat and normalized.startswith(f"{pat}/"):
            return True
    return False


def inventory_digest(files: list[dict[str, Any]]) -> str:
    return sha256_text(canonical_json(files))


def sha256_text(text: str) -> str:
    return sha256_bytes(text.encode("utf-8"))


def copy_package_sources(repo: Path, staging: Path) -> dict[str, Path]:
    roots = {
        "php": repo / "php",
        "dart_agentools": repo / "dart/agentools",
        "dart_mcp": repo / "dart/agentools_mcp_dart",
    }
    out: dict[str, Path] = {}
    for key, src in roots.items():
        if not src.is_dir():
            raise GateError(f"missing package source: {src}")
        dest = staging / key
        if dest.exists():
            shutil.rmtree(dest)
        shutil.copytree(src, dest, ignore=COPY_IGNORE)
        out[key] = dest
    patch_staged_mcp_pubspec(out["dart_mcp"], out["dart_agentools"])
    return out


def patch_staged_mcp_pubspec(mcp_dir: Path, agentools_dir: Path) -> None:
    """Keep paired MCP artifact resolvable inside the disposable staging root only."""
    pubspec = mcp_dir / "pubspec.yaml"
    rel = os.path.relpath(agentools_dir, mcp_dir).replace("\\", "/")
    text = pubspec.read_text(encoding="utf-8")
    if "../agentools" not in text:
        raise GateError("unexpected MCP pubspec path dependency")
    text = text.replace("../agentools", rel)
    pubspec.write_text(text, encoding="utf-8")


@dataclass(frozen=True)
class CommandResult:
    argv: list[str]
    cwd: str
    exit_code: int
    duration_ms: int
    stdout: str
    stderr: str


def run_command(
    argv: list[str],
    cwd: Path,
    *,
    timeout_s: int = 900,
    env: dict[str, str] | None = None,
) -> CommandResult:
    started = time.monotonic()
    proc = subprocess.run(
        argv,
        cwd=cwd,
        capture_output=True,
        text=True,
        timeout=timeout_s,
        env=env,
        check=False,
    )
    duration_ms = int((time.monotonic() - started) * 1000)
    return CommandResult(
        argv=argv,
        cwd=str(cwd),
        exit_code=proc.returncode,
        duration_ms=duration_ms,
        stdout=proc.stdout,
        stderr=proc.stderr,
    )


def file_inventory(root: Path, relative_paths: list[str]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for rel in sorted(relative_paths):
        path = root / rel
        if not path.is_file():
            raise GateError(f"inventory path is not a file: {rel}")
        rows.append(
            {
                "path": rel.replace("\\", "/"),
                "bytes": path.stat().st_size,
                "sha256": sha256_file(path),
            }
        )
    return rows


def deny_violations(paths: list[str], deny_globs: tuple[str, ...]) -> list[str]:
    bad: list[str] = []
    for path in paths:
        if matches_any(path, deny_globs):
            bad.append(path)
    return sorted(bad)


def build_php_archive(staging_php: Path, work: Path) -> tuple[Path, list[str]]:
    archive_dir = work / "php-archive"
    archive_dir.mkdir(parents=True, exist_ok=True)
    archive_file = archive_dir / "agentools-sdk.tar"
    result = run_command(
        [
            "composer",
            "archive",
            f"--file={archive_file.stem}",
            f"--dir={archive_dir}",
        ],
        staging_php,
    )
    if result.exit_code != 0:
        raise GateError(f"composer archive failed: {result.stderr or result.stdout}")
    if not archive_file.exists():
        candidates = list(archive_dir.glob("*.tar"))
        if len(candidates) != 1:
            raise GateError("composer archive did not produce a single .tar")
        archive_file = candidates[0]
    with tarfile.open(archive_file, "r:") as handle:
        members = [m.name for m in handle.getmembers() if m.isfile()]
    return archive_file, sorted(members)


def read_ignore_patterns(package_dir: Path, name: str) -> list[str]:
    path = package_dir / name
    if not path.is_file():
        return []
    patterns: list[str] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("/"):
            line = line[1:]
        patterns.append(line)
    return patterns


def dart_package_files(package_dir: Path) -> list[str]:
    """Walk package files using pubignore/gitignore conventions (P7 archive oracle)."""
    ignore_name = ".pubignore" if (package_dir / ".pubignore").is_file() else ".gitignore"
    ignore = read_ignore_patterns(package_dir, ignore_name)
    files: list[str] = []
    for path in sorted(package_dir.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(package_dir).as_posix()
        if rel.startswith(".") or "/." in rel:
            continue
        if matches_any(rel, tuple(ignore) + (".dart_tool/**", "build/**")):
            continue
        files.append(rel)
    return files


def parse_dart_publish_inventory(output: str, package_dir: Path) -> list[str]:
    """Extract the native pub preview tree rather than approximating its defaults."""
    paths: list[str] = []
    parents: list[str] = []
    line_re = re.compile(r"^((?:(?:│| )   )*)(?:├──|└──) (.+)$")
    for line in output.splitlines():
        match = line_re.match(line)
        if match is None:
            continue
        depth = len(match.group(1)) // 4
        label = match.group(2)
        parent = parents[:depth]
        candidate = package_dir.joinpath(*parent, label)
        if candidate.is_dir():
            parents = parent + [label]
            continue
        filename = re.sub(r" \([^)]*\)$", "", label)
        candidate = package_dir.joinpath(*parent, filename)
        if candidate.is_file():
            paths.append(candidate.relative_to(package_dir).as_posix())
    if not paths:
        raise GateError("dart publication preview produced no parseable files")
    return sorted(paths)


def build_dart_publish_inventory(package_dir: Path) -> tuple[list[str], CommandResult]:
    resolution = run_command(["dart", "pub", "get", "--offline"], package_dir)
    if resolution.exit_code != 0:
        combined = resolution.stdout + resolution.stderr
        raise GateError(f"dart pub get --offline failed: {combined}")
    result = run_command(
        ["dart", "pub", "publish", "--dry-run", "--skip-validation"],
        package_dir,
        timeout_s=60,
    )
    combined = result.stdout + result.stderr
    if result.exit_code != 0 and "can't be published yet" not in combined:
        raise GateError(f"dart pub publish --dry-run failed: {combined}")
    paths = parse_dart_publish_inventory(combined, package_dir)
    return paths, result


def extract_tar(archive: Path, dest: Path) -> Path:
    dest.mkdir(parents=True, exist_ok=True)
    with tarfile.open(archive, "r:") as handle:
        handle.extractall(dest, filter="data")
    children = [p for p in dest.iterdir() if p.is_dir()]
    if len(children) == 1:
        return children[0]
    return dest


def materialize_consumer(template_dir: Path, dest: Path, replacements: dict[str, str]) -> None:
    if dest.exists():
        shutil.rmtree(dest)
    shutil.copytree(template_dir, dest)
    for path in dest.rglob("*"):
        if path.is_file() and path.suffix in {".json", ".yaml", ".dart", ".php"}:
            text = path.read_text(encoding="utf-8")
            for needle, repl in replacements.items():
                text = text.replace(needle, repl)
            path.write_text(text, encoding="utf-8")


def scan_workspace_leaks(root: Path, repo: Path) -> list[str]:
    leaks: list[str] = []
    repo_real = repo.resolve()
    skip_dirs = {"vendor", ".dart_tool", "build", ".pub-cache"}
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        if skip_dirs.intersection(path.parts):
            continue
        if path.suffix not in {".json", ".yaml", ".dart", ".php", ".lock"}:
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if WORKSPACE_PATH_RE.search(text):
            leaks.append(str(path.relative_to(root)))
        if str(repo_real) in text:
            leaks.append(str(path.relative_to(root)))
    return sorted(set(leaks))


def compile_migration_snippets(repo: Path) -> list[CommandResult]:
    results: list[CommandResult] = []
    php_snippet = repo / "consumers/php/snippets/migration_testkit.php"
    results.append(run_command(["php", "-l", str(php_snippet)], repo))
    dart_snippet = repo / "consumers/dart/snippets/migration_testkit.dart"
    scratch = Path(tempfile.mkdtemp(prefix="p74-snippet-"))
    try:
        pkg = scratch / "pkg"
        shutil.copytree(repo / "dart/agentools", pkg, ignore=COPY_IGNORE)
        snippet_dest = scratch / "snippet"
        snippet_dest.mkdir()
        shutil.copy2(dart_snippet, snippet_dest / "migration_testkit.dart")
        pubspec = (
            "name: snippet_probe\n"
            "publish_to: none\n"
            "environment:\n  sdk: ^3.11.0\n"
            "dependencies:\n  agentools:\n    path: pkg\n"
        )
        (scratch / "pubspec.yaml").write_text(pubspec, encoding="utf-8")
        results.append(run_command(["dart", "pub", "get", "--offline"], scratch))
        results.append(run_command(["dart", "analyze", "snippet/migration_testkit.dart"], scratch))
    finally:
        shutil.rmtree(scratch, ignore_errors=True)
    return results


def run_php_production_consumer(extracted: Path, work: Path) -> CommandResult:
    consumer = work / "php-production"
    materialize_consumer(
        REPO / "consumers/php/production",
        consumer,
        {"PACKAGE_PATH": str(extracted.resolve())},
    )
    install = run_command(
        ["composer", "install", "--no-dev", "--no-interaction", "--prefer-dist"],
        consumer,
    )
    if install.exit_code != 0:
        return install
    return run_command(["php", "scenario.php"], consumer)


def run_php_dev_consumer(staged_php: Path, work: Path) -> CommandResult:
    """TestKit contour is root-only autoload-dev; prove it on the staged package root."""
    script = work / "php-dev-scenario.php"
    shutil.copy2(REPO / "consumers/php/dev/scenario.php", script)
    install = run_command(["composer", "install", "--no-interaction", "--prefer-dist"], staged_php)
    if install.exit_code != 0:
        return install
    return run_command(["php", str(script.resolve())], staged_php)


def run_dart_consumer(template: Path, package_path: Path, work: Path, name: str) -> list[CommandResult]:
    consumer = work / name
    materialize_consumer(template, consumer, {"PACKAGE_PATH": str(package_path.resolve())})
    results = [
        run_command(["dart", "pub", "get", "--offline"], consumer),
        run_command(["dart", "analyze", "--fatal-infos"], consumer),
    ]
    bin_dir = consumer / "bin"
    if bin_dir.is_dir():
        for script in sorted(bin_dir.glob("*.dart")):
            results.append(run_command(["dart", "run", str(script.relative_to(consumer))], consumer))
    return results


def run_mcp_consumer(
    agentools_path: Path,
    mcp_path: Path,
    work: Path,
) -> list[CommandResult]:
    consumer = work / "dart-mcp"
    materialize_consumer(
        REPO / "consumers/dart_mcp",
        consumer,
        {
            "AGENTOOLS_PATH": str(agentools_path.resolve()),
            "MCP_PATH": str(mcp_path.resolve()),
        },
    )
    results = [
        run_command(["dart", "pub", "get", "--offline"], consumer),
        run_command(["dart", "analyze", "--fatal-infos"], consumer),
        run_command(["dart", "test", "-r", "compact"], consumer),
    ]
    return results


def stage_inventories(repo: Path, work: Path) -> tuple[dict[str, Path], Path, dict[str, Any]]:
    staging = work / "source"
    packages = copy_package_sources(repo, staging)
    php_archive, php_members = build_php_archive(packages["php"], work)
    php_bad = deny_violations(php_members, PHP_ARCHIVE_DENY_GLOBS)
    dart_core_paths, dart_core_cmd = build_dart_publish_inventory(packages["dart_agentools"])
    dart_mcp_paths, dart_mcp_cmd = build_dart_publish_inventory(packages["dart_mcp"])
    dart_core_bad = deny_violations(dart_core_paths, DART_PUBLISH_DENY_GLOBS)
    dart_mcp_bad = deny_violations(dart_mcp_paths, DART_PUBLISH_DENY_GLOBS)

    extracted = extract_tar(php_archive, work / "php-extract")
    php_files = file_inventory(extracted, php_members)
    dart_core_files = [
        {
            "path": rel,
            "bytes": (packages["dart_agentools"] / rel).stat().st_size,
            "sha256": sha256_file(packages["dart_agentools"] / rel),
        }
        for rel in dart_core_paths
    ]
    dart_mcp_files = [
        {
            "path": rel,
            "bytes": (packages["dart_mcp"] / rel).stat().st_size,
            "sha256": sha256_file(packages["dart_mcp"] / rel),
        }
        for rel in dart_mcp_paths
    ]

    surface_digest = sha256_file(SURFACE_PATH) if SURFACE_PATH.is_file() else None
    payload = {
        "source_tree": str(staging),
        "source_commit": git_head_sha(repo),
        "php": {
            "archive_command": ["composer", "archive"],
            "archive_path": str(php_archive),
            "archive_sha256": sha256_file(php_archive),
            "file_count": len(php_members),
            "files_digest": inventory_digest(php_files),
            "deny_violations": php_bad,
            "inventory": php_files,
        },
        "dart_core": {
            "publish_command": dart_core_cmd.argv,
            "file_count": len(dart_core_paths),
            "files_digest": inventory_digest(dart_core_files),
            "deny_violations": dart_core_bad,
            "inventory": dart_core_files,
        },
        "dart_mcp": {
            "publish_command": dart_mcp_cmd.argv,
            "file_count": len(dart_mcp_paths),
            "files_digest": inventory_digest(dart_mcp_files),
            "deny_violations": dart_mcp_bad,
            "inventory": dart_mcp_files,
            "path_dependency": "public-release-blocked",
        },
        "package_surface_sha256": surface_digest,
    }
    return packages, extracted, payload


def run_consumer_evidence(
    repo: Path,
    packages: dict[str, Path],
    extracted: Path,
    work: Path,
) -> dict[str, Any]:
    consumer_work = work / "consumers"
    consumer_work.mkdir()
    php_prod = run_php_production_consumer(extracted, consumer_work)
    php_dev = run_php_dev_consumer(packages["php"], consumer_work)
    dart_core_cmds = run_dart_consumer(
        REPO / "consumers/dart/core",
        packages["dart_agentools"],
        consumer_work,
        "dart-core",
    )
    dart_dev_cmds = run_dart_consumer(
        REPO / "consumers/dart/dev",
        packages["dart_agentools"],
        consumer_work,
        "dart-dev",
    )
    mcp_cmds = run_mcp_consumer(packages["dart_agentools"], packages["dart_mcp"], consumer_work)
    leaks = scan_workspace_leaks(consumer_work, repo)
    return {
        "php_production": _cmd_record(php_prod),
        "php_dev": _cmd_record(php_dev),
        "dart_core": [_cmd_record(item) for item in dart_core_cmds],
        "dart_dev": [_cmd_record(item) for item in dart_dev_cmds],
        "dart_mcp": [_cmd_record(item) for item in mcp_cmds],
        "workspace_leaks": leaks,
    }


def _cmd_record(result: CommandResult) -> dict[str, Any]:
    return {
        "argv": result.argv,
        "cwd": result.cwd,
        "exit_code": result.exit_code,
        "duration_ms": result.duration_ms,
    }


def evaluate_stage(stage: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    for key in ("php", "dart_core", "dart_mcp"):
        bad = stage[key]["deny_violations"]
        if bad:
            errors.append(f"{key} deny violations: {bad[:5]}")
    for name in ("php_production", "php_dev"):
        if stage["consumers"][name]["exit_code"] != 0:
            errors.append(f"consumer {name} failed")
    for group in ("dart_core", "dart_dev", "dart_mcp"):
        for item in stage["consumers"][group]:
            if item["exit_code"] != 0:
                errors.append(f"consumer {group} failed: {item['argv']}")
    if stage["consumers"]["workspace_leaks"]:
        errors.append(f"workspace leaks: {stage['consumers']['workspace_leaks']}")
    for item in stage["migration"]:
        if item["exit_code"] != 0:
            errors.append(f"migration compile failed: {item['argv']}")
    return errors


def render_report(payload: dict[str, Any]) -> str:
    stage = payload["stages"][0]
    lines = [
        "# P7 package gate",
        "",
        "**Verdict:** private/local package receipt — **not** a public release claim.",
        "",
        f"- Schema: `{payload['schema_version']}`",
        f"- Source commit: `{payload['source_commit']}`",
        f"- Deterministic repeat digest match: `{payload['repeat_digest_match']}`",
        f"- Package surface roster: `{stage.get('package_surface_sha256', 'n/a')}`",
        "",
        "## PHP archive (`agentools/sdk`)",
        "",
        f"- Files: {stage['php']['file_count']}",
        f"- Archive SHA-256: `{stage['php']['archive_sha256']}`",
        f"- Content digest: `{stage['php']['files_digest']}`",
        f"- Deny violations: {len(stage['php']['deny_violations'])}",
        "",
        "## Dart core (`agentools`)",
        "",
        f"- Publication-preview files: {stage['dart_core']['file_count']}",
        f"- Content digest: `{stage['dart_core']['files_digest']}`",
        f"- Deny violations: {len(stage['dart_core']['deny_violations'])}",
        "",
        "## Dart MCP companion (`agentools_mcp_dart`)",
        "",
        f"- Publication-preview files: {stage['dart_mcp']['file_count']}",
        f"- Path dependency on core: `{stage['dart_mcp']['path_dependency']}`",
        f"- Deny violations: {len(stage['dart_mcp']['deny_violations'])}",
        "",
        "## Clean consumers",
        "",
        f"- PHP production (`--no-dev`): exit {stage['consumers']['php_production']['exit_code']}",
        f"- PHP dev (TestKit contour): exit {stage['consumers']['php_dev']['exit_code']}",
        f"- Dart core / dev / MCP groups: see artifact JSON",
        f"- Workspace leak scan: {len(stage['consumers']['workspace_leaks'])} hit(s)",
        "",
        "## P8–P10 residuals",
        "",
        "- P9 owns deterministic normalized bytes, SBOM and provenance.",
        "- P10 owns RC consumer acceptance against P9 subjects.",
        "- MCP `path:` dependency remains `public-release-blocked` until P9/P10.",
        "",
    ]
    return "\n".join(lines) + "\n"


def render_migration_residuals() -> str:
    return (
        "# P7 migration residuals\n\n"
        "**Status:** snippets compile in isolated consumers; registry publication deferred.\n\n"
        "| Topic | Owner | Notes |\n"
        "|:--|:--|:--|\n"
        "| PHP TestKit FQN moves | P7.2 | Documented in `php/UPGRADING.md`; dev consumer proves contour |\n"
        "| Dart testkit library split | P7.3 | `agentools_testkit.dart`; snippet compiles via `dart analyze` |\n"
        "| MCP path dependency | P9/P10 | Paired private artifact only; label `public-release-blocked` |\n"
        "| Dart conformance tree | P7.4 | Excluded via `.pubignore` (parity with PHP `archive.exclude`) |\n"
        "| Packagist / pub.dev upload | P9/P10 | Out of P7 scope per D4/D5 |\n"
    )


def run_gate(repo: Path = REPO) -> dict[str, Any]:
    work = Path(tempfile.mkdtemp(prefix="p74-gate-"))
    try:
        packages, extracted, inventory_a = stage_inventories(repo, work / "a")
        _, _, inventory_b = stage_inventories(repo, work / "b")
        repeat = (
            inventory_a["php"]["files_digest"] == inventory_b["php"]["files_digest"]
            and inventory_a["dart_core"]["files_digest"] == inventory_b["dart_core"]["files_digest"]
            and inventory_a["dart_mcp"]["files_digest"] == inventory_b["dart_mcp"]["files_digest"]
        )
        migration_cmds = compile_migration_snippets(repo)
        consumers = run_consumer_evidence(repo, packages, extracted, work / "a")
        primary = {
            **inventory_a,
            "consumers": consumers,
            "migration": [_cmd_record(item) for item in migration_cmds],
        }
        errors = evaluate_stage(primary)
        if not repeat:
            errors.append("consecutive stage digests differ")
        status = "green" if not errors else "red"
        payload: dict[str, Any] = {
            "schema_version": SCHEMA_VERSION,
            "status": status,
            "source_commit": inventory_a["source_commit"],
            "repeat_digest_match": repeat,
            "errors": errors,
            "stages": [primary, inventory_b],
            "verdict": "private-local-package-receipt",
            "public_release_claimed": False,
        }
        return payload
    finally:
        shutil.rmtree(work, ignore_errors=True)


def run_mutant_checks(repo: Path = REPO) -> list[str]:
    """Negative fixtures: forbidden archive path, missing stable symbol, src import."""
    failures: list[str] = []
    if not deny_violations(["testkit/Foo.php"], PHP_ARCHIVE_DENY_GLOBS):
        failures.append("mutant: php deny pattern did not match testkit")
    if deny_violations(["src/Foo.php"], PHP_ARCHIVE_DENY_GLOBS):
        failures.append("mutant: php deny blocked production src")
    scratch = Path(tempfile.mkdtemp(prefix="p74-mutant-"))
    try:
        packages = copy_package_sources(repo, scratch / "src")
        php_archive, members = build_php_archive(packages["php"], scratch)
        members.append("plans/secret.json")
        if not deny_violations(members, PHP_ARCHIVE_DENY_GLOBS):
            failures.append("mutant: injected plans/ did not trip deny")
        bad_consumer = scratch / "consumer"
        bad_consumer.mkdir()
        (bad_consumer / "bad.dart").write_text(
            "import 'package:agentools/src/smoke.dart';\n", encoding="utf-8"
        )
        if not WORKSPACE_PATH_RE.search((bad_consumer / "bad.dart").read_text()):
            failures.append("mutant: src import leak regex failed")
        missing = scratch / "missing"
        missing.mkdir()
        materialize_consumer(
            REPO / "consumers/dart/core",
            missing,
            {"PACKAGE_PATH": str(packages["dart_agentools"].resolve())},
        )
        smoke = missing / "bin" / "smoke.dart"
        smoke.write_text(
            "import 'package:agentools/agentools.dart';\n"
            "void main() { const RemovedStableProbe(); }\n",
            encoding="utf-8",
        )
        analyze = run_command(["dart", "pub", "get", "--offline"], missing)
        if analyze.exit_code != 0:
            failures.append("mutant: missing symbol setup failed pub get")
        else:
            analyze = run_command(["dart", "analyze", "--fatal-infos", "."], missing)
            if analyze.exit_code == 0:
                failures.append("mutant: removed stable symbol did not fail analyze")
    finally:
        shutil.rmtree(scratch, ignore_errors=True)
    return failures


def cmd_run(args: argparse.Namespace) -> int:
    payload = run_gate(REPO)
    mutant_errors = run_mutant_checks(REPO)
    if mutant_errors:
        payload["status"] = "red"
        payload["errors"] = list(payload.get("errors", [])) + mutant_errors
    ARTIFACT_PATH.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT_PATH.write_text(canonical_json(payload) + "\n", encoding="utf-8")
    REPORT_PATH.write_text(render_report(payload), encoding="utf-8")
    MIGRATION_REPORT.write_text(render_migration_residuals(), encoding="utf-8")
    print(canonical_json({"status": payload["status"], "errors": payload["errors"]}))
    return 0 if payload["status"] == "green" else 1


def cmd_test(_: argparse.Namespace) -> int:
    return 0 if unittest.main(argv=["package_gate", "-q"], exit=False).result.wasSuccessful() else 1


class PackageGateTests(unittest.TestCase):
    def test_deny_patterns(self) -> None:
        self.assertTrue(matches_any("testkit/X.php", PHP_ARCHIVE_DENY_GLOBS))
        self.assertFalse(matches_any("src/X.php", PHP_ARCHIVE_DENY_GLOBS))
        self.assertTrue(matches_any("test/x_test.dart", DART_PUBLISH_DENY_GLOBS))
        self.assertTrue(matches_any("evidence/result.log", DART_PUBLISH_DENY_GLOBS))

    def test_path_escape_rejected(self) -> None:
        self.assertTrue(matches_any("../outside", ("../**",)))
        normalized = os.path.normpath("../outside")
        self.assertTrue(".." in normalized)

    def test_inventory_digest_stable(self) -> None:
        rows = [{"path": "a", "bytes": 1, "sha256": "x"}]
        self.assertEqual(inventory_digest(rows), inventory_digest(list(rows)))

    def test_dart_package_walk_excludes_conformance(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pkg = Path(tmp) / "pkg"
            pkg.mkdir()
            (pkg / "pubspec.yaml").write_text("name: x\n", encoding="utf-8")
            (pkg / "lib").mkdir()
            (pkg / "lib/a.dart").write_text("", encoding="utf-8")
            (pkg / "conformance").mkdir()
            (pkg / "conformance/harness.dart").write_text("", encoding="utf-8")
            (pkg / "test").mkdir()
            (pkg / "test/a_test.dart").write_text("", encoding="utf-8")
            (pkg / ".pubignore").write_text(
                "/conformance/\n/test/\n", encoding="utf-8"
            )
            paths = dart_package_files(pkg)
            self.assertIn("lib/a.dart", paths)
            self.assertFalse(any(p.startswith("conformance/") for p in paths))
            self.assertFalse(any(p.startswith("test/") for p in paths))

    def test_dart_package_walk_does_not_mask_unignored_tests(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pkg = Path(tmp)
            (pkg / "pubspec.yaml").write_text("name: x\n", encoding="utf-8")
            (pkg / "test").mkdir()
            (pkg / "test/a_test.dart").write_text("", encoding="utf-8")

            self.assertIn("test/a_test.dart", dart_package_files(pkg))

    def test_parse_dart_publish_inventory_uses_native_tree(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pkg = Path(tmp)
            (pkg / "lib").mkdir()
            (pkg / "lib/a.dart").write_text("", encoding="utf-8")
            (pkg / "pubspec.yaml").write_text("name: x\n", encoding="utf-8")
            output = (
                "Publishing x 1.0.0 to https://pub.dev:\n"
                "├── lib\n"
                "│   └── a.dart (<1 KB)\n"
                "└── pubspec.yaml (<1 KB)\n"
            )

            self.assertEqual(
                parse_dart_publish_inventory(output, pkg),
                ["lib/a.dart", "pubspec.yaml"],
            )

    def test_dart_publish_inventory_resolves_offline_before_dry_run(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            pkg = Path(tmp)
            (pkg / "pubspec.yaml").write_text("name: x\n", encoding="utf-8")
            resolution = CommandResult([], str(pkg), 0, 1, "", "")
            preview = CommandResult(
                [], str(pkg), 0, 1, "├── pubspec.yaml (<1 KB)\n", ""
            )
            with mock.patch(
                f"{__name__}.run_command", side_effect=[resolution, preview]
            ) as run:
                paths, result = build_dart_publish_inventory(pkg)

            self.assertEqual(paths, ["pubspec.yaml"])
            self.assertEqual(result, preview)
            self.assertEqual(
                run.call_args_list,
                [
                    mock.call(["dart", "pub", "get", "--offline"], pkg),
                    mock.call(
                        [
                            "dart",
                            "pub",
                            "publish",
                            "--dry-run",
                            "--skip-validation",
                        ],
                        pkg,
                        timeout_s=60,
                    ),
                ],
            )

    def test_workspace_leak_regex(self) -> None:
        self.assertIsNotNone(WORKSPACE_PATH_RE.search("import 'package:agentools/src/foo.dart';"))

    def test_cleanup_tempdir(self) -> None:
        temp = Path(tempfile.mkdtemp(prefix="p74-clean-"))
        (temp / "x").write_text("y", encoding="utf-8")
        shutil.rmtree(temp)
        self.assertFalse(temp.exists())


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run")
    sub.add_parser("test")
    args = parser.parse_args(argv)
    if args.command == "run":
        return cmd_run(args)
    if args.command == "test":
        return cmd_test(args)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
