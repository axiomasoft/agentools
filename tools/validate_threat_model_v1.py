#!/usr/bin/env python3
"""Scriptable checks for threat-model-v1.md (P1.2 validation helper)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/threat-model-v1.md"

REQUIRED_FINDINGS = tuple(f"F{n:02d}" for n in range(1, 13))
OWNER_PHASES = {"P2", "P3", "P4", "P5", "P6", "P7", "P8", "P9", "P10"}


def main() -> int:
    text = CONTRACT.read_text(encoding="utf-8")
    ids = re.findall(r"^\| TM-(\d{3}) \|", text, flags=re.MULTILINE)
    if len(ids) != len(set(ids)):
        print("duplicate TM-### ids", file=sys.stderr)
        return 1
    if "mitigated" in text.lower() and "| mitigated |" in text.lower():
        print("forbidden mitigated rows without evidence", file=sys.stderr)
        return 1
    for finding in REQUIRED_FINDINGS:
        if finding not in text:
            print(f"missing finding mapping: {finding}", file=sys.stderr)
            return 1
    for phase in OWNER_PHASES:
        if phase not in text:
            print(f"missing owner phase reference: {phase}", file=sys.stderr)
            return 1
    if "partially trusted" not in text.lower() or "untrusted" not in text.lower():
        print("missing trust classes", file=sys.stderr)
        return 1
    if "exactly-once" not in text or "secure" not in text:
        print("missing scoped secure/exactly-once language", file=sys.stderr)
        return 1
    print(f"threat-model v1 checks ok ({len(set(ids))} TM ids)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
