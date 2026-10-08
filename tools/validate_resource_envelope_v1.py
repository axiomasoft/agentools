#!/usr/bin/env python3
"""Scriptable checks for resource-envelope-v1.md (P1.5 validation helper)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "verification/root/contracts/resource-envelope-v1.md"
COVERAGE = ROOT / "verification/findings/P1-contract-coverage.md"

REQUIRED_RL = tuple(f"RL-{n:03d}" for n in range(1, 26))
RL_OWNER_RE = re.compile(r"^\| (RL-\d{3}) \|(?:[^|]+\|){9} (P\d+(?:/P\d+)?) \|", re.MULTILINE)


def main() -> int:
    text = CONTRACT.read_text(encoding="utf-8")
    coverage = COVERAGE.read_text(encoding="utf-8")

    rl_ids = re.findall(r"^\| (RL-\d{3}) \|", text, flags=re.MULTILINE)
    if len(rl_ids) != len(set(rl_ids)):
        print("duplicate RL-### ids", file=sys.stderr)
        return 1
    for rl in REQUIRED_RL:
        if rl not in rl_ids:
            print(f"missing limit row: {rl}", file=sys.stderr)
            return 1

    if "missing" not in text.lower():
        print("missing limits must be explicit", file=sys.stderr)
        return 1
    if "forbidden" not in text.lower() and "forbidden" not in coverage.lower():
        print("benchmark protocol must forbid absolute cross-machine PR gating", file=sys.stderr)
        return 1
    if "cross-machine" not in text:
        print("missing cross-machine timing policy", file=sys.stderr)
        return 1
    if "BW-JCS-001" not in text:
        print("missing benchmark workloads", file=sys.stderr)
        return 1
    if "PASS" not in coverage:
        print("coverage gate not green", file=sys.stderr)
        return 1
    if "Unowned: 0" not in coverage:
        print("coverage report must claim zero unowned rows", file=sys.stderr)
        return 1

    for match in RL_OWNER_RE.finditer(text):
        owner = match.group(2)
        if "/" in owner:
            print(f"composite RL owner forbidden: {match.group(1)} -> {owner}", file=sys.stderr)
            return 1

    print(f"resource-envelope v1 checks ok ({len(rl_ids)} RL rows)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
