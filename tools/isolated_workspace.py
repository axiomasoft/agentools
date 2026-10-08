"""Disposable source trees shared by compatibility and mutation checks."""
from __future__ import annotations

import hashlib
import shutil
from pathlib import Path

ROOTS = ("php", "dart", "conformance", "tools", "scripts", "api", "fixtures", "spec", "verification", "consumers", "benchmarks")
EXCLUDED = {".git", "node_modules", ".dart_tool", "build", "tmp", "__pycache__", ".phpunit.result.cache"}


def copy_workspace(source: Path, target: Path) -> None:
    """Copy vendors, never symlink them: Composer resolves sources via vendorDir."""
    for name in ROOTS:
        if (source / name).exists():
            shutil.copytree(source / name, target / name, symlinks=True,
                            ignore=lambda _dir, names: set(names) & EXCLUDED)
    for name in ("README.md", "DEVELOPMENT.md", "LICENSE", ".gitleaks.toml"):
        if (source / name).is_file():
            shutil.copy2(source / name, target / name)
    # Reuse resolution, not compiled kernels or snapshots of original sources.
    for package in ("agentools", "agentools_mcp_dart"):
        config = source / "dart" / package / ".dart_tool/package_config.json"
        if config.is_file():
            dest = target / "dart" / package / ".dart_tool/package_config.json"
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(config, dest)


def source_fingerprints(root: Path) -> dict[str, str]:
    out = {}
    for name in ROOTS:
        for path in (root / name).rglob("*"):
            rel = path.relative_to(root)
            if set(rel.parts) & (EXCLUDED | {"vendor", "reports", "artifacts", "findings"}):
                continue
            if path.is_file():
                out[rel.as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    return out
