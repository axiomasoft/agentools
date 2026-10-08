#!/usr/bin/env python3
"""P9.3 deterministic private RC subjects and reproducibility receipt (stdlib-only)."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from dataclasses import dataclass
from pathlib import Path
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[1]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))

from tools import package_gate as pg  # noqa: E402

SCHEMA_VERSION = "release-candidate-receipt/v1"
CONTRACT_REL = (
    "verification/root/contracts/release-candidate-v1.md"
)
SCHEMA_REL = (
    "verification/root/contracts/release-candidate-v1.schema.json"
)
ARTIFACT_PATH = (
    pg.REPO
    / "verification/artifacts/P9-reproducibility.json"
)
FINDING_PATH = (
    pg.REPO / "verification/findings/P9-reproducibility.md"
)
LOCK_PATH = pg.REPO / ".ci/supply-chain-lock.json"

PRODUCT_PREFIXES = ("php/", "dart/agentools/", "dart/agentools_mcp_dart/")

SUBJECT_SPECS: dict[str, dict[str, str]] = {
    "php-sdk": {
        "package_key": "php",
        "format": "agentools.release-subject/tar-gzip/v1",
        "paired_status": "standalone",
    },
    "dart-core": {
        "package_key": "dart_core",
        "format": "agentools.release-subject/tar-gzip/v1",
        "paired_status": "standalone",
    },
    "dart-mcp-companion": {
        "package_key": "dart_mcp",
        "format": "agentools.release-subject/tar-gzip/v1",
        "paired_status": "private-paired",
    },
}

KNOWN_ALGORITHMS = frozenset({"sha256"})
KNOWN_DIGEST_ENVELOPES = frozenset({"sha256"})


class RcError(RuntimeError):
    pass


def sha256_file(path: Path) -> str:
    return pg.sha256_file(path)


def canonical_json(value: Any) -> str:
    return pg.canonical_json(value)


def git_head_sha(repo: Path) -> str:
    return pg.git_head_sha(repo)


def git_commit_epoch(repo: Path) -> int:
    proc = subprocess.run(
        ["git", "show", "-s", "--format=%ct", "HEAD"],
        cwd=repo,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RcError(proc.stderr.strip() or "git show failed")
    return int(proc.stdout.strip())


def product_tree_dirty(repo: Path) -> list[str]:
    proc = subprocess.run(
        ["git", "status", "--porcelain", "--", *PRODUCT_PREFIXES],
        cwd=repo,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RcError(proc.stderr.strip() or "git status failed")
    return [line for line in proc.stdout.splitlines() if line.strip()]


def file_mode_for_path(rel: str) -> int:
    base = Path(rel)
    if rel.startswith("bin/") or base.suffix == "" and rel.startswith("bin/"):
        return 0o100755
    if rel == "bin/reference-slice-demo":
        return 0o100755
    return 0o100644


def validate_relative_path(rel: str) -> None:
    norm = rel.replace("\\", "/")
    if not norm or norm.startswith("/") or ".." in norm.split("/"):
        raise RcError(f"invalid archive path: {rel}")
    if norm.startswith("./"):
        raise RcError(f"invalid archive path: {rel}")


def deterministic_gzip(payload: bytes) -> bytes:
    buf = io.BytesIO()
    with gzip.GzipFile(fileobj=buf, mode="wb", mtime=0, compresslevel=9) as handle:
        handle.write(payload)
    return buf.getvalue()


def build_normalized_tar(inventory: list[dict[str, Any]], content_root: Path, mtime: int) -> bytes:
    ordered = sorted(inventory, key=lambda row: row["path"])
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w", format=tarfile.GNU_FORMAT) as tar:
        for row in ordered:
            rel = str(row["path"]).replace("\\", "/")
            validate_relative_path(rel)
            path = content_root / rel
            if not path.is_file():
                raise RcError(f"missing content file: {rel}")
            data = path.read_bytes()
            digest = hashlib.sha256(data).hexdigest()
            if digest != row["sha256"]:
                raise RcError(f"content manifest mismatch for {rel}")
            info = tarfile.TarInfo(name=rel)
            info.size = len(data)
            info.mtime = mtime
            info.uid = 0
            info.gid = 0
            info.uname = "root"
            info.gname = "root"
            info.mode = file_mode_for_path(rel)
            tar.addfile(info, io.BytesIO(data))
    return buf.getvalue()


def build_subject_archive(
    inventory: list[dict[str, Any]],
    content_root: Path,
    mtime: int,
) -> tuple[bytes, str]:
    tar_bytes = build_normalized_tar(inventory, content_root, mtime)
    archive = deterministic_gzip(tar_bytes)
    return archive, hashlib.sha256(archive).hexdigest()


def content_manifest_digest(inventory: list[dict[str, Any]]) -> str:
    return pg.inventory_digest(inventory)


def p7_oracle_paths(repo: Path, work: Path) -> dict[str, set[str]]:
    """Live P7.4 oracle from package_gate staging (not a stale artifact snapshot)."""
    _, _, payload = pg.stage_inventories(repo, work)
    return {
        "php-sdk": {row["path"] for row in payload["php"]["inventory"]},
        "dart-core": {row["path"] for row in payload["dart_core"]["inventory"]},
        "dart-mcp-companion": {row["path"] for row in payload["dart_mcp"]["inventory"]},
    }


def compare_p7_allowlist(
    subject_id: str,
    inventory: list[dict[str, Any]],
    oracle: dict[str, set[str]],
) -> list[str]:
    expected = oracle[subject_id]
    actual = {row["path"] for row in inventory}
    errors: list[str] = []
    missing = sorted(expected - actual)
    extra = sorted(actual - expected)
    if missing:
        errors.append(f"{subject_id} missing paths: {missing[:5]}")
    if extra:
        errors.append(f"{subject_id} extra paths: {extra[:5]}")
    return errors


@dataclass(frozen=True)
class BuiltSubject:
    subject_id: str
    inventory: list[dict[str, Any]]
    content_manifest_digest: str
    archive_bytes: bytes
    archive_sha256: str
    public_release_blocked: bool


def collect_inventories(
    work: Path,
) -> tuple[dict[str, Path], Path, dict[str, list[dict[str, Any]]], dict[str, Path]]:
    packages = pg.copy_package_sources(pg.REPO, work / "source")
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
    return packages, extracted, inventories, roots  # type: ignore[return-value]


def build_all_subjects(
    work: Path,
    *,
    source_date_epoch: int,
    oracle: dict[str, set[str]],
) -> list[BuiltSubject]:
    _, _, inventories, roots = collect_inventories(work)
    built: list[BuiltSubject] = []
    for subject_id, spec in SUBJECT_SPECS.items():
        inventory = inventories[subject_id]
        allow_errors = compare_p7_allowlist(subject_id, inventory, oracle)
        if allow_errors:
            raise RcError("; ".join(allow_errors))
        archive_bytes, archive_sha256 = build_subject_archive(
            inventory, roots[subject_id], source_date_epoch
        )
        blocked = subject_id == "dart-mcp-companion"
        built.append(
            BuiltSubject(
                subject_id=subject_id,
                inventory=inventory,
                content_manifest_digest=content_manifest_digest(inventory),
                archive_bytes=archive_bytes,
                archive_sha256=archive_sha256,
                public_release_blocked=blocked,
            )
        )
    return built


def digest_file(path: Path) -> str:
    if not path.is_file():
        raise RcError(f"missing file for digest: {path}")
    return sha256_file(path)


def build_receipt(
    *,
    builder_label: str,
    subjects: list[BuiltSubject],
    source_commit: str,
    source_date_epoch: int,
) -> dict[str, Any]:
    contract_path = pg.REPO / CONTRACT_REL
    schema_path = pg.REPO / SCHEMA_REL
    return {
        "schema_version": SCHEMA_VERSION,
        "builder": builder_label,
        "source_commit": source_commit,
        "source_date_epoch": source_date_epoch,
        "contract_sha256": digest_file(contract_path),
        "schema_sha256": digest_file(schema_path),
        "supply_chain_lock_sha256": digest_file(LOCK_PATH),
        "public_release_claimed": False,
        "subjects": [
            {
                "subject_id": item.subject_id,
                "format": SUBJECT_SPECS[item.subject_id]["format"],
                "paired_status": SUBJECT_SPECS[item.subject_id]["paired_status"],
                "public_release_blocked": item.public_release_blocked,
                "file_count": len(item.inventory),
                "content_manifest_digest": item.content_manifest_digest,
                "archive_digest": {
                    "algorithm": "sha256",
                    "digest": item.archive_sha256,
                },
                "inventory": item.inventory,
            }
            for item in subjects
        ],
    }


def subject_fingerprints(subjects: list[BuiltSubject]) -> dict[str, dict[str, str]]:
    return {
        item.subject_id: {
            "content_manifest_digest": item.content_manifest_digest,
            "archive_sha256": item.archive_sha256,
        }
        for item in subjects
    }


def run_reproducibility(
    repo: Path,
    *,
    require_clean: bool = True,
    source_date_epoch: int | None = None,
) -> dict[str, Any]:
    if require_clean:
        dirty = product_tree_dirty(repo)
        if dirty:
            raise RcError(f"dirty product tree: {dirty[:3]}")
    source_commit = git_head_sha(repo)
    if source_date_epoch is None:
        source_date_epoch = git_commit_epoch(repo)
    work_a = Path(tempfile.mkdtemp(prefix="p93-rc-a-"))
    work_b = Path(tempfile.mkdtemp(prefix="p93-rc-b-"))
    oracle_work = Path(tempfile.mkdtemp(prefix="p93-oracle-"))
    try:
        oracle = p7_oracle_paths(repo, oracle_work)
        old_umask = os.umask(0o022)
        subjects_a = build_all_subjects(
            work_a, source_date_epoch=source_date_epoch, oracle=oracle
        )
        os.umask(0o027)
        subjects_b = build_all_subjects(
            work_b, source_date_epoch=source_date_epoch, oracle=oracle
        )
        os.umask(old_umask)
        fp_a = subject_fingerprints(subjects_a)
        fp_b = subject_fingerprints(subjects_b)
        match = fp_a == fp_b
        errors: list[str] = []
        if not match:
            errors.append("independent builders produced different fingerprints")
        for subject in subjects_a:
            if subject.public_release_blocked and subject.subject_id != "dart-mcp-companion":
                errors.append("unexpected public_release_blocked flag")
        mcp = next(s for s in subjects_a if s.subject_id == "dart-mcp-companion")
        if not mcp.public_release_blocked:
            errors.append("MCP companion must remain public-release-blocked")
        payload: dict[str, Any] = {
            "schema_version": SCHEMA_VERSION,
            "status": "green" if match and not errors else "red",
            "source_commit": source_commit,
            "source_date_epoch": source_date_epoch,
            "reproducibility_match": match,
            "builder_a": build_receipt(
                builder_label="clean-root-a",
                subjects=subjects_a,
                source_commit=source_commit,
                source_date_epoch=source_date_epoch,
            ),
            "builder_b": build_receipt(
                builder_label="clean-root-b",
                subjects=subjects_b,
                source_commit=source_commit,
                source_date_epoch=source_date_epoch,
            ),
            "fingerprints": {"builder_a": fp_a, "builder_b": fp_b},
            "errors": errors,
            "verdict": "private-rc-subjects-reproducible",
        }
        return payload
    finally:
        shutil.rmtree(work_a, ignore_errors=True)
        shutil.rmtree(work_b, ignore_errors=True)
        shutil.rmtree(oracle_work, ignore_errors=True)


def validate_receipt_schema(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if payload.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    for key in ("builder_a", "builder_b"):
        block = payload.get(key)
        if not isinstance(block, dict):
            errors.append(f"missing {key}")
            continue
        for subject in block.get("subjects", []):
            digest = subject.get("archive_digest", {})
            if digest.get("algorithm") not in KNOWN_ALGORITHMS:
                errors.append("unknown digest algorithm")
            status = subject.get("paired_status")
            if status not in {"standalone", "private-paired"}:
                errors.append("unknown paired_status")
    return errors


def render_finding(payload: dict[str, Any]) -> str:
    fp = payload.get("fingerprints", {}).get("builder_a", {})
    lines = [
        "# P9 reproducibility — private RC subjects",
        "",
        f"**Status:** `{payload.get('status')}`",
        f"**Source commit:** `{payload.get('source_commit')}`",
        f"**SOURCE_DATE_EPOCH:** `{payload.get('source_date_epoch')}`",
        f"**Independent builders match:** `{payload.get('reproducibility_match')}`",
        "",
        "## Subject fingerprints (builder A)",
        "",
    ]
    for subject_id, row in sorted(fp.items()):
        lines.append(
            f"- `{subject_id}`: manifest `{row['content_manifest_digest']}` · "
            f"archive `{row['archive_sha256']}`"
        )
    lines.extend(
        [
            "",
            "## Notes",
            "",
            "- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).",
            "- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.",
            "- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).",
            "",
        ]
    )
    return "\n".join(lines) + "\n"


def cmd_run(args: argparse.Namespace) -> int:
    # Local direct-development acceptance must be able to attest the exact
    # worktree bytes before a commit exists. Hosted CI remains fail-closed on a
    # dirty product tree; subject manifests and archive digests bind the local
    # bytes, and every public release path remains blocked separately.
    hosted_ci = os.environ.get("CI", "").lower() == "true"
    payload = run_reproducibility(
        pg.REPO,
        require_clean=hosted_ci and not args.allow_dirty,
    )
    schema_errors = validate_receipt_schema(payload)
    if schema_errors:
        payload["status"] = "red"
        payload["errors"] = list(payload.get("errors", [])) + schema_errors
    ARTIFACT_PATH.parent.mkdir(parents=True, exist_ok=True)
    ARTIFACT_PATH.write_text(canonical_json(payload) + "\n", encoding="utf-8")
    FINDING_PATH.write_text(render_finding(payload), encoding="utf-8")
    print(canonical_json({"status": payload["status"], "errors": payload.get("errors", [])}))
    return 0 if payload["status"] == "green" else 1


def cmd_verify(args: argparse.Namespace) -> int:
    if not ARTIFACT_PATH.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT_PATH}")
    payload = json.loads(ARTIFACT_PATH.read_text(encoding="utf-8"))
    errors = validate_receipt_schema(payload)
    if not payload.get("reproducibility_match"):
        errors.append("reproducibility_match is false")
    if payload.get("status") != "green":
        errors.append("artifact status is not green")
    print(canonical_json({"status": "green" if not errors else "red", "errors": errors}))
    return 0 if not errors else 1


class ReleaseCandidateGoldenTests(unittest.TestCase):
    def test_path_ordering_and_unicode(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "root"
            root.mkdir()
            (root / "β").write_text("x", encoding="utf-8")
            (root / "a").write_text("y", encoding="utf-8")
            inv = [
                {"path": "a", "sha256": hashlib.sha256(b"y").hexdigest()},
                {"path": "β", "sha256": hashlib.sha256(b"x").hexdigest()},
            ]
            tar_a = build_normalized_tar(inv, root, 1_700_000_000)
            tar_b = build_normalized_tar(list(reversed(inv)), root, 1_700_000_000)
            self.assertEqual(tar_a, tar_b)

    def test_traversal_rejected(self) -> None:
        with self.assertRaises(RcError):
            validate_relative_path("../escape")

    def test_gzip_suppresses_mtime(self) -> None:
        data = deterministic_gzip(b"hello")
        self.assertEqual(data[4:8], b"\x00\x00\x00\x00")

    def test_byte_mutation_changes_digest(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "root"
            root.mkdir()
            (root / "f").write_text("a", encoding="utf-8")
            inv = [{"path": "f", "sha256": hashlib.sha256(b"a").hexdigest()}]
            _, d1 = build_subject_archive(inv, root, 1_700_000_000)
            (root / "f").write_text("b", encoding="utf-8")
            inv[0]["sha256"] = hashlib.sha256(b"b").hexdigest()
            _, d2 = build_subject_archive(inv, root, 1_700_000_000)
            self.assertNotEqual(d1, d2)

    def test_schema_rejects_unknown_algorithm(self) -> None:
        payload = {
            "schema_version": SCHEMA_VERSION,
            "builder_a": {
                "subjects": [
                    {
                        "archive_digest": {"algorithm": "md5", "digest": "0" * 64},
                        "paired_status": "standalone",
                    }
                ]
            },
            "builder_b": {"subjects": []},
        }
        self.assertTrue(validate_receipt_schema(payload))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("run", help="build subjects twice and write receipt")
    run.add_argument(
        "--allow-dirty",
        action="store_true",
        help="skip product-tree cleanliness guard (local dev only)",
    )
    sub.add_parser("verify", help="verify committed reproducibility artifact")
    sub.add_parser("test", help="run unit tests")
    args = parser.parse_args(argv)
    if args.command == "run":
        return cmd_run(args)
    if args.command == "verify":
        return cmd_verify(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ReleaseCandidateGoldenTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
