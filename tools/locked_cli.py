#!/usr/bin/env python3
"""Materialize CLI tools pinned in .ci/supply-chain-lock.json, verifying sha256 on every use.

The downloaded artifact is the only cached object and it is re-hashed against the lock each
time it is read, so a tampered or truncated cache entry is discarded instead of executed.
Binaries are extracted from verified bytes into a fresh private directory; archive members
are selected by exact binary name and extracted with the tarfile ``data`` filter.
"""

from __future__ import annotations

import hashlib
import io
import os
import tarfile
import tempfile
import unittest
import urllib.request
from pathlib import Path
from typing import Any, Callable

CACHE_ROOT = Path(tempfile.gettempdir()) / "agentools-locked-cli"

Fetch = Callable[[str], bytes]


class LockedToolError(RuntimeError):
    """A pinned tool could not be fetched or materialized safely."""


def _download(url: str) -> bytes:
    with urllib.request.urlopen(url, timeout=120) as response:  # noqa: S310 - https enforced below
        data: bytes = response.read()
    return data


def verified_artifact(
    entry: dict[str, Any],
    cache_root: Path = CACHE_ROOT,
    fetch: Fetch = _download,
) -> bytes:
    """Return artifact bytes whose sha256 equals the lock entry; cached copies are re-verified."""
    expected = str(entry["sha256"])
    cached = cache_root / expected
    if cached.is_file():
        data = cached.read_bytes()
        if hashlib.sha256(data).hexdigest() == expected:
            return data
    url = str(entry["download_url"])
    if not url.startswith("https://"):
        raise LockedToolError(f"{entry['id']}: download_url must use https")
    data = fetch(url)
    if hashlib.sha256(data).hexdigest() != expected:
        raise LockedToolError(f"{entry['id']} checksum mismatch")
    cache_root.mkdir(mode=0o700, parents=True, exist_ok=True)
    staging = cache_root / f"{expected}.{os.getpid()}.tmp"
    staging.write_bytes(data)
    staging.replace(cached)
    return data


def materialize_binary(entry: dict[str, Any], data: bytes, binary_name: str, workdir: Path) -> Path:
    """Write the executable for ``entry`` from verified ``data`` into ``workdir``."""
    if str(entry["download_url"]).endswith(".tar.gz"):
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as archive:
            member = next(
                (
                    item
                    for item in archive.getmembers()
                    if item.isfile() and Path(item.name).name == binary_name
                ),
                None,
            )
            if member is None:
                raise LockedToolError(f"{entry['id']}: {binary_name} binary missing in archive")
            if Path(member.name).is_absolute():
                # The data filter strips the leading slash during extraction, but
                # joining the original absolute name below would escape workdir.
                raise LockedToolError(f"{entry['id']}: absolute binary member path")
            archive.extract(member, path=workdir, filter="data")
        binary = workdir / member.name
    else:
        binary = workdir / binary_name
        binary.write_bytes(data)
    binary.chmod(0o755)
    return binary


def install(
    entry: dict[str, Any],
    binary_name: str,
    cache_root: Path = CACHE_ROOT,
    fetch: Fetch = _download,
) -> Path:
    """Verify the pinned artifact and materialize its binary in a new private directory."""
    data = verified_artifact(entry, cache_root, fetch)
    workdir = Path(tempfile.mkdtemp(prefix=f"agentools-{binary_name}-"))
    return materialize_binary(entry, data, binary_name, workdir)


def _tar_gz(members: dict[str, bytes]) -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as archive:
        for name, payload in members.items():
            info = tarfile.TarInfo(name)
            info.size = len(payload)
            archive.addfile(info, io.BytesIO(payload))
    return buffer.getvalue()


def _entry(data: bytes, url: str) -> dict[str, Any]:
    return {"id": "acme/tool", "download_url": url, "sha256": hashlib.sha256(data).hexdigest()}


class LockedCliTests(unittest.TestCase):
    def test_downloads_verifies_and_caches_artifact(self) -> None:
        data = b"#!/bin/sh\necho ok\n"
        calls: list[str] = []

        def fetch(url: str) -> bytes:
            calls.append(url)
            return data

        with tempfile.TemporaryDirectory() as tmp:
            entry = _entry(data, "https://example.invalid/tool")
            self.assertEqual(verified_artifact(entry, Path(tmp), fetch), data)
            self.assertEqual(verified_artifact(entry, Path(tmp), fetch), data)
        self.assertEqual(calls, ["https://example.invalid/tool"])

    def test_tampered_cache_is_discarded_and_refetched(self) -> None:
        data = b"genuine"
        with tempfile.TemporaryDirectory() as tmp:
            entry = _entry(data, "https://example.invalid/tool")
            (Path(tmp) / entry["sha256"]).write_bytes(b"planted")
            self.assertEqual(verified_artifact(entry, Path(tmp), lambda _: data), data)
            self.assertEqual((Path(tmp) / entry["sha256"]).read_bytes(), data)

    def test_checksum_mismatch_is_rejected_and_not_cached(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            entry = _entry(b"genuine", "https://example.invalid/tool")
            with self.assertRaises(LockedToolError):
                verified_artifact(entry, Path(tmp), lambda _: b"swapped")
            self.assertFalse((Path(tmp) / entry["sha256"]).exists())

    def test_non_https_url_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            entry = _entry(b"x", "http://example.invalid/tool")
            with self.assertRaises(LockedToolError):
                verified_artifact(entry, Path(tmp), lambda _: b"x")

    def test_extracts_only_the_named_member_inside_workdir(self) -> None:
        data = _tar_gz({"../escape": b"evil", "LICENSE": b"text", "dist/tool": b"binary"})
        with tempfile.TemporaryDirectory() as tmp:
            workdir = Path(tmp) / "work"
            workdir.mkdir()
            binary = materialize_binary(_entry(data, "https://example.invalid/t.tar.gz"), data, "tool", workdir)
            self.assertEqual(binary, workdir / "dist/tool")
            self.assertEqual(binary.read_bytes(), b"binary")
            self.assertTrue(os.access(binary, os.X_OK))
            self.assertFalse((Path(tmp) / "escape").exists())
            self.assertFalse((workdir / "LICENSE").exists())

    def test_traversing_binary_member_is_refused(self) -> None:
        data = _tar_gz({"../tool": b"evil"})
        with tempfile.TemporaryDirectory() as tmp:
            workdir = Path(tmp) / "work"
            workdir.mkdir()
            with self.assertRaises(tarfile.FilterError):
                materialize_binary(_entry(data, "https://example.invalid/t.tar.gz"), data, "tool", workdir)
            self.assertFalse((Path(tmp) / "tool").exists())

    def test_missing_binary_member_is_an_error(self) -> None:
        data = _tar_gz({"README": b"x"})
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaises(LockedToolError):
                materialize_binary(_entry(data, "https://example.invalid/t.tar.gz"), data, "tool", Path(tmp))

    def test_absolute_binary_member_cannot_select_an_existing_external_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            outside = Path(tmp) / "tool"
            outside.write_bytes(b"planted")
            outside.chmod(0o600)
            workdir = Path(tmp) / "work"
            workdir.mkdir()
            data = _tar_gz({str(outside): b"verified"})
            with self.assertRaises(LockedToolError):
                materialize_binary(_entry(data, "https://example.invalid/t.tar.gz"), data, "tool", workdir)
            self.assertEqual(outside.read_bytes(), b"planted")
            self.assertEqual(outside.stat().st_mode & 0o777, 0o600)

    def test_plain_binary_is_written_executable(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            binary = materialize_binary(_entry(b"bin", "https://example.invalid/tool"), b"bin", "tool", Path(tmp))
            self.assertEqual(binary.read_bytes(), b"bin")
            self.assertTrue(os.access(binary, os.X_OK))


if __name__ == "__main__":
    unittest.main()
