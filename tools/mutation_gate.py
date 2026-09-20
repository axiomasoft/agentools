#!/usr/bin/env python3
"""Critical semantic mutant gate (P8.3). Patches run in disposable copies."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
PLAN = REPO / "plans/2026.09.19-№1-AGENTOOLS-HARDENING"
MANIFEST = REPO / "fixtures/hardening/adversarial/manifest.v1.json"
MUTATION_DIR = REPO / "fixtures/hardening/adversarial/mutations"
CATALOG_PATH = MUTATION_DIR / "catalog.v1.json"
RECEIPT = PLAN / "artifacts/P8-mutation-baseline.json"
INFECTION_CONFIG = REPO / "php/infection.json5"

PHP_UNIT = ["php", "vendor/bin/phpunit", "--no-output"]
# phpunit 12 may not support --no-output; commands below are explicit.


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=True, sort_keys=True, separators=(",", ":")) + "\n"


def patches() -> list[dict[str, Any]]:
    return [
        {"id": "MUT-CNF-AUTH-BYPASS", "sdk": "php", "path": "php/src/Confirmation/ConfirmationProtocol.php", "search": "if (!$authorizer->isAllowed($principal, $parsed->toolName)) {", "replace": "if (false && !$authorizer->isAllowed($principal, $parsed->toolName)) {", "phpunit": ["tests/Confirmation/DurableReplayGateTest.php", "tests/Confirmation/ConfirmationProtocolTest.php"]},
        {"id": "MUT-CNF-AUTH-BYPASS", "sdk": "dart", "path": "dart/agentools/lib/src/confirmation/confirmation_protocol.dart", "search": "if (!authorizer.isAllowed(principal, parsed.toolName)) {", "replace": "if (false && !authorizer.isAllowed(principal, parsed.toolName)) {", "dart_test": ["test/confirmation/durable_replay_gate_test.dart", "test/confirmation/confirmation_protocol_test.dart"]},
        {"id": "MUT-CNF-CLOCK-FORGE", "sdk": "php", "path": "php/src/Confirmation/TokenEnvelope.php", "search": "if ($now->getTimestamp() - $skew >= $exp->getTimestamp()) {", "replace": "if (false && $now->getTimestamp() - $skew >= $exp->getTimestamp()) {", "phpunit": ["tests/Confirmation/TokenEnvelopeTest.php"]},
        {"id": "MUT-CNF-CLOCK-FORGE", "sdk": "dart", "path": "dart/agentools/lib/src/confirmation/token_envelope.dart", "search": "if (nowUtc.millisecondsSinceEpoch ~/ 1000 - skew >=", "replace": "if (false && nowUtc.millisecondsSinceEpoch ~/ 1000 - skew >=", "dart_test": ["test/confirmation/token_envelope_test.dart"]},
        {"id": "MUT-TKN-SKIP-VERIFY", "sdk": "php", "path": "php/src/Confirmation/TokenEnvelope.php", "search": "if (!hash_equals(hash_hmac('sha256', $signingInput, $key->secret, true), $mac)) {", "replace": "if (false && !hash_equals(hash_hmac('sha256', $signingInput, $key->secret, true), $mac)) {", "phpunit": ["tests/Confirmation/TokenEnvelopeTest.php"]},
        {"id": "MUT-TKN-SKIP-VERIFY", "sdk": "dart", "path": "dart/agentools/lib/src/confirmation/token_envelope.dart", "search": "if (!_constantTimeEquals(expected, mac)) {", "replace": "if (false && !_constantTimeEquals(expected, mac)) {", "dart_test": ["test/confirmation/token_envelope_test.dart"]},
        {"id": "MUT-TKN-SKIP-CONSUME", "sdk": "php", "path": "php/src/Confirmation/ConfirmationProtocol.php", "search": "        $this->store->consume(\n            $envelope->tenantId,\n            $envelope->jti,", "replace": "        if (false) $this->store->consume(\n            $envelope->tenantId,\n            $envelope->jti,", "phpunit": ["tests/Confirmation/DurableReplayGateTest.php"]},
        {"id": "MUT-TKN-SKIP-CONSUME", "sdk": "dart", "path": "dart/agentools/lib/src/confirmation/confirmation_protocol.dart", "search": "    _store.consume(", "replace": "    if (false) _store.consume(", "dart_test": ["test/confirmation/durable_replay_gate_test.dart"]},
        {"id": "MUT-IDC-STALE-FENCE", "sdk": "php", "path": "php/testkit/InMemoryDeduplicationStore.php", "search": "            || $record['lease']->token !== $lease->token", "replace": "            || (false && $record['lease']->token !== $lease->token)", "phpunit": ["tests/Idempotency/LeaseFenceTest.php"]},
        {"id": "MUT-IDC-STALE-FENCE", "sdk": "dart", "path": "dart/agentools/lib/src/idempotency/in_memory_deduplication_store.dart", "search": "        record.lease!.token != lease.token) {", "replace": "        false && record.lease!.token != lease.token) {", "dart_test": ["test/idempotency/lease_fence_test.dart"]},
        {"id": "MUT-IDC-UNKNOWN-ALLOW", "sdk": "php", "path": "php/src/Idempotency/IdempotencyCoordinator.php", "search": "        if ($capability === ToolIdempotencyCapability::Unknown && $this->catalog->isHighRisk($tool)) {", "replace": "        if (false && $capability === ToolIdempotencyCapability::Unknown && $this->catalog->isHighRisk($tool)) {", "phpunit": ["tests/Idempotency/IdempotencyCoordinatorTest.php"]},
        {"id": "MUT-IDC-UNKNOWN-ALLOW", "sdk": "dart", "path": "dart/agentools/lib/src/idempotency/idempotency_coordinator.dart", "search": "    if (capability == ToolIdempotencyCapability.unknown &&\n        _catalog.isHighRisk(tool)) {", "replace": "    if (false && capability == ToolIdempotencyCapability.unknown &&\n        _catalog.isHighRisk(tool)) {", "dart_test": ["test/idempotency/idempotency_coordinator_test.dart"]},
        {"id": "MUT-EVT-SKIP-COLLISION", "sdk": "php", "path": "php/testkit/InMemoryEventStore.php", "search": "            if ($row['hash'] !== $hashes[$index]) {\n                throw new EventIdCollision($event->eventId);", "replace": "            if (false && $row['hash'] !== $hashes[$index]) {\n                throw new EventIdCollision($event->eventId);", "phpunit": ["tests/Persistence/EventStoreCollisionTest.php"]},
        {"id": "MUT-EVT-SKIP-COLLISION", "sdk": "dart", "path": "dart/agentools/lib/src/persistence/in_memory_event_store.dart", "search": "      if (row.hash != hashes[index] || row.actionId != actionId) {\n        throw EventIdCollision(events[index].eventId);", "replace": "      if (false && (row.hash != hashes[index] || row.actionId != actionId)) {\n        throw EventIdCollision(events[index].eventId);", "dart_test": ["test/journal/event_store_collision_test.dart"]},
        {"id": "MUT-EVT-SKIP-VERSION", "sdk": "php", "path": "php/testkit/InMemoryEventStore.php", "search": "            if ($expectedPrevSeq !== $actual) {\n                throw new VersionConflict($expectedPrevSeq, $actual);", "replace": "            if (false && $expectedPrevSeq !== $actual) {\n                throw new VersionConflict($expectedPrevSeq, $actual);", "phpunit": ["tests/Persistence/EventStoreCollisionTest.php"]},
        {"id": "MUT-EVT-SKIP-VERSION", "sdk": "dart", "path": "dart/agentools/lib/src/persistence/in_memory_event_store.dart", "search": "      if (expectedPrevSeq != actual) {\n        throw VersionConflict(expected: expectedPrevSeq, actual: actual);", "replace": "      if (false && expectedPrevSeq != actual) {\n        throw VersionConflict(expected: expectedPrevSeq, actual: actual);", "dart_test": ["test/journal/event_store_collision_test.dart"]},
        {"id": "MUT-JCS-SKIP-SORT", "sdk": "php", "path": "php/src/Schema/Canonicalizer.php", "search": "        uksort($members, static function (string $left, string $right): int {\n            return strcmp(\n                mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),\n                mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),\n            );\n        });", "replace": "        ksort($members, SORT_STRING);", "phpunit": ["tests/Schema/CanonicalizerOracleTest.php"]},
        {"id": "MUT-JCS-SKIP-SORT", "sdk": "dart", "path": "dart/agentools/lib/src/schema/canonicalizer.dart", "search": "    final keys = members.keys.toList()..sort(_compareUtf16CodeUnits);", "replace": "    final keys = members.keys.toList();", "dart_test": ["test/schema/canonicalizer_oracle_test.dart"]},
        {"id": "MUT-SCN-RELAX-DEPTH", "sdk": "php", "path": "php/src/Scenario/ExpressionEvaluator.php", "search": "            if (++$this->parseDepth > $this->limits->maxDepth) {", "replace": "            if (false && ++$this->parseDepth > $this->limits->maxDepth) {", "phpunit": ["tests/Scenario/ExpressionEvaluatorTest.php"]},
        {"id": "MUT-SCN-RELAX-DEPTH", "sdk": "dart", "path": "dart/agentools/lib/src/scenario/expression_parser.dart", "search": "      if (_parseDepth > _limits.maxDepth) {", "replace": "      if (false && _parseDepth > _limits.maxDepth) {", "dart_test": ["test/scenario/expression_evaluator_test.dart"]},
        {"id": "MUT-OPS-RELAX-BAGGAGE", "sdk": "php", "path": "php/src/Ops/BaggageRefs.php", "search": "    private const MAX_ITEMS = 64;", "replace": "    private const MAX_ITEMS = 128;", "phpunit": ["tests/Ops/BaggageRefsTest.php", "tests/Adversarial/AdversarialCorpusTest.php"]},
        {"id": "MUT-OPS-RELAX-BAGGAGE", "sdk": "dart", "path": "dart/agentools/lib/src/ops/baggage_refs.dart", "search": "  static const _maxItems = 64;", "replace": "  static const _maxItems = 128;", "dart_test": ["test/ops/baggage_refs_test.dart", "test/adversarial/adversarial_corpus_test.dart"]},
        {"id": "MUT-OPS-SKIP-REDACTION", "sdk": "php", "path": "php/src/Ops/AuditEventFactory.php", "search": "        $this->redaction->assertPayload($payload);\n        $event = new ActionEvent(\n            eventId: JournalAppender::newEventId(),", "replace": "        if (false) $this->redaction->assertPayload($payload);\n        $event = new ActionEvent(\n            eventId: JournalAppender::newEventId(),", "phpunit": ["tests/Ops/AuditEventFactoryTest.php"]},
        {"id": "MUT-OPS-SKIP-REDACTION", "sdk": "dart", "path": "dart/agentools/lib/src/ops/audit_event_factory.dart", "search": "    _redaction.assertPayload(Map<String, dynamic>.from(payload));", "replace": "    if (false) _redaction.assertPayload(Map<String, dynamic>.from(payload));", "dart_test": ["test/ops/audit_event_factory_test.dart"]},
        {"id": "MUT-PKG-EXPORT-TESTKIT", "sdk": "php", "path": "php/composer.json", "search": "      \"AgenTools\\\\\": \"src/\"\n    }\n  },\n  \"autoload-dev\": {\n    \"psr-4\": {\n      \"AgenTools\\\\Reference\\\\\": \"reference/\",\n      \"AgenTools\\\\TestKit\\\\\": \"testkit/\",", "replace": "      \"AgenTools\\\\\": \"src/\",\n      \"AgenTools\\\\TestKit\\\\\": \"testkit/\"\n    }\n  },\n  \"autoload-dev\": {\n    \"psr-4\": {\n      \"AgenTools\\\\Reference\\\\\": \"reference/\",", "phpunit": ["tests/Adversarial/PackageBoundaryKillTest.php"]},
        {"id": "MUT-PKG-EXPORT-TESTKIT", "sdk": "dart", "path": "dart/agentools/lib/agentools.dart", "search": "export 'src/smoke.dart';", "replace": "export 'src/smoke.dart';\nexport 'agentools_testkit.dart';", "dart_test": ["test/agentools_smoke_test.dart"], "extra": ["python3", str(REPO / "tools/package_surface.py"), "diff", "--actual", "--strict"]},
    ]


def emit_catalog() -> dict[str, Any]:
    items = []
    for patch in patches():
        path = REPO / patch["path"]
        text = path.read_text(encoding="utf-8")
        if patch["search"] not in text:
            raise SystemExit(f"search not found: {patch['id']} {patch['sdk']} {patch['path']}")
        items.append({
            **patch,
            "anchor_sha256": sha256_file(path),
        })
    catalog = {"schema": "adversarial-mutations/v1", "patches": items}
    MUTATION_DIR.mkdir(parents=True, exist_ok=True)
    CATALOG_PATH.write_text(canonical_json(catalog), encoding="utf-8")
    return catalog


def apply_patch(text: str, search: str, replace: str) -> str:
    if text.count(search) != 1:
        raise ValueError(f"search must occur exactly once, found {text.count(search)}")
    return text.replace(search, replace, 1)


def run_cmd(command: list[str], cwd: Path, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=str(cwd), text=True, capture_output=True, timeout=timeout, check=False)


def run_patch(patch: dict[str, Any], *, workspace: Path) -> dict[str, Any]:
    started = time.time()
    src = workspace / patch["path"]
    original = src.read_bytes()
    if sha256_bytes(original) != patch["anchor_sha256"]:
        return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "stale-anchor", "killed": False}
    try:
        mutated = apply_patch(original.decode("utf-8"), patch["search"], patch["replace"])
    except ValueError as exc:
        return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "ambiguous-patch", "killed": False, "error": str(exc)}
    src.write_text(mutated, encoding="utf-8")
    try:
        if patch["sdk"] == "php":
            cmd = ["php", "vendor/bin/phpunit", *patch["phpunit"]]
            proc = run_cmd(cmd, workspace / "php")
        else:
            cmd = ["dart", "test", *patch["dart_test"]]
            proc = run_cmd(cmd, workspace / "dart/agentools")
            extra = patch.get("extra")
            if extra and proc.returncode == 0:
                extra_proc = run_cmd(extra, workspace)
                if extra_proc.returncode != 0:
                    proc = extra_proc
        duration_ms = int((time.time() - started) * 1000)
        stderr = (proc.stderr or "") + (proc.stdout or "")
        compile_invalid = any(token in stderr for token in ("Parse error", "syntax error", "Error: Unexpected", "compile-time error"))
        if compile_invalid:
            return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "compile-invalid", "killed": False, "duration_ms": duration_ms, "command": cmd}
        if proc.returncode == 0:
            return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "survived", "killed": False, "duration_ms": duration_ms, "command": cmd}
        return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "killed", "killed": True, "duration_ms": duration_ms, "command": cmd, "exit": proc.returncode}
    except subprocess.TimeoutExpired:
        return {"id": patch["id"], "sdk": patch["sdk"], "outcome": "timeout", "killed": False}
    finally:
        src.write_bytes(original)


def workspace_copy() -> Path:
    tmp = Path(tempfile.mkdtemp(prefix="agentools-mut-"))
    for rel in ("php", "dart", "tools", "api", "fixtures", "spec", "plans"):
        src = REPO / rel
        dst = tmp / rel
        if rel == "php":
            dst.mkdir(parents=True)
            for name in ("src", "tests", "testkit", "reference", "composer.json", "composer.lock", "phpunit.xml", "phpstan.neon", "infection.json5"):
                item = src / name
                if item.is_dir():
                    shutil.copytree(item, dst / name, ignore=shutil.ignore_patterns("vendor", ".phpunit.cache"))
                elif item.exists():
                    shutil.copy2(item, dst / name)
            os.symlink(src / "vendor", dst / "vendor")
        elif rel == "dart":
            shutil.copytree(src, dst, ignore=shutil.ignore_patterns(".dart_tool", "build"))
            dart_tool = src / "agentools" / ".dart_tool"
            if dart_tool.exists():
                os.symlink(dart_tool, dst / "agentools" / ".dart_tool")
        else:
            shutil.copytree(src, dst, ignore=shutil.ignore_patterns(".git"))
    return tmp


def run_all() -> dict[str, Any]:
    emit_catalog()
    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    fingerprint_path = REPO / "php/src/Confirmation/TokenEnvelope.php"
    before = sha256_file(fingerprint_path)
    results = [run_patch(patch, workspace=REPO) for patch in catalog["patches"]]
    after = sha256_file(fingerprint_path)
    killed = [row for row in results if row["killed"]]
    survivors = [row for row in results if not row["killed"]]
    receipt = {
        "schema": "p8-mutation-baseline/v1",
        "manifest_sha256": sha256_file(MANIFEST),
        "catalog_sha256": sha256_file(CATALOG_PATH),
        "workspace_unchanged": before == after,
        "results": results,
        "killed": len(killed),
        "survived": [f"{row['id']}:{row['sdk']}:{row['outcome']}" for row in survivors],
        "verdict": "green" if not survivors and before == after else "red",
    }
    RECEIPT.parent.mkdir(parents=True, exist_ok=True)
    RECEIPT.write_text(canonical_json(receipt), encoding="utf-8")
    return receipt


class MutationGateTests(unittest.TestCase):
    def test_stale_anchor_is_red(self) -> None:
        patch = patches()[0] | {"anchor_sha256": "0" * 64, "search": "nope", "replace": "nope"}
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            target = workspace / patch["path"]
            target.parent.mkdir(parents=True)
            target.write_text("other", encoding="utf-8")
            row = run_patch(patch, workspace=workspace)
        self.assertEqual(row["outcome"], "stale-anchor")
        self.assertFalse(row["killed"])

    def test_ambiguous_patch_is_red(self) -> None:
        patch = dict(patches()[0])
        workspace = REPO
        patch["search"] = "public"
        patch["replace"] = "public"
        patch["anchor_sha256"] = sha256_file(REPO / patch["path"])
        row = run_patch(patch, workspace=workspace)
        self.assertEqual(row["outcome"], "ambiguous-patch")

    def test_weakened_ordinary_assertion_lets_mutant_survive(self) -> None:
        patch = next(item for item in patches() if item["id"] == "MUT-OPS-RELAX-BAGGAGE" and item["sdk"] == "php")
        patch = dict(patch)
        patch["phpunit"] = ["tests/Ops/BaggageRefsTest.php", "--filter", "testItAcceptsOpaqueIdentifiers"]
        patch["anchor_sha256"] = sha256_file(REPO / patch["path"])
        row = run_patch(patch, workspace=REPO)
        self.assertEqual(row["outcome"], "survived")
        self.assertFalse(row["killed"])


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("command", nargs="?", default="run", choices=("emit", "run", "test"))
    args = parser.parse_args(argv)
    if args.command == "emit":
        emit_catalog()
        print(f"wrote {CATALOG_PATH.relative_to(REPO)}")
        return 0
    if args.command == "test":
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(MutationGateTests)
        result = unittest.TextTestRunner(verbosity=1).run(suite)
        return 0 if result.wasSuccessful() else 1
    receipt = run_all()
    if receipt["verdict"] != "green":
        print("mutation gate red: " + ",".join(receipt["survived"]), file=sys.stderr)
        return 1
    print(f"mutation gate green ({receipt['killed']} killed)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
