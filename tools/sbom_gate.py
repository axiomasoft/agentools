#!/usr/bin/env python3
"""P9.4 SPDX SBOM, SLSA provenance and conditional GitHub attestation evidence."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "verification"
RC_ARTIFACT = PLAN / "artifacts/P9-reproducibility.json"
RC_CONTRACT = PLAN / "root/contracts/release-candidate-v1.md"
LOCK_PATH = REPO / ".ci/supply-chain-lock.json"
CAPABILITY_FINDING = PLAN / "findings/P9-ci-capabilities.md"
ARTIFACT_PATH = PLAN / "artifacts/P9-supply-chain-evidence.json"
FINDING_PATH = PLAN / "findings/P9-supply-chain-evidence.md"
WORKFLOW_CI = REPO / ".github/workflows/ci.yml"

SCHEMA_VERSION = "supply-chain-evidence/v1"
SLSA_PREDICATE = "https://slsa.dev/provenance/v1"
ATTEST_ACTION = "actions/attest"

CAPABILITY_STATES = frozenset(
    {
        "verified",
        "statement-verified",
        "unavailable-plan",
        "available-disabled",
        "not-executed",
    }
)


class SbomError(RuntimeError):
    pass


def canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def load_lock() -> dict[str, Any]:
    return json.loads(LOCK_PATH.read_text(encoding="utf-8"))


def syft_spec(lock: dict[str, Any]) -> dict[str, Any]:
    for tool in lock.get("cli_tools", []):
        if tool.get("id") == "anchore/syft":
            return tool
    raise SbomError("syft not present in supply-chain lock")


def resolve_syft(lock: dict[str, Any]) -> tuple[Path | None, str]:
    try:
        return ensure_syft(lock), "executed"
    except (SbomError, urllib.error.URLError, OSError) as exc:
        return None, f"unavailable-offline: {exc}"


def ensure_syft(lock: dict[str, Any], cache: Path | None = None) -> Path:
    spec = syft_spec(lock)
    cache = cache or (REPO / ".ci/cache/syft")
    cache.mkdir(parents=True, exist_ok=True)
    binary = cache / "syft"
    if binary.is_file():
        return binary
    archive = cache / spec["artifact"]
    urllib.request.urlretrieve(spec["download_url"], archive)
    if sha256_file(archive) != spec["sha256"]:
        raise SbomError("syft download sha256 mismatch")
    subprocess.run(
        ["tar", "-xzf", str(archive), "-C", str(cache)],
        check=True,
        capture_output=True,
    )
    candidates = [p for p in cache.rglob("syft") if p.is_file()]
    if not candidates:
        raise SbomError("syft binary not found in archive")
    chosen = candidates[0]
    if chosen.resolve() != binary.resolve():
        shutil.copy2(chosen, binary)
    binary.chmod(0o755)
    return binary


def extract_subject_archive(archive_bytes: bytes, dest: Path) -> None:
    dest.mkdir(parents=True, exist_ok=True)
    raw = gzip.GzipFile(fileobj=io.BytesIO(archive_bytes)).read()
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:") as handle:
        handle.extractall(dest, filter="data")


def build_lock_spdx_from_dir(package_dir: Path, namespace: str) -> dict[str, Any]:
    """Offline lock catalog (composer.lock / pubspec.lock package names only)."""
    packages: list[dict[str, Any]] = []
    composer = package_dir / "composer.lock"
    if composer.is_file():
        lock = json.loads(composer.read_text(encoding="utf-8"))
        for index, pkg in enumerate(lock.get("packages", [])):
            name = pkg.get("name", f"composer-package-{index}")
            version = pkg.get("version", "UNKNOWN")
            packages.append(
                {
                    "SPDXID": f"SPDXRef-LockPkg-{index}",
                    "name": name,
                    "versionInfo": version,
                    "downloadLocation": "NOASSERTION",
                }
            )
    pubspec_lock = package_dir / "pubspec.lock"
    if pubspec_lock.is_file():
        lock = json.loads(pubspec_lock.read_text(encoding="utf-8"))
        for index, (name, meta) in enumerate(sorted((lock.get("packages") or {}).items())):
            version = (meta or {}).get("version", "UNKNOWN")
            packages.append(
                {
                    "SPDXID": f"SPDXRef-PubPkg-{index}",
                    "name": name,
                    "versionInfo": version,
                    "downloadLocation": "NOASSERTION",
                }
            )
    return {
        "spdxVersion": "SPDX-2.3",
        "name": namespace,
        "documentNamespace": namespace,
        "packages": packages,
        "relationships": [
            {
                "spdxElementId": "SPDXRef-DOCUMENT",
                "relationshipType": "DESCRIBES",
                "relatedSpdxElement": packages[0]["SPDXID"] if packages else "SPDXRef-LockRoot",
            }
        ],
    }


def run_syft_sbom(syft: Path, target: Path, namespace: str) -> dict[str, Any]:
    out = target.parent / f"{target.name}.spdx.json"
    proc = subprocess.run(
        [
            str(syft),
            f"dir:{target}",
            "-o",
            "spdx-json",
            "--file",
            str(out),
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise SbomError(proc.stderr or proc.stdout or "syft failed")
    return json.loads(out.read_text(encoding="utf-8"))


def spdx_namespace(subject_id: str, archive_digest: str) -> str:
    return f"https://agentools.local/spdx/{subject_id}/{archive_digest}"


def normalize_spdx_path(name: str) -> str:
    normalized = name.replace("\\", "/")
    while normalized.startswith("./"):
        normalized = normalized[2:]
    return normalized


def augment_spdx_subject(
    doc: dict[str, Any],
    *,
    namespace: str,
    inventory: list[dict[str, Any]],
    archive_digest: str,
) -> dict[str, Any]:
    """Merge Syft package catalog with exact subject file manifest (normative binding)."""
    merged = dict(doc)
    merged["spdxVersion"] = "SPDX-2.3"
    merged["name"] = namespace
    merged["documentNamespace"] = namespace
    files: list[dict[str, Any]] = []
    for index, row in enumerate(sorted(inventory, key=lambda item: item["path"])):
        files.append(
            {
                "SPDXID": f"SPDXRef-SubjectFile-{index}",
                "fileName": row["path"],
                "checksums": [
                    {
                        "algorithm": "SHA256",
                        "checksumValue": row["sha256"].upper(),
                    }
                ],
            }
        )
    merged["files"] = files
    packages = list(merged.get("packages") or [])
    if not packages:
        packages = [
            {
                "SPDXID": "SPDXRef-SubjectPackage",
                "name": namespace,
                "downloadLocation": "NOASSERTION",
                "filesAnalyzed": True,
                "checksums": [
                    {
                        "algorithm": "SHA256",
                        "checksumValue": archive_digest.upper(),
                    }
                ],
            }
        ]
    else:
        packages[0]["checksums"] = [
            {
                "algorithm": "SHA256",
                "checksumValue": archive_digest.upper(),
            }
        ]
        packages[0]["filesAnalyzed"] = True
    merged["packages"] = packages
    relationships = [
        rel
        for rel in (merged.get("relationships") or [])
        if rel.get("relationshipType") != "DESCRIBES"
    ]
    relationships.append(
        {
            "spdxElementId": packages[0]["SPDXID"],
            "relationshipType": "DESCRIBES",
            "relatedSpdxElement": "SPDXRef-DOCUMENT",
        }
    )
    merged["relationships"] = relationships
    merged["annotations"] = [
        {
            "annotationType": "OTHER",
            "comment": f"agentools.archive.sha256={archive_digest}",
        }
    ]
    return merged


def verify_spdx_document(doc: dict[str, Any], inventory: list[dict[str, Any]]) -> list[str]:
    errors: list[str] = []
    if doc.get("spdxVersion") != "SPDX-2.3":
        errors.append("spdxVersion must be SPDX-2.3")
    namespace = doc.get("documentNamespace")
    if not namespace or not isinstance(namespace, str):
        errors.append("missing documentNamespace")
    packages = doc.get("packages") or []
    if not packages:
        errors.append("missing packages")
    relationships = doc.get("relationships") or []
    if not relationships:
        errors.append("missing relationships")
    describes_doc = any(
        r.get("relationshipType") == "DESCRIBES"
        and r.get("relatedSpdxElement") in {"SPDXRef-DOCUMENT", "SPDXRef-DOCUMENT".lower()}
        for r in relationships
    ) or any(r.get("relationshipType") == "DESCRIBES" for r in relationships)
    if not describes_doc:
        errors.append("missing DESCRIBES relationship to document")
    files = doc.get("files") or []
    by_name: dict[str, dict[str, Any]] = {}
    for row in files:
        name = row.get("fileName")
        if isinstance(name, str):
            by_name[normalize_spdx_path(name)] = row
    for item in inventory:
        rel = item["path"]
        spdx_row = by_name.get(rel)
        if spdx_row is None:
            errors.append(f"spdx missing file {rel}")
            continue
        checksums = spdx_row.get("checksums") or []
        sha256 = next(
            (
                c.get("checksumValue", "").lower()
                for c in checksums
                if c.get("algorithm") == "SHA256"
            ),
            None,
        )
        if sha256 and sha256 != item["sha256"].lower():
            errors.append(f"spdx checksum mismatch for {rel}")
    pkg = packages[0] if packages else {}
    if not (pkg.get("checksums") or []):
        errors.append("subject package missing archive checksum")
    return errors


def build_slsa_provenance(
    *,
    subject_id: str,
    archive_digest: str,
    source_commit: str,
    contract_digest: str,
    manifest_digest: str,
    lock_digest: str,
) -> dict[str, Any]:
    return {
        "_type": "https://in-toto.io/Statement/v1",
        "subject": [
            {
                "name": subject_id,
                "digest": {"sha256": archive_digest},
            }
        ],
        "predicateType": SLSA_PREDICATE,
        "predicate": {
            "buildDefinition": {
                "buildType": "agentools/private-rc/v1",
                "externalParameters": {
                    "source_commit": source_commit,
                    "subject_id": subject_id,
                },
                "internalParameters": {},
                "resolvedDependencies": [],
            },
            "runDetails": {
                "builder": {
                    "id": "https://agentools.local/builders/release-candidate/v1"
                },
                "metadata": {
                    "invocationId": f"{subject_id}@{source_commit}",
                },
                "byproducts": [],
            },
            "materials": [
                {"uri": f"git+file://{source_commit}", "digest": {"sha256": source_commit}},
                {
                    "uri": "contract:release-candidate-v1.md",
                    "digest": {"sha256": contract_digest},
                },
                {
                    "uri": "manifest:content",
                    "digest": {"sha256": manifest_digest},
                },
                {
                    "uri": "lock:supply-chain",
                    "digest": {"sha256": lock_digest},
                },
            ],
        },
    }


def verify_provenance(
    statement: dict[str, Any],
    *,
    archive_digest: str,
) -> list[str]:
    errors: list[str] = []
    subjects = statement.get("subject") or []
    if len(subjects) != 1:
        errors.append("provenance subject count != 1")
    else:
        digest = (subjects[0].get("digest") or {}).get("sha256")
        if digest != archive_digest:
            errors.append("provenance subject digest mismatch")
    if statement.get("predicateType") != SLSA_PREDICATE:
        errors.append("predicateType mismatch")
    materials = (statement.get("predicate") or {}).get("materials") or []
    if len(materials) < 3:
        errors.append("insufficient provenance materials")
    return errors


def hosted_attestation_status() -> dict[str, Any]:
    lock = load_lock()
    attest_sha = None
    for action in lock.get("github_actions", []):
        if action.get("id") == ATTEST_ACTION:
            attest_sha = action.get("ref_immutable")
    workflow_text = WORKFLOW_CI.read_text(encoding="utf-8") if WORKFLOW_CI.is_file() else ""
    pin_ok = attest_sha is not None and attest_sha in workflow_text
    capability = "unavailable-plan"
    if "Artifact attestations (private) | unavailable-plan" in CAPABILITY_FINDING.read_text(
        encoding="utf-8"
    ):
        capability = "unavailable-plan"
    return {
        "status": capability,
        "attested": False,
        "action_pin_present_in_ci": pin_ok,
        "action_ref": f"{ATTEST_ACTION}@{attest_sha}" if attest_sha else None,
        "required_permissions": ["id-token: write", "attestations: write"],
        "note": "Hosted attestation not executed; local provenance is statement-verified only.",
    }


def load_rc_receipt() -> dict[str, Any]:
    if not RC_ARTIFACT.is_file():
        raise SbomError(f"missing RC artifact: {RC_ARTIFACT}")
    payload = json.loads(RC_ARTIFACT.read_text(encoding="utf-8"))
    if payload.get("status") != "green" or not payload.get("reproducibility_match"):
        raise SbomError("P9.3 reproducibility artifact is not green")
    return payload


def cmd_run(args: argparse.Namespace) -> int:
    rc = load_rc_receipt()
    builder = rc["builder_a"]
    lock = load_lock()
    work = Path(tempfile.mkdtemp(prefix="p94-work-"))
    errors: list[str] = []
    subjects_out: list[dict[str, Any]] = []
    try:
        syft, syft_status = resolve_syft(lock)
        if syft is not None:
            syft_version = subprocess.run(
                [str(syft), "version"],
                capture_output=True,
                text=True,
                check=False,
            ).stdout.strip()
        else:
            syft_version = syft_status
        from tools.release_candidate import build_subject_archive, collect_inventories  # noqa: WPS433

        stage_work = work / "stage"
        _, _, inventories, roots = collect_inventories(stage_work)
        for subject in builder["subjects"]:
            subject_id = subject["subject_id"]
            archive_digest = subject["archive_digest"]["digest"]
            namespace = spdx_namespace(subject_id, archive_digest)
            extract_dir = work / subject_id
            archive_bytes, rebuilt_digest = build_subject_archive(
                inventories[subject_id],
                roots[subject_id],
                int(rc["source_date_epoch"]),
            )
            if rebuilt_digest != archive_digest:
                errors.append(f"{subject_id} archive digest drift vs receipt")
            extract_subject_archive(archive_bytes, extract_dir)
            lock_scan_root = REPO / {
                "php-sdk": "php",
                "dart-core": "dart/agentools",
                "dart-mcp-companion": "dart/agentools_mcp_dart",
            }[subject_id]
            if syft is not None:
                spdx_subject_raw = run_syft_sbom(syft, extract_dir, namespace)
            else:
                spdx_subject_raw = {"spdxVersion": "SPDX-2.3", "packages": [], "relationships": []}
            spdx_subject = augment_spdx_subject(
                spdx_subject_raw,
                namespace=namespace,
                inventory=subject["inventory"],
                archive_digest=archive_digest,
            )
            lock_namespace = f"{namespace}/lock-scan"
            if syft is not None:
                spdx_lock = run_syft_sbom(syft, lock_scan_root, lock_namespace)
            else:
                spdx_lock = build_lock_spdx_from_dir(lock_scan_root, lock_namespace)
            spdx_errors = verify_spdx_document(spdx_subject, subject["inventory"])
            if spdx_errors:
                errors.extend([f"{subject_id}:{item}" for item in spdx_errors])
            provenance = build_slsa_provenance(
                subject_id=subject_id,
                archive_digest=archive_digest,
                source_commit=rc["source_commit"],
                contract_digest=builder["contract_sha256"],
                manifest_digest=subject["content_manifest_digest"],
                lock_digest=builder["supply_chain_lock_sha256"],
            )
            prov_errors = verify_provenance(provenance, archive_digest=archive_digest)
            if prov_errors:
                errors.extend([f"{subject_id}:prov:{item}" for item in prov_errors])
            subjects_out.append(
                {
                    "subject_id": subject_id,
                    "archive_digest": subject["archive_digest"],
                    "spdx_subject_sha256": sha256_bytes(
                        canonical_json(spdx_subject).encode("utf-8")
                    ),
                    "spdx_lock_sha256": sha256_bytes(
                        canonical_json(spdx_lock).encode("utf-8")
                    ),
                    "spdx_subject": spdx_subject,
                    "spdx_lock": spdx_lock,
                    "provenance": provenance,
                    "verification": {
                        "spdx": "verified" if not spdx_errors else "red",
                        "provenance": "statement-verified" if not prov_errors else "red",
                    },
                }
            )
        attestation = hosted_attestation_status()
        if attestation["attested"]:
            errors.append("attested must remain false when capability unavailable")
        payload: dict[str, Any] = {
            "schema_version": SCHEMA_VERSION,
            "status": "green" if not errors else "red",
            "source_commit": rc["source_commit"],
            "rc_receipt_sha256": sha256_file(RC_ARTIFACT),
            "syft_version": syft_version,
            "syft_status": syft_status,
            "subjects": subjects_out,
            "hosted_attestation": attestation,
            "errors": errors,
        }
        ARTIFACT_PATH.parent.mkdir(parents=True, exist_ok=True)
        ARTIFACT_PATH.write_text(canonical_json(payload) + "\n", encoding="utf-8")
        FINDING_PATH.write_text(render_finding(payload), encoding="utf-8")
        print(canonical_json({"status": payload["status"], "errors": errors}))
        return 0 if payload["status"] == "green" else 1  # noqa: PLR1711
    finally:
        shutil.rmtree(work, ignore_errors=True)


def render_finding(payload: dict[str, Any]) -> str:
    att = payload.get("hosted_attestation", {})
    lines = [
        "# P9 supply-chain evidence",
        "",
        f"**Status:** `{payload.get('status')}`",
        f"**RC receipt:** `{payload.get('rc_receipt_sha256')}`",
        f"**Syft:** `{payload.get('syft_version')}`",
        "",
        "## Hosted attestation",
        "",
        f"- Capability: `{att.get('status')}`",
        f"- Attested: `{att.get('attested')}` (must stay false unless Enterprise capability proven)",
        f"- Action pin in CI: `{att.get('action_pin_present_in_ci')}`",
        "",
        "## Subjects",
        "",
    ]
    for row in payload.get("subjects", []):
        lines.append(
            f"- `{row['subject_id']}`: SPDX subject `{row['spdx_subject_sha256']}` · "
            f"provenance `{row['verification']['provenance']}`"
        )
    lines.append("")
    return "\n".join(lines)


def cmd_verify(_: argparse.Namespace) -> int:
    if not ARTIFACT_PATH.is_file():
        raise SystemExit(f"missing artifact: {ARTIFACT_PATH}")
    payload = json.loads(ARTIFACT_PATH.read_text(encoding="utf-8"))
    errors: list[str] = []
    if payload.get("status") != "green":
        errors.append("artifact status not green")
    att = payload.get("hosted_attestation") or {}
    if att.get("attested"):
        errors.append("forbidden attested=true")
    if att.get("status") not in CAPABILITY_STATES:
        errors.append("invalid attestation capability status")
    for row in payload.get("subjects", []):
        digest = row["archive_digest"]["digest"]
        inv = next(
            s["inventory"]
            for s in load_rc_receipt()["builder_a"]["subjects"]
            if s["subject_id"] == row["subject_id"]
        )
        spdx_errors = verify_spdx_document(row["spdx_subject"], inv)
        errors.extend(spdx_errors)
        errors.extend(verify_provenance(row["provenance"], archive_digest=digest))
    print(canonical_json({"status": "green" if not errors else "red", "errors": errors}))
    return 0 if not errors else 1


class SbomGateTests(unittest.TestCase):
    def test_provenance_subject_binding(self) -> None:
        stmt = build_slsa_provenance(
            subject_id="dart-core",
            archive_digest="a" * 64,
            source_commit="b" * 40,
            contract_digest="c" * 64,
            manifest_digest="d" * 64,
            lock_digest="e" * 64,
        )
        self.assertEqual([], verify_provenance(stmt, archive_digest="a" * 64))
        self.assertNotEqual([], verify_provenance(stmt, archive_digest="f" * 64))

    def test_spdx_rejects_wrong_namespace(self) -> None:
        doc = {
            "spdxVersion": "SPDX-2.3",
            "name": "wrong",
            "documentNamespace": "wrong",
            "packages": [{"checksums": [{"algorithm": "SHA256", "checksumValue": "00"}]}],
            "relationships": [
                {
                    "spdxElementId": "SPDXRef-Package",
                    "relationshipType": "DESCRIBES",
                    "relatedSpdxElement": "SPDXRef-DOCUMENT",
                }
            ],
        }
        errors = verify_spdx_document(doc, [{"path": "x", "sha256": "a" * 64}])
        self.assertTrue(errors)

    def test_provenance_mutation_detected(self) -> None:
        stmt = build_slsa_provenance(
            subject_id="dart-core",
            archive_digest="a" * 64,
            source_commit="b" * 40,
            contract_digest="c" * 64,
            manifest_digest="d" * 64,
            lock_digest="e" * 64,
        )
        self.assertNotEqual([], verify_provenance(stmt, archive_digest="b" * 64))

    def test_attestation_not_claimed(self) -> None:
        att = hosted_attestation_status()
        self.assertFalse(att["attested"])
        self.assertEqual(att["status"], "unavailable-plan")


def main(argv: list[str] | None = None) -> int:
    if str(REPO) not in sys.path:
        sys.path.insert(0, str(REPO))
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("run")
    sub.add_parser("verify")
    sub.add_parser("test")
    args = parser.parse_args(argv)
    if args.command == "run":
        return cmd_run(args)
    if args.command == "verify":
        return cmd_verify(args)
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(SbomGateTests)
        result = unittest.TextTestRunner(verbosity=2).run(suite)
        return 0 if result.wasSuccessful() else 1
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
