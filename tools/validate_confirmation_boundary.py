#!/usr/bin/env python3
"""Reject new direct ConfirmationProtocol prepare/commit calls outside the frozen allowlist."""

from __future__ import annotations

import argparse
import json
import re
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

ALLOWLIST = {
    ROOT / "php/src/Confirmation/ConfirmationProtocol.php",
    ROOT / "php/src/Confirmation/ConfirmationService.php",
    ROOT / "php/tests/Confirmation/ConfirmationProtocolTest.php",
    ROOT / "php/tests/Confirmation/DurableReplayGateTest.php",
    ROOT / "php/tests/Reference/Scenario/SliceConfirmationGatesTest.php",
    ROOT / "dart/agentools/lib/src/confirmation/confirmation_protocol.dart",
    ROOT / "dart/agentools/lib/src/confirmation/confirmation_service.dart",
    ROOT / "dart/agentools/test/confirmation/confirmation_protocol_test.dart",
    ROOT / "dart/agentools/test/confirmation/durable_replay_gate_test.dart",
}

SCAN_ROOTS = [
    ROOT / "php/src",
    ROOT / "php/reference",
    ROOT / "php/tests",
    ROOT / "dart/agentools/lib",
    ROOT / "dart/agentools/test",
]

PATTERN = re.compile(r"->\s*commit\s*\(|::\s*commit\s*\(|\.commit\s*\(")


def violations() -> list[str]:
    found: list[str] = []
    for base in SCAN_ROOTS:
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if path.suffix not in {".php", ".dart"}:
                continue
            if path in ALLOWLIST:
                continue
            text = path.read_text(encoding="utf-8")
            if "ConfirmationProtocol" not in text and ".commit(" not in text:
                continue
            for match in PATTERN.finditer(text):
                if "ConfirmationProtocol" in text or "confirmation_protocol" in text:
                    snippet = text[max(0, match.start() - 40) : match.start() + 20]
                    if "protocol" in snippet.lower() or "ConfirmationProtocol" in snippet:
                        found.append(f"{path.relative_to(ROOT)}:{match.start()}")
    return found


def self_probe() -> bool:
    probe = "class X { function f(ConfirmationProtocol $p) { $p->commit('t'); } }\n"
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as handle:
        handle.write(probe)
        path = Path(handle.name)
    text = path.read_text(encoding="utf-8")
    path.unlink(missing_ok=True)
    return bool(PATTERN.search(text))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    _ = parser.parse_args()
    if not self_probe():
        print("self-probe failed", file=sys.stderr)
        return 1
    bad = violations()
    payload = {"status": "green" if not bad else "red", "violations": bad, "allowlist": len(ALLOWLIST)}
    print(json.dumps(payload, sort_keys=True))
    return 0 if not bad else 1


if __name__ == "__main__":
    raise SystemExit(main())
