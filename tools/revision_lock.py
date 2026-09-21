#!/usr/bin/env python3
"""Generate digest-qualified candidate revision lock for 2026-09."""
from __future__ import annotations

import hashlib
import json
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
REVISION_DIR = REPO / "spec/revisions/2026-09"
FIXTURES = REPO / "fixtures/2026-09"
LOCK_PATH = REVISION_DIR / "revision.lock.json"


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def canonical_json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def digest_paths(glob: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for path in sorted(REPO.glob(glob)):
        if path.is_file():
            out[str(path.relative_to(REPO))] = sha256_file(path)
    return out


def build_lock() -> dict:
    normative = digest_paths("spec/revisions/2026-09/*.md")
    normative.update(digest_paths("spec/revisions/2026-09/schema/**/*.json"))
    fixtures = digest_paths("fixtures/2026-09/**/*.json")
    generated = {
        "rules.json": sha256_file(REVISION_DIR / "rules.json"),
        "semantic-manifest.json": sha256_file(REVISION_DIR / "semantic-manifest.json"),
    }
    lock = {
        "schema_version": "revision-lock/v1",
        "spec_revision": "2026-09",
        "status": "candidate",
        "generated_by": "tools/revision_lock.py",
        "base_revision": "2026-08",
        "base_tag": "spec-2026-08",
        "normative_digests": normative,
        "fixture_digests": fixtures,
        "generated_artifact_digests": generated,
    }
    lock["lock_digest"] = sha256_text(
        canonical_json({k: v for k, v in lock.items() if k != "lock_digest"})
    )
    return lock


def main() -> int:
    mode = "--check" if "--check" in sys.argv else "--write"
    lock = build_lock()
    rendered = json.dumps(lock, indent=2, ensure_ascii=False) + "\n"
    if mode == "--write":
        LOCK_PATH.write_text(rendered, encoding="utf-8")
        print(f"revision_lock: wrote {LOCK_PATH}")
        return 0
    if not LOCK_PATH.is_file() or LOCK_PATH.read_text(encoding="utf-8") != rendered:
        print("revision_lock: stale lock", file=sys.stderr)
        return 1
    print("revision_lock: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
