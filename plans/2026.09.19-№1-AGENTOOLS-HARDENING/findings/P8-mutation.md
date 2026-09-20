# P8.3 mutation gates

**Date:** 2026-09-20  
**Catalog:** `fixtures/hardening/adversarial/mutations/catalog.v1.json`  
**Runner:** `python3 tools/mutation_gate.py`  
**Receipt:** `artifacts/P8-mutation-baseline.json`  
**Infection:** `infection/infection` `0.35.4` (`php/infection.json5`)

The release-bearing layer is the digest-bound semantic catalog, not a percentage.
PHP Infection is an AST signal with calibrated floors. Dart has no comparable
AST mutator; regex scores are not a gate.

## Critical catalog

`python3 tools/mutation_gate.py run` killed **26/26** named PHP/Dart patches
(workspace digest restored after each patch). Self-tests: stale anchor, ambiguous
patch, and a deliberately weakened `BaggageRefs` assertion (`testItAcceptsOpaqueIdentifiers`)
all stay RED rather than “killed”.

| ID | Fault |
|:--|:--|
| MUT-CNF-AUTH-BYPASS | authorization inverted |
| MUT-CNF-CLOCK-FORGE | expiry/skew skipped |
| MUT-TKN-SKIP-VERIFY | HMAC compare skipped |
| MUT-TKN-SKIP-CONSUME | durable consume omitted |
| MUT-IDC-STALE-FENCE | stale lease token accepted |
| MUT-IDC-UNKNOWN-ALLOW | unknown high-risk capability allowed |
| MUT-EVT-SKIP-COLLISION | event_id collision overwrite |
| MUT-EVT-SKIP-VERSION | expected-version skipped |
| MUT-JCS-SKIP-SORT | UTF-16 member sort skipped |
| MUT-SCN-RELAX-DEPTH | expression depth cap removed |
| MUT-OPS-RELAX-BAGGAGE | baggage item cap raised |
| MUT-OPS-SKIP-REDACTION | audit `assertPayload` skipped |
| MUT-PKG-EXPORT-TESTKIT | testkit exported as production |

`MUT-OPS-SKIP-REDACTION` deepens audit redaction beyond the P8.1 AV-009 baggage
row; ordinary `AuditEventFactory` tests now reject a `token` payload field.

## Infection (PHP only)

Measured with `/usr/bin/php8.4 -d xdebug.mode=off` and PCOV 1.0.12. Herd-lite
PHP 8.4.1 cannot load PCOV (`Dynamic loading not supported`) and is not a
coverage driver for this gate. `timeoutsAsEscaped=true`. Combined campaign
mutates named non-generated namespaces only.

| Scope | Mutants | Killed | Escaped | Timeout | Error | Syntax | Covered MSI |
|:--|--:|--:|--:|--:|--:|--:|--:|
| Confirmation | 687 | 488 | 197 | 2 | 0 | 0 | 71.03 |
| Schema | 265 | 209 | 51 | 3 | 2 | 0 | 79.62 |
| Ops | 240 | 167 | 73 | 0 | 0 | 0 | 69.58 |
| Scenario | 355 | 271 | 83 | 1 | 0 | 0 | 76.34 |
| Idempotency | 279 | 241 | 35 | 0 | 0 | 3 | 87.46 |
| **combined** | **1826** | **1377** | **438** | **6** | **2** | **3** | **75.68** |

Floors in `php/infection.json5`: `minMsi=70`, `minCoveredMsi=70` (combined
measured 75.68, 5.68pp headroom). Per-scope Ops 69% is constructor/logical
equivalent noise and is **not** the committed gate. Floors must not be lowered
without a new evidence delta.

### Reviewed non-kills

- **Timeouts (6):** inverted `skipWs` / JSON offset `--$this->offset` /
  `readJsonString` removal / expression `throw` removal — parser loops, counted
  escaped, not catalog kills.
- **Errors (2):** `Increment` on Canonicalizer/JSON offset — runtime faults,
  not kills.
- **Syntax (3):** `NotIdentical`/`Identical` on `IdempotencyAcquisition` /
  result XOR-style guards — compile-invalid, not kills.
- **Equivalent/noise (escaped):** constructor `||`/`&&` De Morgan, `clone`
  removal, retryable `false→true`, `> vs >=` on unused exact byte bound,
  `json_decode` depth 511/513.
- **Exclusion:** `src/Ops/LongOperationCoordinator.php` — Infection 0.35.4
  crashes on `enum` in the same file as `class` (`Expected Class_, Got Enum_`).
  Compensating proof: catalog + ordinary coordinator tests; not a silent
  protocol-directory blanket.

Generic Dart `mutation_test` percentage is not recorded as a gate.
