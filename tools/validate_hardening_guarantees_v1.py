#!/usr/bin/env python3
"""Scriptable checks for hardening-guarantees-v1.md (P1.4 validation helper)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = ROOT / "plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/hardening-guarantees-v1.md"
THREAT = ROOT / "plans/2026.09.19-№1-AGENTOOLS-HARDENING/root/contracts/threat-model-v1.md"

STATUSES = {"baseline-red", "baseline-partial", "designed", "proved"}
OWNER_PHASES = {"P2", "P3", "P4", "P5", "P6", "P7", "P8", "P9", "P10"}
OWNER_CELL = re.compile(
    r"^\| (?:G-|NG-)[^|]+\|(?:[^|]+\|){4} (P\d+(?:/P\d+)?|phase design) \|",
    re.MULTILINE,
)
OPEN_THREATS = tuple(f"TM-{n:03d}" for n in range(1, 13))


def main() -> int:
    text = CONTRACT.read_text(encoding="utf-8")
    threat = THREAT.read_text(encoding="utf-8")

    g_ids = re.findall(r"^\| (G-[A-Z]+-\d{3}) \|", text, flags=re.MULTILINE)
    ng_ids = re.findall(r"^\| (NG-[A-Z]+-\d{3}) \|", text, flags=re.MULTILINE)
    if len(g_ids) != len(set(g_ids)):
        print("duplicate G-* ids", file=sys.stderr)
        return 1
    if len(ng_ids) != len(set(ng_ids)):
        print("duplicate NG-* ids", file=sys.stderr)
        return 1
    if not g_ids or not ng_ids:
        print("missing guarantee or non-guarantee rows", file=sys.stderr)
        return 1

    for status in re.findall(r"\| (baseline-red|baseline-partial|designed|proved) \|", text):
        if status not in STATUSES:
            print(f"invalid status: {status}", file=sys.stderr)
            return 1

    if "| proved |" in text:
        print("forbidden proved without immutable evidence carrier", file=sys.stderr)
        return 1

    for phase in OWNER_PHASES:
        if phase not in text:
            print(f"missing owner phase: {phase}", file=sys.stderr)
            return 1

    for tm in OPEN_THREATS:
        if tm not in text:
            print(f"open threat not addressed: {tm}", file=sys.stderr)
            return 1

    guarantee_section = text.split("## 2. Non-guarantee catalog", 1)[0]
    for forbidden in ("exactly-once", "production-ready", "MCP compliant", "secure"):
        if re.search(rf"\| G-[^|]*\b{forbidden}\b", guarantee_section, flags=re.IGNORECASE):
            print(f"unqualified forbidden claim in guarantee row: {forbidden}", file=sys.stderr)
            return 1

    if "TM-013" not in text or "TM-014" not in text:
        print("missing bookkeeping threats TM-013/TM-014", file=sys.stderr)
        return 1

    if "partially trusted" not in threat.lower():
        print("threat-model prerequisite missing trust classes", file=sys.stderr)
        return 1

    for match in OWNER_CELL.finditer(text):
        owner = match.group(1)
        if "/" in owner:
            print(f"composite owner forbidden: {owner}", file=sys.stderr)
            return 1
        if owner != "phase design" and owner not in OWNER_PHASES:
            print(f"invalid owner phase: {owner}", file=sys.stderr)
            return 1

    print(f"hardening-guarantees v1 checks ok ({len(g_ids)} G, {len(ng_ids)} NG)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
