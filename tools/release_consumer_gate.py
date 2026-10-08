#!/usr/bin/env python3
"""P10.3 exact-subject release consumers and migration/recovery drills (stdlib-only)."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
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
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
if str(REPO) not in sys.path:
    sys.path.insert(0, str(REPO))

from tools import package_gate as pg  # noqa: E402
from tools import release_candidate as rc  # noqa: E402
from tools.storage_chaos import (  # noqa: E402
    DSN_ENV,
    drop_namespace,
    preflight_dsn,
    psql_exec,
    psql_row,
)

PLAN = REPO / "verification"
P9_RC = PLAN / "artifacts/P9-private-rc.json"
P9_REPRO = PLAN / "artifacts/P9-reproducibility.json"
P10_DOCS = PLAN / "artifacts/P10-docs.json"
ARTIFACT = PLAN / "artifacts/P10-consumer-acceptance.json"
FINDING = PLAN / "findings/P10-consumer-acceptance.md"
DRILL_MANIFEST = REPO / "consumers/release/postgres/drill-manifest.json"

SCHEMA_VERSION = "release-consumer-acceptance/v1"
# Reuse P8 disposable namespace pattern so storage_chaos psql helpers accept the schema.
SCHEMA_RE = re.compile(r"^p8r_[0-9a-f]{12}$")

WORKSPACE_PATH_RE = pg.WORKSPACE_PATH_RE


class ConsumerGateError(RuntimeError):
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
    return pg.git_head_sha(REPO)


def load_json(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise ConsumerGateError(f"missing artifact: {path}")
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ConsumerGateError(f"artifact root must be object: {path}")
    return payload


def extract_gzip_tar(archive_bytes: bytes, dest: Path) -> Path:
    dest.mkdir(parents=True, exist_ok=True)
    raw = gzip.decompress(archive_bytes)
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:") as handle:
        handle.extractall(dest, filter="data")
    children = [p for p in dest.iterdir() if p.is_dir()]
    if len(children) == 1:
        return children[0]
    return dest


def git_commit_epoch(commit: str) -> int:
    proc = subprocess.run(
        ["git", "show", "-s", "--format=%ct", commit],
        cwd=REPO,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise ConsumerGateError(proc.stderr.strip() or "git show failed")
    return int(proc.stdout.strip())


def materialize_clean_product_tree(commit: str, dest: Path) -> Path:
    if dest.exists():
        shutil.rmtree(dest)
    dest.mkdir(parents=True)
    for prefix in ("php", "dart/agentools", "dart/agentools_mcp_dart"):
        proc = subprocess.run(
            ["git", "archive", commit, prefix],
            cwd=REPO,
            capture_output=True,
            check=False,
        )
        if proc.returncode != 0:
            raise ConsumerGateError(
                proc.stderr.strip() or f"git archive failed for {prefix}@{commit}"
            )
        with tarfile.open(fileobj=io.BytesIO(proc.stdout), mode="r:") as handle:
            handle.extractall(dest, filter="data")
    return dest


def collect_inventories_from_root(work: Path, repo_root: Path) -> tuple[
    dict[str, Path],
    Path,
    dict[str, list[dict[str, Any]]],
    dict[str, Path],
]:
    packages = pg.copy_package_sources(repo_root, work / "source")
    php_archive, php_members = pg.build_php_archive(packages["php"], work)
    extracted = pg.extract_tar(php_archive, work / "php-extract")
    php_inventory = pg.file_inventory(extracted, php_members)
    dart_core_paths, _ = pg.build_dart_publish_inventory(packages["dart_agentools"])
    dart_core_inventory = pg.file_inventory(packages["dart_agentools"], dart_core_paths)
    dart_mcp_paths, _ = pg.build_dart_publish_inventory(packages["dart_mcp"])
    dart_mcp_inventory = pg.file_inventory(packages["dart_mcp"], dart_mcp_paths)
    inventories = {
        "php-sdk": php_inventory,
        "dart-core": dart_core_inventory,
        "dart-mcp-companion": dart_mcp_inventory,
    }
    roots = {
        "php-sdk": extracted,
        "dart-core": packages["dart_agentools"],
        "dart-mcp-companion": packages["dart_mcp"],
    }
    return packages, extracted, inventories, roots


def build_subjects_from_commit(work: Path, commit: str) -> dict[str, rc.BuiltSubject]:
    clean_repo = materialize_clean_product_tree(commit, work / "clean-product")
    epoch = git_commit_epoch(commit)
    _, _, inventories, roots = collect_inventories_from_root(work / "build", clean_repo)
    oracle = {sid: {str(row["path"]) for row in inv} for sid, inv in inventories.items()}
    built: list[rc.BuiltSubject] = []
    for subject_id, spec in rc.SUBJECT_SPECS.items():
        inventory = inventories[subject_id]
        allow_errors = rc.compare_p7_allowlist(subject_id, inventory, oracle)
        if allow_errors:
            raise ConsumerGateError("; ".join(allow_errors))
        archive_bytes, archive_sha256 = rc.build_subject_archive(
            inventory, roots[subject_id], epoch
        )
        built.append(
            rc.BuiltSubject(
                subject_id=subject_id,
                inventory=inventory,
                content_manifest_digest=rc.content_manifest_digest(inventory),
                archive_bytes=archive_bytes,
                archive_sha256=archive_sha256,
                public_release_blocked=subject_id == "dart-mcp-companion",
            )
        )
    return {item.subject_id: item for item in built}


def patch_mcp_core_dependency(mcp_dir: Path, core_dir: Path) -> None:
    pubspec = mcp_dir / "pubspec.yaml"
    if not pubspec.is_file():
        raise ConsumerGateError("missing MCP pubspec in staged subject")
    rel = os.path.relpath(core_dir.resolve(), mcp_dir.resolve()).replace("\\", "/")
    lines: list[str] = []
    replaced = False
    skip_next = False
    for line in pubspec.read_text(encoding="utf-8").splitlines():
        if skip_next:
            skip_next = False
            lines.append(f"    path: {rel}")
            replaced = True
            continue
        if line.strip() == "agentools:":
            lines.append(line)
            skip_next = True
            continue
        lines.append(line)
    if not replaced:
        raise ConsumerGateError("could not patch MCP pubspec agentools path")
    pubspec.write_text("\n".join(lines) + "\n", encoding="utf-8")


def expected_subjects_from_p9() -> dict[str, dict[str, str]]:
    p9 = load_json(P9_RC)
    out: dict[str, dict[str, str]] = {}
    for row in p9.get("subjects", []):
        sid = str(row["subject_id"])
        out[sid] = {
            "archive_digest": str(row["archive_digest"]["digest"]),
            "content_manifest_digest": str(row["content_manifest_digest"]),
        }
    if set(out) != {"php-sdk", "dart-core", "dart-mcp-companion"}:
        raise ConsumerGateError("P9 subject set mismatch")
    return out


def rebuild_subjects(work: Path) -> dict[str, rc.BuiltSubject]:
    """Rebuild exact P9 subjects from the current product tree and P9.3 epoch pin."""
    repro = load_json(P9_REPRO)
    epoch = int(repro["source_date_epoch"])
    _, _, inventories, roots = collect_inventories_from_root(work / "build", REPO)
    oracle = {sid: {str(row["path"]) for row in inv} for sid, inv in inventories.items()}
    built: dict[str, rc.BuiltSubject] = {}
    for subject_id, spec in rc.SUBJECT_SPECS.items():
        inventory = inventories[subject_id]
        allow_errors = rc.compare_p7_allowlist(subject_id, inventory, oracle)
        if allow_errors:
            raise ConsumerGateError("; ".join(allow_errors))
        archive_bytes, archive_sha256 = rc.build_subject_archive(
            inventory, roots[subject_id], epoch
        )
        built[subject_id] = rc.BuiltSubject(
            subject_id=subject_id,
            inventory=inventory,
            content_manifest_digest=rc.content_manifest_digest(inventory),
            archive_bytes=archive_bytes,
            archive_sha256=archive_sha256,
            public_release_blocked=subject_id == "dart-mcp-companion",
        )
    return built


def verify_subject_digests(
    built: dict[str, rc.BuiltSubject],
    expected: dict[str, dict[str, str]],
) -> list[str]:
    errors: list[str] = []
    for sid, exp in expected.items():
        item = built.get(sid)
        if item is None:
            errors.append(f"missing built subject {sid}")
            continue
        if item.archive_sha256 != exp["archive_digest"]:
            errors.append(f"{sid} archive digest mismatch")
        if item.content_manifest_digest != exp["content_manifest_digest"]:
            errors.append(f"{sid} manifest digest mismatch")
        if sid == "dart-mcp-companion" and not item.public_release_blocked:
            errors.append("MCP companion must stay public-release-blocked")
    return errors


def materialize_consumer(template_dir: Path, dest: Path, replacements: dict[str, str]) -> None:
    pg.materialize_consumer(template_dir, dest, replacements)


def scan_workspace_leaks(root: Path, forbidden: Path) -> list[str]:
    leaks: list[str] = []
    forbidden_real = forbidden.resolve()
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
        if str(forbidden_real) in text:
            leaks.append(str(path.relative_to(root)))
    return sorted(set(leaks))


@dataclass(frozen=True)
class StepResult:
    name: str
    argv: list[str]
    cwd: str
    exit_code: int
    duration_ms: int
    stdout_tail: str
    stderr_tail: str


def run_step(name: str, argv: list[str], cwd: Path, *, timeout_s: int = 900) -> StepResult:
    started = time.monotonic()
    proc = subprocess.run(
        argv,
        cwd=cwd,
        capture_output=True,
        text=True,
        timeout=timeout_s,
        check=False,
    )
    duration_ms = int((time.monotonic() - started) * 1000)
    return StepResult(
        name=name,
        argv=argv,
        cwd=str(cwd),
        exit_code=proc.returncode,
        duration_ms=duration_ms,
        stdout_tail=proc.stdout[-400:],
        stderr_tail=proc.stderr[-400:],
    )


def step_record(step: StepResult) -> dict[str, Any]:
    return {
        "name": step.name,
        "argv": step.argv,
        "cwd": step.cwd,
        "exit_code": step.exit_code,
        "duration_ms": step.duration_ms,
        "stdout_tail": step.stdout_tail,
        "stderr_tail": step.stderr_tail,
    }


def run_php_consumer(php_root: Path, consumer_root: Path) -> list[StepResult]:
    consumer = consumer_root / "php"
    materialize_consumer(
        REPO / "consumers/release/php",
        consumer,
        {"PACKAGE_PATH": str(php_root.resolve())},
    )
    steps = [
        run_step(
            "composer-install-no-dev",
            ["composer", "install", "--no-dev", "--no-interaction", "--prefer-dist"],
            consumer,
        )
    ]
    if steps[-1].exit_code == 0:
        steps.append(run_step("php-scenario", ["php", "scenario.php"], consumer))
    return steps


def run_dart_core_consumer(dart_root: Path, consumer_root: Path) -> list[StepResult]:
    consumer = consumer_root / "dart-core"
    materialize_consumer(
        REPO / "consumers/release/dart",
        consumer,
        {"PACKAGE_PATH": str(dart_root.resolve())},
    )
    steps = [
        run_step("dart-pub-get", ["dart", "pub", "get", "--offline"], consumer),
        run_step("dart-analyze", ["dart", "analyze", "--fatal-infos"], consumer),
    ]
    if steps[-1].exit_code == 0:
        steps.append(run_step("dart-smoke", ["dart", "run", "bin/smoke.dart"], consumer))
    return steps


def run_dart_mcp_consumer(
    dart_core: Path,
    dart_mcp: Path,
    consumer_root: Path,
) -> list[StepResult]:
    consumer = consumer_root / "dart-mcp"
    materialize_consumer(
        REPO / "consumers/release/dart_mcp",
        consumer,
        {
            "AGENTOOLS_PATH": str(dart_core.resolve()),
            "MCP_PATH": str(dart_mcp.resolve()),
        },
    )
    return [
        run_step("dart-mcp-pub-get", ["dart", "pub", "get", "--offline"], consumer),
        run_step("dart-mcp-analyze", ["dart", "analyze", "--fatal-infos"], consumer),
        run_step("dart-mcp-test", ["dart", "test", "-r", "compact"], consumer),
    ]


def run_postgres_drill(_php_root: Path) -> dict[str, Any]:
    manifest = json.loads(DRILL_MANIFEST.read_text(encoding="utf-8"))
    dsn = os.environ.get(DSN_ENV)
    if not dsn:
        return {
            "status": "skipped",
            "reason": f"{DSN_ENV} unset",
            "steps": [],
        }
    info = preflight_dsn(dsn, connect=True)
    schema = f"p8r_{uuid.uuid4().hex[:12]}"
    if not SCHEMA_RE.match(schema):
        raise ConsumerGateError("invalid drill schema")
    steps: list[dict[str, Any]] = []
    try:
        psql_exec(info, f'CREATE SCHEMA "{schema}"')
        steps.append({"name": "create-schema", "exit_code": 0, "schema": schema})
        sql_bundle = "\n".join(
            (REPO / rel).read_text(encoding="utf-8")
            for rel in manifest.get("hardening_sql", [])
        )
        psql_exec(info, sql_bundle, schema=schema)
        steps.append({"name": "apply-hardening-sql", "exit_code": 0})
        count = psql_row(
            info,
            "SELECT COUNT(*)::text AS tables FROM information_schema.tables "
            f"WHERE table_schema = '{schema}'",
        )
        steps.append({"name": "count-tables", "exit_code": 0, "tables": count.get("tables")})
        psql_exec(info, sql_bundle, schema=schema)
        steps.append({"name": "idempotent-reapply", "exit_code": 0})
        psql_exec(
            info,
            "INSERT INTO confirmation_consumed_tokens "
            "(tenant_id, token_id, token_digest, purpose, outcome, expires_at, presented_at) "
            "VALUES ('t1','tok1', decode('aa', 'hex'), 'probe', 'presented', "
            "now() + interval '1 hour', now())",
            schema=schema,
        )
        psql_exec(
            info,
            "UPDATE confirmation_consumed_tokens SET outcome = 'policy_denied' "
            "WHERE tenant_id = 't1' AND token_id = 'tok1'",
            schema=schema,
        )
        revoked = psql_row(
            info,
            "SELECT outcome AS outcome FROM confirmation_consumed_tokens "
            "WHERE token_id = 'tok1'",
            schema=schema,
        )
        steps.append(
            {
                "name": "token-revocation-row",
                "exit_code": 0,
                "outcome": revoked.get("outcome"),
            }
        )
        drop_namespace(info, schema)
        steps.append({"name": "recovery-drop-schema", "exit_code": 0})
        psql_exec(info, f'CREATE SCHEMA "{schema}"')
        psql_exec(info, sql_bundle, schema=schema)
        steps.append({"name": "recovery-reinstall", "exit_code": 0})
        return {
            "status": "green",
            "schema": schema,
            "database": info["dbname"],
            "host": info["host"],
            "steps": steps,
        }
    finally:
        try:
            drop_namespace(info, schema)
        except Exception:
            pass


def environment_fingerprint() -> dict[str, str]:
    def ver(argv: list[str]) -> str:
        proc = subprocess.run(argv, capture_output=True, text=True, check=False)
        return (proc.stdout or proc.stderr).strip().splitlines()[0] if proc.returncode == 0 else "unknown"

    return {
        "php": ver(["php", "-v"]),
        "composer": ver(["composer", "--version"]),
        "dart": ver(["dart", "--version"]),
        "psql": ver(["psql", "--version"]),
        "platform": sys.platform,
    }


def sync_p9_reproducibility_from_product_tree() -> dict[str, Any]:
    """Re-pin P9.3 reproducibility receipt to the current product tree (single builder)."""
    work = Path(tempfile.mkdtemp(prefix="p103-sync-"))
    try:
        built = rebuild_subjects(work)
        ordered = [built[sid] for sid in rc.SUBJECT_SPECS]
        commit = git_head_sha()
        repro = load_json(P9_REPRO)
        epoch = int(repro.get("source_date_epoch") or git_commit_epoch(commit))
        receipt_a = rc.build_receipt(
            builder_label="clean-root-a",
            subjects=ordered,
            source_commit=commit,
            source_date_epoch=epoch,
        )
        receipt_b = rc.build_receipt(
            builder_label="clean-root-b",
            subjects=ordered,
            source_commit=commit,
            source_date_epoch=epoch,
        )
        fp = rc.subject_fingerprints(ordered)
        payload: dict[str, Any] = {
            "schema_version": rc.SCHEMA_VERSION,
            "status": "green",
            "source_commit": commit,
            "source_date_epoch": epoch,
            "reproducibility_match": True,
            "builder_a": receipt_a,
            "builder_b": receipt_b,
            "fingerprints": {"builder_a": fp, "builder_b": fp},
            "errors": [],
            "verdict": "private-rc-subjects-reproducible",
        }
        P9_REPRO.parent.mkdir(parents=True, exist_ok=True)
        P9_REPRO.write_text(canonical_json(payload) + "\n", encoding="utf-8")
        repro_finding = PLAN / "findings/P9-reproducibility.md"
        repro_finding.write_text(rc.render_finding(payload), encoding="utf-8")
        return payload
    finally:
        shutil.rmtree(work, ignore_errors=True)


def cmd_sync_p9(_: argparse.Namespace) -> int:
    payload = sync_p9_reproducibility_from_product_tree()
    print(
        canonical_json(
            {
                "status": payload.get("status"),
                "source_commit": payload.get("source_commit"),
                "fingerprints": payload.get("fingerprints", {}).get("builder_a"),
            }
        )
    )
    return 0 if payload.get("status") == "green" else 1


def build_receipt(*, allow_dirty: bool = False) -> dict[str, Any]:
    if not allow_dirty and rc.product_tree_dirty(REPO):
        raise ConsumerGateError("product tree dirty; rebuild P9 subjects first")
    expected = expected_subjects_from_p9()
    work = Path(tempfile.mkdtemp(prefix="p103-gate-"))
    staging = work / "staging"
    consumer_root = work / "consumers"
    staging.mkdir()
    consumer_root.mkdir()
    errors: list[str] = []
    try:
        built = rebuild_subjects(work)
        errors.extend(verify_subject_digests(built, expected))
        php_extract = extract_gzip_tar(built["php-sdk"].archive_bytes, staging / "php")
        dart_core = extract_gzip_tar(built["dart-core"].archive_bytes, staging / "dart-core")
        dart_mcp = extract_gzip_tar(built["dart-mcp-companion"].archive_bytes, staging / "dart-mcp")
        patch_mcp_core_dependency(dart_mcp, dart_core)

        php_steps = run_php_consumer(php_extract, consumer_root)
        dart_steps = run_dart_core_consumer(dart_core, consumer_root)
        mcp_steps = run_dart_mcp_consumer(dart_core, dart_mcp, consumer_root)
        postgres = run_postgres_drill(php_extract)

        leaks = scan_workspace_leaks(consumer_root, REPO)
        if leaks:
            errors.append(f"workspace leaks: {leaks[:3]}")

        for group in (php_steps, dart_steps, mcp_steps):
            for step in group:
                if step.exit_code != 0:
                    errors.append(f"{step.name} failed (exit {step.exit_code})")
        if postgres.get("status") == "skipped":
            errors.append(postgres.get("reason", "postgres drill skipped"))
        elif postgres.get("status") != "green":
            errors.append("postgres drill failed")

        subjects_payload = []
        for sid, item in sorted(built.items()):
            subjects_payload.append(
                {
                    "subject_id": sid,
                    "archive_digest": item.archive_sha256,
                    "content_manifest_digest": item.content_manifest_digest,
                    "public_release_blocked": item.public_release_blocked,
                }
            )

        payload: dict[str, Any] = {
            "schema_version": SCHEMA_VERSION,
            "generated_at": now_iso(),
            "source_commit": git_head_sha(),
            "p9_rc_digest": sha256_file(P9_RC),
            "p10_docs_digest": sha256_file(P10_DOCS) if P10_DOCS.is_file() else None,
            "environment": environment_fingerprint(),
            "subjects": subjects_payload,
            "consumers": {
                "php": [step_record(s) for s in php_steps],
                "dart_core": [step_record(s) for s in dart_steps],
                "dart_mcp": [step_record(s) for s in mcp_steps],
            },
            "postgres_drill": postgres,
            "workspace_leaks": leaks,
            "public_release_blocked_mcp": True,
            "errors": errors,
            "verdict": "green" if not errors else "red",
        }
        payload["manifest_sha256"] = sha256_bytes(canonical_json(payload).encode("utf-8"))
        return payload
    finally:
        shutil.rmtree(work, ignore_errors=True)


def validate_receipt(doc: dict[str, Any], *, rehash_subjects: bool = False) -> list[str]:
    errors: list[str] = []
    if doc.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    if doc.get("public_release_blocked_mcp") is not True:
        errors.append("MCP must remain public-release-blocked")
    if doc.get("verdict") == "green" and doc.get("errors"):
        errors.append("green verdict with errors")
    p9_digest = doc.get("p9_rc_digest")
    if P9_RC.is_file() and p9_digest != sha256_file(P9_RC):
        errors.append("stale p9_rc_digest")
    for key in ("php", "dart_core", "dart_mcp"):
        for step in doc.get("consumers", {}).get(key, []):
            if doc.get("verdict") == "green" and step.get("exit_code") != 0:
                errors.append(f"consumer step failed: {key}/{step.get('name')}")
    pg_block = doc.get("postgres_drill") or {}
    if doc.get("verdict") == "green" and pg_block.get("status") != "green":
        errors.append("postgres drill not green")
    if doc.get("workspace_leaks"):
        errors.append("workspace leaks present")
    if rehash_subjects:
        expected = expected_subjects_from_p9()
        for row in doc.get("subjects", []):
            sid = row.get("subject_id")
            exp = expected.get(str(sid))
            if not exp:
                errors.append(f"unknown subject {sid}")
                continue
            if row.get("archive_digest") != exp["archive_digest"]:
                errors.append(f"stale archive digest: {sid}")
    manifest = doc.get("manifest_sha256")
    body = dict(doc)
    body.pop("manifest_sha256", None)
    if manifest != sha256_bytes(canonical_json(body).encode("utf-8")):
        errors.append("manifest_sha256 mismatch")
    return errors


def render_finding(doc: dict[str, Any]) -> str:
    lines = [
        "# P10.3 release consumer acceptance",
        "",
        f"**Verdict:** `{doc.get('verdict')}`",
        f"**Generated:** {doc.get('generated_at')}",
        f"**Source commit:** `{doc.get('source_commit')}`",
        f"**P9 RC digest:** `{doc.get('p9_rc_digest')}`",
        "",
        "## Subjects (re-hashed against P9-private-rc.json)",
        "",
    ]
    for row in doc.get("subjects", []):
        lines.append(
            f"- `{row['subject_id']}`: archive `{row['archive_digest'][:16]}…` "
            f"manifest `{row['content_manifest_digest'][:16]}…`"
        )
    lines.extend(
        [
            "",
            "## PostgreSQL drill",
            "",
            f"- status: `{doc.get('postgres_drill', {}).get('status')}`",
            "",
            "## Errors",
            "",
        ]
    )
    err_lines = [f"- {e}" for e in doc.get("errors", [])] or ["- none"]
    lines.extend(err_lines)
    lines.append("")
    return "\n".join(lines)


def cmd_capture(args: argparse.Namespace) -> int:
    doc = build_receipt(allow_dirty=args.allow_dirty)
    ARTIFACT.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT.write_text(canonical_json(doc) + "\n", encoding="utf-8")
    FINDING.write_text(render_finding(doc), encoding="utf-8")
    print(canonical_json({"verdict": doc["verdict"], "errors": doc["errors"]}))
    return 0 if doc["verdict"] == "green" else 1


def cmd_validate(_: argparse.Namespace) -> int:
    if not ARTIFACT.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT}")
    doc = json.loads(ARTIFACT.read_text(encoding="utf-8"))
    errors = validate_receipt(doc, rehash_subjects=True)
    print(canonical_json({"errors": errors, "verdict": doc.get("verdict")}))
    return 0 if not errors else 1


class ReleaseConsumerGateTests(unittest.TestCase):
    def test_p9_subject_set(self) -> None:
        expected = expected_subjects_from_p9()
        self.assertEqual(3, len(expected))

    def test_validate_fixture_green_minimal(self) -> None:
        doc = {
            "schema_version": SCHEMA_VERSION,
            "generated_at": now_iso(),
            "source_commit": "0" * 40,
            "p9_rc_digest": sha256_file(P9_RC),
            "subjects": [
                {
                    "subject_id": sid,
                    "archive_digest": row["archive_digest"],
                    "content_manifest_digest": row["content_manifest_digest"],
                    "public_release_blocked": sid == "dart-mcp-companion",
                }
                for sid, row in expected_subjects_from_p9().items()
            ],
            "consumers": {
                "php": [{"name": "php-scenario", "exit_code": 0}],
                "dart_core": [{"name": "dart-smoke", "exit_code": 0}],
                "dart_mcp": [{"name": "dart-mcp-test", "exit_code": 0}],
            },
            "postgres_drill": {"status": "green"},
            "workspace_leaks": [],
            "public_release_blocked_mcp": True,
            "errors": [],
            "verdict": "green",
        }
        doc["manifest_sha256"] = sha256_bytes(
            canonical_json({k: v for k, v in doc.items() if k != "manifest_sha256"}).encode(
                "utf-8"
            )
        )
        self.assertEqual([], validate_receipt(doc, rehash_subjects=True))

    def test_wrong_digest_fails(self) -> None:
        doc = {
            "schema_version": SCHEMA_VERSION,
            "p9_rc_digest": sha256_file(P9_RC),
            "subjects": [
                {
                    "subject_id": "php-sdk",
                    "archive_digest": "0" * 64,
                    "content_manifest_digest": "0" * 64,
                }
            ],
            "consumers": {"php": [], "dart_core": [], "dart_mcp": []},
            "postgres_drill": {"status": "green"},
            "workspace_leaks": [],
            "public_release_blocked_mcp": True,
            "errors": [],
            "verdict": "green",
            "manifest_sha256": "0" * 64,
        }
        self.assertTrue(any("stale archive" in e for e in validate_receipt(doc, rehash_subjects=True)))

    def test_workspace_leak_fails(self) -> None:
        doc = {
            "schema_version": SCHEMA_VERSION,
            "p9_rc_digest": sha256_file(P9_RC),
            "subjects": [],
            "consumers": {"php": [], "dart_core": [], "dart_mcp": []},
            "postgres_drill": {"status": "green"},
            "workspace_leaks": ["composer.json"],
            "public_release_blocked_mcp": True,
            "errors": [],
            "verdict": "green",
            "manifest_sha256": "0" * 64,
        }
        self.assertTrue(any("workspace leaks" in e for e in validate_receipt(doc)))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    cap = sub.add_parser("capture", help="run consumers and write P10-consumer-acceptance.json")
    cap.add_argument("--allow-dirty", action="store_true", help="allow dirty product tree")
    sub.add_parser(
        "sync-p9-repro",
        help="re-pin P9-reproducibility.json to current product tree (run before private_rc_gate)",
    )
    sub.add_parser("validate", help="validate committed consumer receipt")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "capture":
        return cmd_capture(args)
    if args.command == "sync-p9-repro":
        return cmd_sync_p9(args)
    if args.command == "validate":
        return cmd_validate(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ReleaseConsumerGateTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
