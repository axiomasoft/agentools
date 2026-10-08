# Hardening guarantees v1 — AgenTools (PLAN1)

**Version:** v1  
**Status:** draft contract (P1.4)  
**Evidence date:** 2026-09-20  
**Baseline anchor:** `artifacts/P1-baseline.json` @ commit `8ce43f47735d827be3872bcb8c01d09dd38ed22d`  
**Inputs:** `threat-model-v1.md` · `compatibility-support-v1.md` · `findings/P1-migration-inventory.md`

Status enum: `baseline-red` | `baseline-partial` | `designed` | `proved` (only immutable executable evidence).

## 1. Guarantee catalog

Observable postconditions and refusal behaviour only; no unqualified “secure”, “exactly-once”,
“production-ready”, or “MCP compliant”.

| ID | Statement | Trust assumptions | Failure boundary | Status | Owner | Falsifying scenario | Threat | Migration |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| G-CNF-001 | Commit path refuses when authorizer denies or proposal/binding tuple does not match the authoritative confirmation record | Partially trusted caller; trusted authorizer seam (P2) | Untrusted caller supplies mismatched principal, descriptor digest, args fingerprint or permissive authorizer | baseline-red | P2 | Approve with wrong digest still commits | TM-001, TM-003 | M-001, M-003 |
| G-CNF-002 | TTL and skew checks use an injected trusted clock when enabled; expired tokens are refused | Trusted clock provider configured by integrator | Caller-supplied `now` without trusted clock | baseline-red | P2 | Commit succeeds after forged `now` | TM-001 | M-001 |
| G-TKN-001 | Parsed confirmation token signature and required payload fields are verified before spend | TokenSigner key material trusted to runtime | Malformed, truncated or wrong-signature token bytes | baseline-partial | P3 | Accept token with invalid signature | TM-006 | M-006 |
| G-TKN-002 | A spent confirmation token is refused on second spend within the same process instance | Single process; in-memory spent map | Second process or restart after spend | baseline-partial | P3 | Replay same token in new PHP-FPM worker | TM-002 | M-002 |
| G-TKN-003 | Cross-process replay is refused via durable atomic consume keyed by tenant/purpose/jti | Durable store with correct schema; partially trusted network | Process-local store only | baseline-red | P3 | Concurrent workers both spend | TM-002 | M-002 |
| G-IDC-001 | Duplicate idempotency key returns the stored in-flight or completed outcome without re-invoking the effect port | Store scope matches worker deployment | Crash between resolve and complete | baseline-partial | P4 | Two workers both invoke external effect | TM-004 | M-004 |
| G-IDC-002 | External effect runs only while holder owns a valid lease and fence token | Durable lease store; fencing before invoke | Stale worker after takeover | baseline-red | P4 | Zombie worker invokes after lease loss | TM-004 | M-004 |
| G-EVT-001 | Re-appending the same `event_id` is a no-op within a store implementation | Single-writer or store-enforced idempotency | Concurrent writers without storage CAS | baseline-partial | P5 | Two different events share seq | TM-005 | M-005 |
| G-EVT-002 | Batch append with expected-version and content-hash collision detection is atomic at storage | Durable EventStore adapter | In-memory log without collision contract | baseline-red | P5 | Torn batch under concurrency | TM-005 | M-005 |
| G-SCN-001 | Expression and scenario limits reject overflow with structured budget/validation errors | Untrusted scenario JSON | Depth/length/collection/step overflow | baseline-partial | P8 | Scenario with 65 steps proceeds | TM-010 | M-010 |
| G-OPS-001 | Baggage carries only opaque identifiers within configured item and encoded-byte caps | Untrusted tool arguments | Opaque id regex bypass or oversized encoded list | baseline-partial | P8 | 65 baggage refs accepted | TM-010 | M-010 |
| G-SEM-001 | Registered cross-language fixtures produce matching semantic outcomes and error codes | Frozen spec revision `2026-08` | Divergent serializers | baseline-partial | P6 | PHP/Dart disagree on JCS bytes | TM-007 | M-007 |
| G-JCS-001 | Canonical JSON serialization matches the independent JCS oracle corpus | Oracle pin D11 | Hand-waved “JSON equal” | baseline-red | P6 | Number row mismatch vs RFC 8785 corpus | TM-007 | M-007 |
| G-SCP-001 | Agentools conformance receipts are scoped; official MCP claims require applicable upstream scenarios | Operator reads applicability matrix | Internal green treated as MCP compliance | baseline-red | P6 | Docs claim “MCP compliant” | TM-008 | M-008 |
| G-PKG-001 | Stable public API entries match the P7 manifest and archive boundary on supported tuples | Intentional export classification | Accidental PSR-4/barrel reachability | baseline-red | P7 | Consumer depends on testkit class | TM-009 | M-009 |
| G-ADV-001 | Each `proved` security/durability row has a matching adversarial or crash proof in the P8 roster | Calibrated resource envelope | Unit tests only | baseline-red | P8 | Mutation survives lease gate | TM-010 | M-010 |
| G-CI-001 | Private release candidate is assembled only on the trusted CI plane with pinned tools | GitHub/repo capabilities per D14 | Fork PR with secrets | baseline-red | P9 | Untrusted workflow publishes RC | TM-011 | M-011 |
| G-REL-001 | Release acceptance reconciles every public claim to an immutable receipt digest | Owner gate for public scope | Source-only smoke | baseline-red | P10 | Packagist upload without dossier | TM-012 | M-012 |
| G-OBS-001 | Bookkeeping projections do not weaken TM-001–TM-005 mitigations | Operators treat observability separately | Epoch sentinel misread as security | baseline-partial | P5 | 1970 timestamp hides staleness | TM-013 | M-013 |

## 2. Non-guarantee catalog

| ID | Explicit non-promise | Rationale | Owner | Threat / finding |
|:--|:--|:--|:--|:--|
| NG-EXT-001 | Exactly-once execution of external side effects | Effects are at-most-once with admission, replay, fencing and downstream cooperation | P4 | TM-004 |
| NG-EXT-002 | Unconditional downstream idempotency without named capability (`native_key`, probe, saga) | Package cannot force third-party systems | P4 | TM-004 |
| NG-AVL-001 | Service availability under DoS or resource exhaustion | Limits and benchmarks are envelope-bound, not HA | P8 | TM-010 |
| NG-TRU-001 | Security when partially trusted integrator or operator is fully compromised | Trust zones assume bounded malice | P2 | TM-001 |
| NG-MEM-001 | Durability across restart for in-memory confirmation, idempotency or event adapters | Documented testkit contour only | P7 | TM-002, TM-004, TM-005 |
| NG-VER-001 | Safe interpretation of unknown cross-version wire or storage blobs | Requires explicit migration class | P6 | M-007 |
| NG-MCP-001 | Official MCP protocol compliance without applicable upstream receipts | Agentools scope ≠ upstream suite | P6 | TM-008 |
| NG-DES-001 | Literal adoption of unaudited SQL/interface sketches | F14 sketches are not normative | phase design | TM-014, F14 |
| NG-SUP-001 | `release-supported` platform or registry lane without named green carrier | Compatibility tuple policy (P1.3); no lane claimed in baseline | P9 | M-014–M-017 |

## 3. Phase ownership summary

| Phase | Guarantees / role |
|:--|:--|
| P2 | G-CNF-001, G-CNF-002 |
| P3 | G-TKN-001–G-TKN-003 |
| P4 | G-IDC-001, G-IDC-002 |
| P5 | G-EVT-001, G-EVT-002, G-OBS-001 |
| P6 | G-SEM-001, G-JCS-001, G-SCP-001 |
| P7 | G-PKG-001; NG-MEM-001 packaging contour |
| P8 | G-SCN-001, G-OPS-001, G-ADV-001; NG-AVL-001 calibration |
| P9 | G-CI-001, NG-SUP-001 (support matrix; no baseline release lane) |
| P10 | G-REL-001 |
| P1 | acceptance-only for baseline capture (no owned G-* / NG-* rows) |

## 4. Threat coverage

Every open threat TM-001–TM-012 maps to at least one guarantee row or explicit non-guarantee above.
TM-013 → G-OBS-001; TM-014 → NG-DES-001.
