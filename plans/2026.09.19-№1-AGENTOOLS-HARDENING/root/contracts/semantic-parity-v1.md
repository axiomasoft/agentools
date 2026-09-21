# Semantic parity contract v1 — AgenTools (PLAN1)

**Version:** v1  
**Status:** P6 executable contract  
**Successor revision:** `2026-09` (candidate)  
**Manifest owner:** `tools/semantic_manifest.py` → `spec/revisions/2026-09/semantic-manifest.json`

## Ownership

| Carrier | Owns |
|:--|:--|
| `09_idempotency-errors.md` IDE-31 | code ↔ producer ↔ category |
| `schema/error.json` | closed code enumeration |
| `fixtures/hardening/*-v1.json` | case-level retryability and semantic codes |
| `12_hardening-semantics.md` | P2–P5 normative delta (`HRD-*`) |
| Generated manifest | digest-qualified `semantic-profile/v1` projection |
| PHP/Dart profile exporters | actual SDK constants (no codegen) |

## Exact-set gate (P6.2)

Comparator MUST treat `missing`, `extra`, and field-level `mismatch` on
`code`, `category`, and `default_retryable` as hard failures for both directions
(normative↔PHP, normative↔Dart, PHP↔Dart). Human titles and localization are out of scope.

## JCS gate (P6.3)

Canonical bytes MUST match independent oracle under `conformance/oracles/jcs/` (pinned
upstream provenance). Neither SDK may regenerate expected bytes.

## Compatibility

Implementations declaring only `2026-08` are not required to implement `HRD-*` or the
extended IDE-31 table. Implementations declaring `2026-09` MUST implement both.
