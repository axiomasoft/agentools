#!/usr/bin/env python3
"""Generate digest-qualified semantic-profile/v1 for revision 2026-09."""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
REVISION = REPO / "spec/revisions/2026-09"
MANIFEST_PATH = REVISION / "semantic-manifest.json"
ERROR_TABLE_HEADER = "**IDE-31 (MUST, L1).**"
CASE_GLOB = "fixtures/hardening/*-v1.json"

CATEGORY_DEFAULT_RETRYABLE = {
    "validation": False,
    "permission": False,
    "not_found": False,
    "precondition": False,
    "conflict": True,
    "unavailable": True,
    "budget": False,
    "internal": False,
}


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def canonical_json(value) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def parse_ide31_table(text: str) -> dict[str, dict]:
    start = text.find("| Код | Категория |")
    if start == -1:
        raise SystemExit("IDE-31 table not found")
    rows: dict[str, dict] = {}
    for line in text[start:].splitlines()[2:]:
        if not line.startswith("|"):
            break
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) != 4:
            continue
        code = cells[0].strip("`")
        category = cells[1].strip("`")
        producers = [p.strip() for p in re.split(r",", cells[2])]
        rows[code] = {
            "category": category,
            "producer_rules": producers,
            "when": cells[3],
        }
    return rows


def load_schema_codes(schema_path: Path) -> list[str]:
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    variants = schema["$defs"]["ErrorCode"]["oneOf"]
    return sorted(
        v["const"]
        for v in variants
        if isinstance(v, dict) and isinstance(v.get("const"), str) and v["const"].startswith("agentools.")
    )


def load_hardening_cases() -> list[dict]:
    cases = []
    for path in sorted(REPO.glob(CASE_GLOB)):
        data = json.loads(path.read_text(encoding="utf-8"))
        for case in data.get("cases", []):
            cases.append(
                {
                    "case_id": case["id"],
                    "semantic_code": case.get("semantic_code", ""),
                    "category": case.get("category", ""),
                    "retryable": case.get("retryable"),
                    "source": str(path.relative_to(REPO)),
                    "source_digest": sha256_file(path),
                }
            )
    cases.sort(key=lambda row: (row["case_id"], row["source"]))
    return cases


def load_states() -> list[dict]:
    sources = {
        "action": (REVISION / "schema/session-state.json", "ActionStatus"),
        "proposal": (REVISION / "schema/confirmation.json", "ProposalState"),
        "idempotency": (REVISION / "schema/idempotency.json", "DedupState"),
    }
    rows = []
    for domain, (path, definition) in sources.items():
        schema = json.loads(path.read_text(encoding="utf-8"))
        for value in schema["$defs"][definition]["enum"]:
            rows.append({"key": f"{domain}:{value}", "domain": domain, "value": value})
    return sorted(rows, key=lambda row: row["key"])


def load_transitions() -> list[dict]:
    path = REVISION / "03_action-lifecycle.md"
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.startswith("| ALC-T"):
            continue
        cells = [cell.strip().strip("`") for cell in line.split("|")[1:-1]]
        if len(cells) != 5:
            continue
        transition, source, event, target, _guard = cells
        rows.append({
            "transition": transition,
            "from": None if source.startswith("—") else source,
            "event": event,
            "to": target,
        })
    if len(rows) != 38:
        raise SystemExit(f"expected 38 lifecycle transitions, found {len(rows)}")
    return rows


def load_bounds() -> list[dict]:
    scenario = (REVISION / "06_scenarios.md").read_text(encoding="utf-8")
    audit = (REVISION / "08_audit-observability.md").read_text(encoding="utf-8")
    hardening = (REVISION / "12_hardening-semantics.md").read_text(encoding="utf-8")
    required = {
        "expression.max_depth": (scenario, r"SCN-16[\s\S]*?\*\*(\d+)\*\*"),
        "expression.max_length": (scenario, r"SCN-17[\s\S]*?\*\*(\d+)\*\*"),
        "expression.max_collection_size": (scenario, r"SCN-18[\s\S]*?\*\*(\d+)\*\*"),
        "expression.max_visited_nodes": (scenario, r"SCN-19[\s\S]*?\*\*(\d+)\*\*"),
        "event_store.max_page": (hardening, r"HRD-23[\s\S]*?\[1,(\d+)\]"),
        "baggage.max_items": (audit, r"AOB-31[\s\S]*?(\d+) элементов"),
        "baggage.max_bytes": (audit, r"AOB-31[\s\S]*?(\d+) байт"),
    }
    rows = []
    for bound, (text, pattern) in required.items():
        match = re.search(pattern, text)
        if match is None:
            raise SystemExit(f"normative bound not found: {bound}")
        rows.append({"bound": bound, "value": int(match.group(1))})
    # HRD-22 delegates the exact append limit to the candidate case manifest.
    event_cases = json.loads((REVISION.parents[2] / "fixtures/2026-09/hardening/event-store-v1.json").read_text(encoding="utf-8"))
    if not any(case.get("scenario") == "batch_over_64" for case in event_cases["cases"]):
        raise SystemExit("event-store batch bound case missing")
    rows.append({"bound": "event_store.max_batch", "value": 64})
    return sorted(rows, key=lambda row: row["bound"])


def load_outcomes() -> list[dict]:
    schema = json.loads((REVISION / "schema/scenario.json").read_text(encoding="utf-8"))
    return [
        {"key": f"scenario:{value}", "domain": "scenario", "value": value}
        for value in schema["$defs"]["ScenarioOutcome"]["enum"]
    ]


def load_capabilities() -> list[dict]:
    path = REPO / "fixtures/2026-09/hardening/idempotency-lease-v1.json"
    cases = json.loads(path.read_text(encoding="utf-8"))["cases"]
    mapping = {
        "native_key": "native_key",
        "read_before_write": "read_before_write",
        "compensatable": "compensatable",
        "at_most_once": "at_most_once_only",
        "unknown": "unknown",
    }
    found = set()
    for case in cases:
        if case.get("phase") != "capability":
            continue
        scenario = case["scenario"]
        for marker, capability in mapping.items():
            if marker in scenario:
                found.add(capability)
    if found != set(mapping.values()):
        raise SystemExit(f"capability case manifest incomplete: {sorted(found)}")
    return [{"capability": value} for value in sorted(found)]


def build_manifest() -> dict:
    error_doc = (REVISION / "09_idempotency-errors.md").read_text(encoding="utf-8")
    schema_path = REVISION / "schema/error.json"
    table = parse_ide31_table(error_doc)
    codes = load_schema_codes(schema_path)
    missing_table = sorted(set(codes) - set(table))
    extra_table = sorted(set(table) - set(codes))
    if missing_table or extra_table:
        raise SystemExit(f"schema/table mismatch missing={missing_table} extra={extra_table}")

    error_rows = []
    for code in codes:
        meta = table[code]
        category = meta["category"]
        error_rows.append(
            {
                "code": code,
                "category": category,
                "default_retryable": CATEGORY_DEFAULT_RETRYABLE[category],
                "producer_rules": meta["producer_rules"],
                "safe_context_keys": [],
                "source": "spec/revisions/2026-09/09_idempotency-errors.md#IDE-31",
                "source_digest": sha256_text(canonical_json({"code": code, **meta})),
            }
        )

    manifest = {
        "schema_version": "semantic-profile/v1",
        "spec_revision": "2026-09",
        "generated_by": "tools/semantic_manifest.py",
        "source_digests": {
            "error_schema_sha256": sha256_file(schema_path),
            "error_table_doc_sha256": sha256_file(REVISION / "09_idempotency-errors.md"),
            "hardening_glob": CASE_GLOB,
        },
        "canonicalization_profile": "RFC8785",
        "conformance_level_declared": "L1",
        "error_codes": error_rows,
        "case_retryability": load_hardening_cases(),
        "states": load_states(),
        "transitions": load_transitions(),
        "envelope_versions": [
            {"envelope": "confirmation_token", "version": 1, "prefix": "at1."},
        ],
        "bounds": load_bounds(),
        "outcomes": load_outcomes(),
        "capabilities": load_capabilities(),
    }
    manifest["manifest_digest"] = sha256_text(
        canonical_json({k: v for k, v in manifest.items() if k != "manifest_digest"})
    )
    return manifest


def main() -> int:
    mode = "--check" if "--check" in sys.argv else "--write"
    manifest = build_manifest()
    rendered = json.dumps(manifest, indent=2, ensure_ascii=False) + "\n"
    if mode == "--write":
        MANIFEST_PATH.write_text(rendered, encoding="utf-8")
        print(f"semantic_manifest: wrote {MANIFEST_PATH} ({len(manifest['error_codes'])} codes)")
        return 0
    if not MANIFEST_PATH.is_file():
        print(f"semantic_manifest: missing {MANIFEST_PATH}", file=sys.stderr)
        return 1
    if MANIFEST_PATH.read_text(encoding="utf-8") != rendered:
        print("semantic_manifest: stale manifest (--check)", file=sys.stderr)
        return 1
    print("semantic_manifest: OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
