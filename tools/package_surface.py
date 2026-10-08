#!/usr/bin/env python3
"""Deterministic PHP/Dart package-surface inventory, target roster and drift gate."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[1]
SCHEMA_VERSION = "package-surface/v1"
CONTRACT_PATH = REPO / "verification/root/contracts/package-boundary-v1.md"
SURFACE_PATH = REPO / "api/package-surface-v1.json"
BASELINE_PATH = REPO / "verification/artifacts/P1-baseline.json"
PHP_COMPOSER = REPO / "php/composer.json"

TIERS = ("stable", "advanced", "testkit", "reference", "internal")
TESTKIT_SIMPLE = frozenset(
    {
        "FixedClock",
        "InMemoryConfirmationTokenStore",
        "InMemoryDeduplicationStore",
        "InMemoryEventLog",
        "InMemoryEventStore",
        "InMemorySessionStore",
        "StaticTokenKeyProvider",
    }
)
STABLE_PHP_FACADES = frozenset(
    {
        "Canonicalizer",
        "ConfirmationService",
        "EventStore",
        "IdempotencyCoordinator",
        "ToolDescriptor",
        "ToolError",
        "ToolErrorCode",
    }
)
INTERNAL_PHP_SIMPLE = frozenset(
    {
        "StoredToolResultCodec",
    }
)
ADVANCED_PHP_OPTIONAL = frozenset(
    {
        "PdoPostgresConfirmationTokenStore",
        "PdoPostgresEventStore",
        "PdoPostgresIdempotencyStore",
    }
)

NS_RE = re.compile(r"^namespace\s+([^;]+);", re.MULTILINE)
PHP_TYPE_RE = re.compile(
    r"^(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)",
    re.MULTILINE,
)
EXPORT_RE = re.compile(
    r"""^export\s+['"]([^'"]+)['"]"""
    r"""(?:\s+show\s+([^;]+))?(?:\s+hide\s+([^;]+))?;""",
    re.MULTILINE,
)
DART_DECL_RE = re.compile(
    r"^(?:(?:abstract|base|final|sealed|mixin|interface)\s+)*"
    r"(?:class|mixin|enum|typedef|extension)\s+(\w+)",
    re.MULTILINE,
)
IDENT_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


class SurfaceError(ValueError):
    """Fail-closed inventory or contract defect."""


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def names_clause(raw: str | None) -> frozenset[str]:
    if not raw:
        return frozenset()
    names = []
    for part in raw.split(","):
        name = part.strip()
        if not name:
            continue
        if not IDENT_RE.fullmatch(name):
            raise SurfaceError(f"invalid show/hide identifier: {part!r}")
        names.append(name)
    return frozenset(names)


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def composer_psr4(composer: dict[str, Any], section: str) -> dict[str, str]:
    mapping = composer.get(section, {}).get("psr-4", {})
    if not isinstance(mapping, dict):
        raise SurfaceError(f"composer {section}.psr-4 must be an object")
    return {str(prefix): str(rel) for prefix, rel in mapping.items()}


def discover_php(repo: Path = REPO, *, production_only: bool = False) -> list[dict[str, Any]]:
    composer = load_json(repo / "php/composer.json")
    production = composer_psr4(composer, "autoload")
    maps = dict(production)
    if not production_only:
        maps.update(composer_psr4(composer, "autoload-dev"))
    entries: list[dict[str, Any]] = []
    seen: dict[str, Path] = {}
    php_root = repo / "php"
    for prefix, rel in sorted(maps.items()):
        if prefix.startswith("AgenTools\\Tests\\"):
            continue
        root = php_root / rel.rstrip("/")
        if not root.is_dir():
            continue
        for path in sorted(root.rglob("*.php")):
            text = path.read_text(encoding="utf-8")
            namespaces = NS_RE.findall(text)
            types = PHP_TYPE_RE.findall(text)
            if not types:
                continue
            if len(namespaces) != 1:
                raise SurfaceError(f"ambiguous PHP namespace in {path}")
            namespace = namespaces[0].strip()
            relative = path.relative_to(root).with_suffix("")
            file_simple = relative.name
            matching = [item for item in types if item[1] == file_simple]
            if len(matching) != 1:
                raise SurfaceError(f"PHP file {path} must declare exactly one PSR-4 type {file_simple}")
            for kind, simple in types:
                fqn = f"{namespace}\\{simple}"
                psr4_expected = prefix + str(relative).replace("/", "\\") if simple == file_simple else None
                if fqn in seen:
                    raise SurfaceError(f"PHP FQN collision {fqn}: {seen[fqn]} and {path}")
                seen[fqn] = path
                entries.append(
                    {
                        "ecosystem": "php",
                        "kind": kind,
                        "fqn": fqn,
                        "simple": simple,
                        "path": path.relative_to(repo).as_posix(),
                        "autoload": "production" if prefix in production else "dev",
                        "source_digest": sha256_file(path),
                        "namespace_prefix": prefix,
                        "psr4_expected": psr4_expected,
                        "psr4_mismatch": bool(psr4_expected and fqn != psr4_expected),
                    }
                )
    entries.sort(key=lambda row: row["fqn"])
    return entries


def dart_public_libraries(package_dir: Path) -> list[Path]:
    lib = package_dir / "lib"
    if not lib.is_dir():
        return []
    return sorted(path for path in lib.glob("*.dart") if path.is_file())


def resolve_dart_uri(current: Path, uri: str, package_dir: Path, package_name: str) -> Path:
    if uri.startswith("package:"):
        rest = uri[len("package:") :]
        name, _, tail = rest.partition("/")
        if name != package_name:
            raise SurfaceError(f"cross-package export is not inventory: {uri}")
        return package_dir / "lib" / tail
    target = (current.parent / uri).resolve()
    lib_root = (package_dir / "lib").resolve()
    if lib_root not in target.parents and target != lib_root:
        raise SurfaceError(f"export escapes package lib: {uri}")
    return target


def dart_declarations(text: str) -> list[str]:
    names = [match.group(1) for match in DART_DECL_RE.finditer(text)]
    return [name for name in names if not name.startswith("_")]


def discover_dart_package(repo: Path, rel: str) -> list[dict[str, Any]]:
    package_dir = repo / rel
    pubspec = (package_dir / "pubspec.yaml").read_text(encoding="utf-8")
    name_line = next((line for line in pubspec.splitlines() if line.startswith("name:")), "")
    package_name = name_line.split(":", 1)[1].strip()
    exported: dict[tuple[str, str], dict[str, Any]] = {}
    visiting: set[Path] = set()
    seen_files: set[Path] = set()

    def walk(library: Path, public: Path, show: frozenset[str], hide: frozenset[str]) -> None:
        if library in visiting:
            return
        visiting.add(library)
        seen_files.add(library)
        text = library.read_text(encoding="utf-8")
        local = dart_declarations(text)
        for name in local:
            if name in hide or (show and name not in show):
                continue
            key = (public.name, name)
            path = library.relative_to(repo).as_posix()
            existing = exported.get(key)
            if existing and existing["path"] != path:
                raise SurfaceError(f"Dart export collision {public.name}#{name}")
            exported[key] = {
                "ecosystem": "dart",
                "kind": "declaration",
                "package": package_name,
                "package_rel": rel,
                "library": public.name,
                "name": name,
                "path": path,
                "source_digest": sha256_file(library),
                "import": f"package:{package_name}/{public.name}",
            }
        for match in EXPORT_RE.finditer(text):
            uri, show_raw, hide_raw = match.groups()
            child_show = names_clause(show_raw)
            child_hide = names_clause(hide_raw)
            if show:
                child_show = child_show & show if child_show else show
            child_hide = child_hide | hide
            target = resolve_dart_uri(library, uri, package_dir, package_name)
            walk(target, public, child_show, child_hide)
        visiting.remove(library)

    for public in dart_public_libraries(package_dir):
        walk(public, public, frozenset(), frozenset())
    rows = sorted(exported.values(), key=lambda row: (row["import"], row["name"]))
    rows.append(
        {
            "ecosystem": "dart",
            "kind": "library-graph",
            "package": package_name,
            "package_rel": rel,
            "files_visited": sorted(path.relative_to(repo).as_posix() for path in seen_files),
        }
    )
    return rows


def discover_dart(repo: Path = REPO) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for rel in ("dart/agentools", "dart/agentools_mcp_dart"):
        rows.extend(discover_dart_package(repo, rel))
    return rows


def php_target_fqn(entry: dict[str, Any]) -> str:
    simple = entry["simple"]
    fqn = entry["fqn"]
    if fqn.startswith("AgenTools\\Reference\\"):
        return fqn
    if simple in TESTKIT_SIMPLE:
        return f"AgenTools\\TestKit\\{simple}"
    return fqn


def php_tier(entry: dict[str, Any]) -> str:
    fqn = entry["fqn"]
    simple = entry["simple"]
    if fqn.startswith("AgenTools\\Reference\\"):
        return "reference"
    if simple in TESTKIT_SIMPLE or fqn.startswith("AgenTools\\TestKit\\"):
        return "testkit"
    if simple in INTERNAL_PHP_SIMPLE:
        return "internal"
    if simple in ADVANCED_PHP_OPTIONAL:
        return "advanced"
    if fqn.startswith("AgenTools\\Contracts\\") or simple in STABLE_PHP_FACADES:
        return "stable"
    return "advanced"


def dart_tier(entry: dict[str, Any]) -> str:
    name = entry["name"]
    package = entry["package"]
    if package == "agentools_mcp_dart":
        return "stable" if name == "McpDartTransport" else "internal"
    if name in TESTKIT_SIMPLE:
        return "testkit"
    if name in {"SqlConfirmationTokenStore", "SqlEventStore", "SqlIdempotencyStore"}:
        return "advanced"
    if name in {"PackageSmokeMarker", "ConfirmationProtocol"}:
        return "advanced"
    return "advanced"


def dart_target_library(entry: dict[str, Any], tier: str) -> str:
    if entry["package"] == "agentools_mcp_dart":
        return "package:agentools_mcp_dart/agentools_mcp_dart.dart"
    if tier == "testkit":
        return "package:agentools/agentools_testkit.dart"
    return "package:agentools/agentools.dart"


def scan_php_mentions(repo: Path, roots: tuple[str, ...]) -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    use_re = re.compile(r"^use\s+(AgenTools\\[^;]+);", re.MULTILINE)
    for rel in roots:
        root = repo / rel
        if not root.exists():
            continue
        for path in sorted(root.rglob("*.php")):
            text = path.read_text(encoding="utf-8")
            posix = path.relative_to(repo).as_posix()
            for fqn in use_re.findall(text):
                found.setdefault(fqn.strip(), []).append(posix)
    return found


def scan_dart_mentions(repo: Path, roots: tuple[str, ...]) -> dict[str, list[str]]:
    found: dict[str, list[str]] = {}
    ident = re.compile(r"\b([A-Z][A-Za-z0-9]+)\b")
    for rel in roots:
        root = repo / rel
        if not root.exists():
            continue
        for path in sorted(root.rglob("*.dart")):
            text = path.read_text(encoding="utf-8")
            posix = path.relative_to(repo).as_posix()
            for name in set(ident.findall(text)):
                found.setdefault(name, []).append(posix)
    return found


def first_hit(hits: list[str] | None) -> str | None:
    return sorted(hits)[0] if hits else None


def classify_php(entries: list[dict[str, Any]], repo: Path) -> list[dict[str, Any]]:
    consumers = scan_php_mentions(repo, ("php/reference", "php/conformance"))
    tests = scan_php_mentions(repo, ("php/tests",))
    rows = []
    for entry in entries:
        tier = php_tier(entry)
        target_fqn = php_target_fqn(entry)
        consumer = first_hit(consumers.get(entry["fqn"]) or consumers.get(target_fqn))
        test = first_hit(tests.get(entry["fqn"]) or tests.get(target_fqn))
        if tier == "stable" and (consumer is None or test is None):
            tier = "advanced"
        archive_role = "excluded" if tier in {"testkit", "reference"} else "production"
        rows.append(
            {
                "id": f"php:{target_fqn}",
                "ecosystem": "php",
                "package": "agentools/sdk",
                "kind": entry["kind"],
                "tier": tier,
                "target_fqn": target_fqn,
                "legacy_fqns": [] if target_fqn == entry["fqn"] else [entry["fqn"]],
                "path": entry["path"],
                "autoload": "dev" if tier in {"testkit", "reference"} else "production",
                "archive_role": archive_role,
                "production_consumer": consumer,
                "behavioural_test": test,
                "source_digest": entry["source_digest"],
                "migration": "keep" if target_fqn == entry["fqn"] else "move",
                "psr4_mismatch": bool(entry.get("psr4_mismatch")),
            }
        )
    rows.sort(key=lambda row: row["id"])
    return rows


def classify_dart(entries: list[dict[str, Any]], repo: Path) -> list[dict[str, Any]]:
    symbols = [entry for entry in entries if entry.get("kind") == "declaration"]
    consumers = scan_dart_mentions(
        repo,
        ("dart/agentools_mcp_dart/lib", "dart/agentools/conformance"),
    )
    tests = scan_dart_mentions(repo, ("dart/agentools/test", "dart/agentools_mcp_dart/test"))
    rows = []
    for entry in symbols:
        tier = dart_tier(entry)
        library = dart_target_library(entry, tier)
        consumer = first_hit(consumers.get(entry["name"]))
        test = first_hit(tests.get(entry["name"]))
        if tier == "stable" and entry["package"] == "agentools" and (consumer is None or test is None):
            tier = "advanced"
            library = dart_target_library(entry, tier)
        identity = f"dart:{entry['package']}:{library.rsplit('/', 1)[-1]}#{entry['name']}"
        current_library = entry["import"]
        rows.append(
            {
                "id": identity,
                "ecosystem": "dart",
                "package": entry["package"],
                "kind": entry["kind"],
                "tier": tier,
                "target_library": library,
                "target_name": entry["name"],
                "legacy_libraries": [] if current_library == library else [current_library],
                "path": entry["path"],
                "production_consumer": consumer,
                "behavioural_test": test,
                "source_digest": entry["source_digest"],
                "migration": "keep" if current_library == library else "reexport",
            }
        )
    rows.sort(key=lambda row: row["id"])
    return rows


def current_php_index(entries: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    return {entry["fqn"]: entry for entry in entries}


def current_dart_index(entries: list[dict[str, Any]]) -> dict[tuple[str, str, str], dict[str, Any]]:
    index = {}
    for entry in entries:
        if entry.get("kind") != "declaration":
            continue
        index[(entry["package"], entry["library"], entry["name"])] = entry
    return index


def build_surface(repo: Path = REPO) -> dict[str, Any]:
    php = discover_php(repo)
    dart = discover_dart(repo)
    php_rows = classify_php(php, repo)
    dart_rows = classify_dart(dart, repo)
    entries = php_rows + dart_rows
    entries.sort(key=lambda row: row["id"])
    return {
        "schema_version": SCHEMA_VERSION,
        "contract": CONTRACT_PATH.relative_to(repo).as_posix(),
        "source_tree_digest": sha256_text(
            canonical_json(
                {
                    "php": [row["source_digest"] for row in php],
                    "dart": [row.get("source_digest") for row in dart if "source_digest" in row],
                }
            )
        ),
        "counts": {
            "php": len(php_rows),
            "dart": len(dart_rows),
            "by_tier": {
                tier: sum(1 for row in entries if row["tier"] == tier) for tier in TIERS
            },
        },
        "entries": entries,
    }


def match_php_observed(entry: dict[str, Any], observed: dict[str, dict[str, Any]]) -> str | None:
    if entry["target_fqn"] in observed:
        return entry["target_fqn"]
    for legacy in entry.get("legacy_fqns") or []:
        if legacy in observed:
            return legacy
    return None


def match_dart_observed(entry: dict[str, Any], observed: dict[tuple[str, str, str], dict[str, Any]]) -> bool:
    name = entry["target_name"]
    package = entry["package"]
    target_lib = entry["target_library"].rsplit("/", 1)[-1]
    if (package, target_lib, name) in observed:
        return True
    for legacy in entry.get("legacy_libraries") or []:
        lib = legacy.rsplit("/", 1)[-1]
        if (package, lib, name) in observed:
            return True
    return False


def dart_observed_ids(observed: dict[tuple[str, str, str], dict[str, Any]]) -> set[tuple[str, str]]:
    return {(package, name) for package, _lib, name in observed}


DART_SRC_IMPORT_RE = re.compile(r"""^import\s+['\"]package:agentools/src/""", re.MULTILINE)
TESTKIT_IMPORT_RE = re.compile(
    r"""^import\s+['\"]package:agentools/agentools_testkit\.dart['\"]""",
    re.MULTILINE,
)


def dart_forbidden_imports(repo: Path = REPO) -> list[str]:
    errors: list[str] = []
    companion = repo / "dart/agentools_mcp_dart"
    if companion.exists():
        for path in sorted(companion.rglob("*.dart")):
            if ".dart_tool" in path.parts:
                continue
            text = path.read_text(encoding="utf-8")
            if DART_SRC_IMPORT_RE.search(text):
                errors.append(f"forbidden src import: {path.relative_to(repo).as_posix()}")
    core_lib = repo / "dart/agentools/lib"
    if core_lib.exists():
        for path in sorted(core_lib.rglob("*.dart")):
            if path.name == "agentools_testkit.dart":
                continue
            text = path.read_text(encoding="utf-8")
            if TESTKIT_IMPORT_RE.search(text):
                errors.append(f"forbidden testkit import: {path.relative_to(repo).as_posix()}")
    return errors


def validate_surface(surface: dict[str, Any], repo: Path = REPO) -> list[str]:
    errors: list[str] = []
    if surface.get("schema_version") != SCHEMA_VERSION:
        errors.append("schema_version mismatch")
    entries = surface.get("entries")
    if not isinstance(entries, list) or not entries:
        return ["entries missing"]
    ids = [row["id"] for row in entries]
    if ids != sorted(ids) or len(ids) != len(set(ids)):
        errors.append("entry identities must be unique and sorted")
    php_obs = current_php_index(discover_php(repo))
    dart_obs = current_dart_index(discover_dart(repo))
    classified_php: set[str] = set()
    classified_dart: set[tuple[str, str]] = set()
    for row in entries:
        tier = row.get("tier")
        if tier not in TIERS:
            errors.append(f"{row.get('id')}: invalid tier")
            continue
        if tier == "stable" and (not row.get("production_consumer") or not row.get("behavioural_test")):
            errors.append(f"{row['id']}: stable-without-consumer")
        if row["ecosystem"] == "php":
            matched = match_php_observed(row, php_obs)
            if matched is None:
                errors.append(f"{row['id']}: missing target")
            else:
                classified_php.add(matched)
        elif row["ecosystem"] == "dart":
            if not match_dart_observed(row, dart_obs):
                errors.append(f"{row['id']}: missing target")
            else:
                classified_dart.add((row["package"], row["target_name"]))
    for fqn in php_obs:
        if fqn not in classified_php:
            errors.append(f"undeclared extra: php:{fqn}")
    for package, name in dart_observed_ids(dart_obs):
        if (package, name) not in classified_dart:
            errors.append(f"undeclared extra: dart:{package}#{name}")
    pubspec = (repo / "dart/agentools/pubspec.yaml").read_text(encoding="utf-8")
    if re.search(r"^\s+mcp_dart:", pubspec, re.MULTILINE):
        errors.append("forbidden tier edge: dart core depends on mcp_dart")
    errors.extend(dart_forbidden_imports(repo))
    return errors


def diff_actual(surface: dict[str, Any], repo: Path = REPO) -> dict[str, Any]:
    php_obs = current_php_index(discover_php(repo))
    dart_obs = current_dart_index(discover_dart(repo))
    pending = []
    missing = []
    extra = []
    matched_php: set[str] = set()
    matched_dart: set[tuple[str, str, str]] = set()
    for row in surface["entries"]:
        if row["ecosystem"] == "php":
            observed = match_php_observed(row, php_obs)
            if observed is None:
                missing.append(row["id"])
            else:
                matched_php.add(observed)
                if observed != row["target_fqn"]:
                    pending.append({"id": row["id"], "observed": observed, "target": row["target_fqn"]})
        else:
            name = row["target_name"]
            package = row["package"]
            target_lib = row["target_library"].rsplit("/", 1)[-1]
            if (package, target_lib, name) in dart_obs:
                matched_dart.add((package, target_lib, name))
                continue
            found = False
            for legacy in row.get("legacy_libraries") or []:
                lib = legacy.rsplit("/", 1)[-1]
                if (package, lib, name) in dart_obs:
                    matched_dart.add((package, lib, name))
                    pending.append({"id": row["id"], "observed": legacy, "target": row["target_library"]})
                    found = True
                    break
            if not found:
                missing.append(row["id"])
    for fqn in php_obs:
        if fqn not in matched_php:
            extra.append(f"php:{fqn}")
    for key in dart_obs:
        if key not in matched_dart:
            extra.append(f"dart:{key[0]}:{key[1]}#{key[2]}")
    return {
        "pending_moves": pending,
        "missing_targets": sorted(missing),
        "undeclared_extra": sorted(extra),
    }


def baseline_delta(repo: Path = REPO) -> dict[str, Any]:
    baseline = load_json(BASELINE_PATH)
    old_php = set(baseline["inventory"]["php_psr4_reachable_classes"])
    new_php = {row["fqn"] for row in discover_php(repo)}
    old_dart = {
        item
        for items in baseline["inventory"]["dart_barrel_exports"].values()
        for item in items
    }
    new_dart_rows = []
    for rel in ("dart/agentools", "dart/agentools_mcp_dart"):
        lib = repo / rel / "lib"
        for path in sorted(lib.glob("*.dart")):
            text = path.read_text(encoding="utf-8")
            for match in EXPORT_RE.finditer(text):
                new_dart_rows.append(f"{path.name}:{match.group(1)}")
    return {
        "php_baseline_only": sorted(old_php - new_php),
        "php_new": sorted(new_php - old_php),
        "dart_barrel_baseline_only": sorted(old_dart - set(new_dart_rows)),
        "dart_barrel_new": sorted(set(new_dart_rows) - old_dart),
        "notes": [
            "P1 baseline derived PHP identities from relative paths, including script files without types.",
            "P7.1 tokenizes class/interface/trait/enum declarations and fails closed on collisions.",
            "PHP/Dart additions after the P1 capture are P2–P6 hardening types, not P7 semantic changes.",
        ],
    }


def write_surface(path: Path, surface: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(canonical_json(surface) + "\n", encoding="utf-8")


def render_inventory_report(surface: dict[str, Any], delta: dict[str, Any], diff: dict[str, Any]) -> str:
    by_tier = surface["counts"]["by_tier"]
    lines = [
        "# P7 API inventory — current reachability to target roster",
        "",
        f"**Schema:** `{SCHEMA_VERSION}`  ",
        f"**PHP entries:** {surface['counts']['php']}  ",
        f"**Dart entries:** {surface['counts']['dart']}  ",
        f"**Source tree digest:** `{surface['source_tree_digest']}`",
        "",
        "## Tier counts",
        "",
        "| Tier | Count |",
        "|:--|--:|",
    ]
    for tier in TIERS:
        lines.append(f"| `{tier}` | {by_tier.get(tier, 0)} |")
    lines.extend(
        [
            "",
            "## P1 baseline digest delta",
            "",
            "P1 captured path-derived PHP names and one-level Dart barrel exports. P7.1 inventories",
            "tokenized PHP types and transitive Dart export closures. The sets below are explained,",
            "not ignored.",
            "",
            f"- PHP identities only in P1 path-map: **{len(delta['php_baseline_only'])}**",
            f"- PHP identities new since P1: **{len(delta['php_new'])}**",
            f"- Dart one-level barrel rows only in P1: **{len(delta['dart_barrel_baseline_only'])}**",
            f"- Dart one-level barrel rows new since P1: **{len(delta['dart_barrel_new'])}**",
            "",
        ]
    )
    for note in delta["notes"]:
        lines.append(f"- {note}")
    mismatches = [row["id"] for row in surface["entries"] if row.get("psr4_mismatch")]
    if mismatches:
        lines.extend(["", "### PSR-4 path/namespace case mismatches", ""])
        for item in mismatches:
            lines.append(f"- `{item}`")
    if delta["php_baseline_only"]:
        lines.extend(["", "### PHP path-map identities without tokenized types", ""])
        for item in delta["php_baseline_only"]:
            lines.append(f"- `{item}`")
    lines.extend(
        [
            "",
            "## Pending ownership moves",
            "",
            "These current identities remain reachable until P7.2/P7.3 rewrite autoload/exports.",
            f"**Pending:** {len(diff['pending_moves'])}  ",
            f"**Missing target:** {len(diff['missing_targets'])}  ",
            f"**Undeclared extra:** {len(diff['undeclared_extra'])}",
            "",
        ]
    )
    for row in diff["pending_moves"]:
        lines.append(f"- `{row['id']}`: `{row['observed']}` → `{row['target']}`")
    lines.extend(
        [
            "",
            "## Cross-tier rules",
            "",
            "- Production PHP autoload must not map `AgenTools\\Reference\\` or `AgenTools\\TestKit\\`.",
            "- `agentools.dart` must not export testkit implementations after P7.3.",
            "- Core must not depend on `mcp_dart` or Flutter.",
            "- `lib/src` remains technically importable; it is unsupported API.",
            "",
        ]
    )
    return "\n".join(lines) + "\n"


def cmd_inventory(args: argparse.Namespace) -> int:
    php = discover_php()
    dart = [row for row in discover_dart() if row.get("kind") == "declaration"]
    payload = {"php": php, "dart": dart}
    text = canonical_json(payload)
    if args.json:
        print(text)
    else:
        print(f"php={len(php)} dart={len(dart)} digest={sha256_text(text)}")
    return 0


def cmd_freeze(args: argparse.Namespace) -> int:
    surface = build_surface()
    output = Path(args.output) if args.output else SURFACE_PATH
    write_surface(output, surface)
    report = render_inventory_report(surface, baseline_delta(), diff_actual(surface))
    report_path = Path(args.report) if args.report else REPO / "verification/findings/P7-api-inventory.md"
    report_path.write_text(report, encoding="utf-8")
    print(f"froze {len(surface['entries'])} entries → {output}")
    return 0


def cmd_validate(args: argparse.Namespace) -> int:
    path = Path(args.contract) if args.contract else SURFACE_PATH
    errors = validate_surface(load_json(path))
    if errors:
        for error in errors:
            print(error, file=sys.stderr)
        return 1
    print("package-surface: OK")
    return 0


def cmd_diff(args: argparse.Namespace) -> int:
    path = Path(args.contract) if args.contract else SURFACE_PATH
    payload = diff_actual(load_json(path))
    print(canonical_json(payload))
    if args.strict and (payload["missing_targets"] or payload["undeclared_extra"]):
        return 1
    return 0


class PackageSurfaceTests(unittest.TestCase):
    def write(self, root: Path, rel: str, text: str) -> Path:
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
        return path

    def test_php_collision(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.write(
                root,
                "php/composer.json",
                canonical_json({"autoload": {"psr-4": {"AgenTools\\": "src/"}}}),
            )
            self.write(root, "php/src/A.php", "<?php\nnamespace AgenTools;\nfinal class A {}\n")
            self.write(root, "php/src/B.php", "<?php\nnamespace AgenTools;\nfinal class B {}\nfinal class A {}\n")
            with self.assertRaises(SurfaceError):
                discover_php(root)

    def test_php_path_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.write(
                root,
                "php/composer.json",
                canonical_json({"autoload": {"psr-4": {"AgenTools\\": "src/"}}}),
            )
            self.write(root, "php/src/A.php", "<?php\nnamespace AgenTools;\nfinal class Other {}\n")
            with self.assertRaises(SurfaceError):
                discover_php(root)

    def test_dart_show_hide_and_cycle(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            pkg = root / "dart/agentools"
            self.write(pkg, "pubspec.yaml", "name: agentools\n")
            self.write(
                pkg,
                "lib/agentools.dart",
                "export 'src/a.dart' show Visible;\nexport 'src/cycle_a.dart';\n",
            )
            self.write(pkg, "lib/src/a.dart", "class Visible {}\nclass Hidden {}\n")
            self.write(pkg, "lib/src/cycle_a.dart", "export 'cycle_b.dart';\nclass CycleA {}\n")
            self.write(pkg, "lib/src/cycle_b.dart", "export 'cycle_a.dart';\nclass CycleB {}\n")
            rows = [row for row in discover_dart_package(root, "dart/agentools") if row.get("kind") == "declaration"]
            names = {row["name"] for row in rows}
            self.assertEqual(names, {"Visible", "CycleA", "CycleB"})

    def test_undeclared_extra_and_missing_target(self) -> None:
        surface = {
            "schema_version": SCHEMA_VERSION,
            "entries": [
                {
                    "id": "php:AgenTools\\Ghost",
                    "ecosystem": "php",
                    "tier": "advanced",
                    "target_fqn": "AgenTools\\Ghost",
                    "legacy_fqns": [],
                    "production_consumer": None,
                    "behavioural_test": None,
                }
            ],
        }
        errors = validate_surface(surface, REPO)
        self.assertTrue(any(error.startswith("undeclared extra:") for error in errors))
        self.assertTrue(any("missing target" in error for error in errors))

    def test_stable_without_consumer(self) -> None:
        row = {
            "id": "php:AgenTools\\Contracts\\Clock",
            "ecosystem": "php",
            "tier": "stable",
            "target_fqn": "AgenTools\\Contracts\\Clock",
            "legacy_fqns": [],
            "production_consumer": None,
            "behavioural_test": "php/tests/x.php",
        }
        errors = validate_surface({"schema_version": SCHEMA_VERSION, "entries": [row]}, REPO)
        self.assertTrue(any("stable-without-consumer" in error for error in errors))

    def test_forbidden_core_mcp_edge(self) -> None:
        pubspec = (REPO / "dart/agentools/pubspec.yaml").read_text(encoding="utf-8")
        self.assertIsNone(re.search(r"^\s+mcp_dart:", pubspec, re.MULTILINE))

    def test_forbidden_companion_src_import(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            self.write(
                root,
                "dart/agentools_mcp_dart/lib/bad.dart",
                "import 'package:agentools/src/contracts/mcp_transport.dart';\n",
            )
            errors = dart_forbidden_imports(root)
            self.assertTrue(any("forbidden src import" in error for error in errors))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    inventory = sub.add_parser("inventory")
    inventory.add_argument("--json", action="store_true")
    freeze = sub.add_parser("freeze")
    freeze.add_argument("--output")
    freeze.add_argument("--report")
    validate = sub.add_parser("validate-contract")
    validate.add_argument("--contract")
    diff = sub.add_parser("diff")
    diff.add_argument("--actual", action="store_true")
    diff.add_argument("--contract")
    diff.add_argument("--strict", action="store_true")
    sub.add_parser("test")
    args = parser.parse_args(argv)
    if args.command == "inventory":
        return cmd_inventory(args)
    if args.command == "freeze":
        return cmd_freeze(args)
    if args.command == "validate-contract":
        return cmd_validate(args)
    if args.command == "diff":
        if not args.actual:
            print("diff requires --actual", file=sys.stderr)
            return 2
        return cmd_diff(args)
    return 0 if unittest.main(argv=["package_surface", "-q"], exit=False).result.wasSuccessful() else 1


if __name__ == "__main__":
    raise SystemExit(main())
