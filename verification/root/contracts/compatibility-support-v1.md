# Compatibility and support policy v1 — AgenTools hardening (PLAN1)

**Version:** v1  
**Status:** draft contract (P1.3)  
**Evidence date:** 2026-09-20  
**Baseline anchor:** `artifacts/P1-baseline.json` @ commit `8ce43f47735d827be3872bcb8c01d09dd38ed22d`

Support is a tuple **(artifact version, spec revision, platform/dependency range, evidence date)** —
never a bare version list. No migration writes occur in P1.

## 1. Identity and publication posture

| Dimension | declared | observed | tested | release-supported | unsupported |
|:--|:--|:--|:--|:--|:--|
| Package identity | `agentools/sdk` (PHP), `agentools`, `agentools_mcp_dart` (Dart) | Private/proprietary manifests (`publish_to: none`, proprietary license) | Baseline capture 2026-09-20 | — | Public Packagist/pub.dev until owner decision (D5) |
| Spec revision | `2026-08` frozen | Lockstep anchor in SDK manifests | CI layers 1–2 green in baseline | — | Ad-hoc spec edits without new dated revision |
| MCP protocol pin | `2026-07-28` (vendor PIN) | Distinct from Agentools spec `2026-08` | Not official MCP conformance | — | Claiming upstream MCP compliance without P6 receipts |

## 2. Platform and dependency matrix

| Dimension | declared | observed | tested | release-supported | unsupported |
|:--|:--|:--|:--|:--|:--|
| PHP runtime | `>=8.3` in `php/composer.json` | Local capture used PHP 8.x toolchain | `php.test`, `php.analyse` green in baseline | — | PHP `<8.3`; PHP 8.2 EOL per php.net table (RAG P1-design) |
| PHP CI workflow | — | `.github/workflows/ci.yml` uses PHP 8.4 | Not part of baseline worktree probes | — | Treating CI matrix as release-supported without P9 lane |
| Dart SDK | `^3.11.0` both packages | Locks resolved in baseline | Core/MCP tests green; core `dart analyze` red in baseline | — | SDK below declared floor |
| Conformance runner | Four `ci_gate` layers | Layers 1–2 green; 3–4 red in baseline | Partial | — | Full conformance lockstep as release gate until green receipt |
| Composer lowest/highest | — | Not executed in P1 | Future P9 disposable copies | — | — |
| Dart compatible-lowest graph | — | Not executed in P1 | Future `dart pub downgrade` lane (P9) | — | Independent per-package lower bounds without graph proof |

## 3. API and consumer surface policy

- PHP PSR-4 reachability (**133** classes in baseline) and Dart barrel exports are **candidate surface only** until P7 declares stable public API with production consumer and behavioural proof.
- Pre-1.0 (`0.0.1`) changes may break candidate surface when documented in the migration inventory and SemVer plan; **1.0 stability** begins only after P7 closure and owner release gate (D4).
- In-memory implementations packaged beside production contracts remain candidates, not supported extension points.

## 4. Change and deprecation policy (pre-1.0)

| Change class | Policy | Notification trigger |
|:--|:--|:--|
| Spec breaking | New dated revision; SDK manifests updated in lockstep (P6) | Revision freeze announcement + migration row |
| Token/envelope | Versioned fields; no silent wire format change (P3) | Conformance vector bump |
| Storage/schema | Explicit migration class; no silent DDL (P5) | Migration inventory + consumer note |
| Public API | SemVer after P7 manifest | Changelog + major plan for breaking stable entries |
| Dependency major bumps | P9 matrix + consumer smoke | CI red on unsupported tuple |

## 5. Compatibility test ownership

| Owner | Responsibility |
|:--|:--|
| P6 | Cross-language error/JCS parity and conformance scope |
| P7 | Public API manifest and archive boundary |
| P8 | Adversarial and resource envelopes on supported tuple |
| P9 | CI matrices, downgrade/lowest graphs, supply chain |
| P10 | Built-archive consumers and release dossier |

## 6. Prohibited claims

The following unqualified claims are forbidden in user-facing docs and release metadata until the
named phase produces proof:

- “MCP compliant” (use P6 applicability matrix instead)
- “all versions” / “backward compatible” without tuple scope
- “stable API” for candidate PHP/Dart reachability
- `release-supported` without a named green CI or consumer carrier

Public identity remains **private/proprietary** in this contract revision.
