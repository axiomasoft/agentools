# Package-boundary policy v1 — AgenTools (PLAN1)

**Version:** v1  
**Status:** P7 executable contract  
**Evidence date:** 2026-09-20  
**Machine roster:** `api/package-surface-v1.json`  
**Decisions:** D12  
**Inventory tool:** `tools/package_surface.py`

Reachability is candidate inventory, not a stability promise. `RAG:—` for repository
classification. Composer `autoload-dev` / `archive.exclude` and Dart public-library /
`lib/src` conventions constrain packaging, not which symbols are stable. `RAG:✅`
primary sources in `research/P7-design-rag.md`.

## 1. Surfaces

Exactly three package surfaces:

| Surface | Identity | Supported entry | Development contour |
|:--|:--|:--|:--|
| PHP core | `agentools/sdk` | production PSR-4 `AgenTools\\` → `src/` | `AgenTools\\TestKit\\`, `AgenTools\\Reference\\` via root-only `autoload-dev` |
| Dart core | `agentools` | `package:agentools/agentools.dart` | `package:agentools/agentools_testkit.dart` |
| Dart MCP companion | `agentools_mcp_dart` | `package:agentools_mcp_dart/agentools_mcp_dart.dart` | none; optional adapter package |

No fourth production package is created. Physical splits remain a future measured decision.

## 2. Tiers

Each current reachable identity has exactly one target tier:

| Tier | Meaning | Archive / autoload |
|:--|:--|:--|
| `stable` | Intentional export/autoload, one production consumer, one behavioural test | production |
| `advanced` | Public candidate; may change pre-1.0 under P1 compatibility | production |
| `testkit` | Process-local in-memory/fixed doubles | PHP `autoload-dev`, Dart testkit library, archive-excluded for PHP |
| `reference` | Product-slice / example contour | PHP `autoload-dev` + archive-excluded |
| `internal` | Implementation detail; not a consumer contract | may ship in `src` / `lib/src`; unsupported to import |

`stable` requires all three proofs. Missing consumer or test downgrades the entry to
`advanced`. Visibility never upgrades a tier.

## 3. Identity

- PHP identity is the PSR-4 FQN of a tokenized `class` / `interface` / `trait` / `enum`.
  Path-derived names without a type are not API. Duplicate FQNs or file/FQN mismatch fail closed.
- Dart identity is `{package}:{public-library}#{declaration}` from the transitive export
  graph, including `show` / `hide` and export cycles. Direct `lib/src` imports are not
  supported API.
- `api/package-surface-v1.json` is the only exact target list. Markdown must not duplicate it.

## 4. Dependency rules

Forbidden edges:

- PHP `src` → `AgenTools\\TestKit\\` or `AgenTools\\Reference\\`
- Dart `package:agentools/agentools.dart` → testkit implementations
- Dart core → `mcp_dart` or Flutter
- MCP companion → core `lib/src` (must use supported libraries)

Optional PostgreSQL adapters stay `advanced` in core. They are not a second package.

## 5. Migration

A proved `stable` removal needs a compatibility shim/deprecation window or a new finite
owner-gate. Candidate accidental names may move to testkit/reference/internal when P1
compatibility permits it and the roster records `legacy_fqns` / `legacy_libraries`.

P7 changes packaging/ownership only. P2–P6 semantics and `spec/revisions/2026-08`
remain frozen. Public registry, hosted dependency and G-RELEASE stay P9/P10/D4/D5.

## 6. Gate

`tools/package_surface.py validate-contract` is red when:

- a reachable current identity has no roster row
- a roster row has no observed current or legacy identity
- identities collide or are unsorted
- a `stable` row lacks production consumer or behavioural test
- Dart core depends on `mcp_dart`

`diff --actual` reports pending moves without making P7.1 red. After P7.2/P7.3,
`--strict` fails on remaining missing/extra identities.
