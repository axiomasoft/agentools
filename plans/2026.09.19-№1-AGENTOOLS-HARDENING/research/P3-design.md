# P3 design — durable presentation consume, bounded token envelope, key lifecycle

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P3-design-rag.md`.

## Design question

How can Agentools make one-shot confirmation durable across process restart and concurrent
workers, without turning the token into JWT, inventing KMS, claiming exactly-once tool effects,
or taking P2 authorization / P5 event-store / P6 spec-revision ownership?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| PHP `$spentTokens` and Dart `_spentTokens` are instance memory keyed by raw token bytes; a new process or worker has an empty set. | both `ConfirmationProtocol` implementations; F02 | Supported consume must be a durable unique claim, not a private array. |
| Spend is intentionally after args/expiry/principal/descriptor checks and before current authorization. | protocol comments; CNF-20 | Durable insert happens at presentation, not only on authorization success. |
| Signed payload covers `action_id`, `args_fingerprint`, `descriptor_digest`, `expires_at`, `principal`, `proposal_id`, `proposal_version`, `tool_name`. No `v`/`alg`/`kid`/`iss`/`aud`/`jti`/`tenant`/`purpose`/`nbf`/`iat`. | PHP/Dart `ConfirmationToken` | P3 owns the new envelope; P2 binding tuple stays the semantic comparison. |
| Wire is two base64url segments (`payload.sig`) with HMAC-SHA256 over a single constructor secret. `TenantHmacTokenSigner` still exposes the same `TokenSigner` and does not select keys by kid/purpose. | `HmacTokenSigner`, `TokenSigner`, reference tenant signer | Replace the signer with a key provider; keep HS256 as the v1 allowlist, not algorithm agility. |
| `json_encode` / `jsonDecode` parse the payload. Neither SDK rejects duplicate object names or bounds token bytes before decode. | `ConfirmationToken::parse` | Parser profile is fail-closed: size, depth, closed claim set, duplicate names. |
| Frozen `2026-08` keeps the token opaque to callers (CNF-14) and extinguishes on presentation including counterparty refusal (CNF-16, CNF-20). Algorithm is explicitly a runtime property. | `spec/revisions/2026-08/04_confirmation.md` | Envelope/key/store are SDK hardening; spec bytes stay unchanged until P6. |
| Composer/pubspec have no PostgreSQL driver. Persistence ports today are in-memory session/idempotency stores. | `php/composer.json`, `dart/agentools/pubspec.yaml` | Production adapter takes injected PDO / SQL executor; no new published library dependency. |
| Audit SQL/interfaces are sketches and F14 forbids treating them as the schema. | `audits/2026-09-19-audit.md`; `findings/audit-verdicts.md` F06/F14 | P3.1 freezes the real contract; audit table is a starting hypothesis only. |

## Accepted boundary

P3 introduces one versioned token-replay contract consumed by both SDKs:

1. **Envelope.** Compact custom format `at1.` + base64url(canonical JSON) + `.` + base64url(HMAC).
   Version lives in the prefix and in the signed `v` claim. There is no unsigned JOSE header, so
   `alg` cannot be swapped by rewriting an unprotected header. JWT is not a requirement (F06).
2. **Parser.** Reject oversize input before decode; reject extra segments; reject duplicate JSON
   names; reject unknown/missing required claims; reject nested objects/arrays; pin `alg` to the
   key's single algorithm.
3. **Keys.** `TokenKeyProvider` returns tenant+purpose scoped material: `active` signs and
   verifies, `verify_only` verifies, `retired`/`compromised` reject. Physical delete only after
   max token lifetime + skew + retention.
4. **Consume.** After integrity, time and P2 binding checks succeed, atomically `INSERT ... ON
   CONFLICT (tenant_id, token_id) DO NOTHING RETURNING token_id` with `token_id = jti`. Empty
   RETURNING is CNF-20. Outcome starts as `presented` and may later be refined; refinement must
   not unspend. Pre-presentation failures do not write the store.
5. **Cleanup.** Delete only where `expires_at + retention < now`. Uniqueness is permanent while
   the row exists. Partial unique indexes on “unexpired” rows are forbidden.

The composition root still supplies clock, issuer/audience, key material and the SQL session.
Safe-by-construction here means an ordinary per-call integrator cannot skip consume or choose
`alg`/`kid`/replay storage; it does not mean a malicious trusted root is harmless.

### Consume ordering

```text
1. Bound raw token size.
2. Parse at1 envelope; reject duplicate names and closed-set violations.
3. Resolve verification key by (tenant, purpose, kid); pin alg.
4. Verify signature over the exact signing input.
5. Check iss/aud/purpose/tenant, nbf/exp with injected clock and bounded skew.
6. Compare P2 binding tuple to the authoritative confirmation record.
7. INSERT consume row (outcome=presented). Empty RETURNING → spent.
8. Evaluate current authorization/policy (P2). Refine outcome. Never unspend.
9. Return authorized invocation or structured denial.
```

P5 may later append audit events; P3 does not create a second event journal.

### v1 numeric bounds

These are P3-owned until P1.5 execution records them as `RL-*` rows. They are not P8 timing SLOs.

| Bound | Value | Rule |
|:--|:--|:--|
| Max raw token bytes | 8192 | Counted before base64 decode. |
| Envelope version | `at1` / `v=1` | Unknown version denies. |
| Algorithm allowlist | `HS256` | HMAC-SHA256; key length ≥ 32 bytes; `none` forbidden. |
| Clock skew | default 60s, hard max 300s | Applied to `nbf`/`exp` only. |
| Max `exp - iat` | 72h default, hard max 7d | Prepare rejects a longer proposal window. |
| Retention after `expires_at` | 24h default, hard min = skew | Cleanup must not open a replay window. |
| `jti` entropy | ≥ 128 bits CSPRNG | ULID/UUID acceptable if CSPRNG-backed. |
| Payload | flat object, ≤ 24 members | Extra/nested members deny. |

## Alternatives

### A. Keep process-local set and document “single worker only”

Rejected. PHP-FPM, Dart isolates and any restart falsify CNF-20. F02 is already confirmed.

### B. Adopt JWT/JWS as the wire format

Rejected as a requirement. RFC 8725 controls (algorithm pin, iss/aud, kid hygiene) apply as
security properties; they do not force JOSE headers. A JWT header would reintroduce `alg`
confusion unless we re-implement the same pin. Custom `at1` envelope with signed `alg`/`kid`
gives the controls without an OAuth/JWT product claim.

### C. Consume only on authorization success

Rejected. CNF-20 already extinguishes on counterparty refusal. Success-only consume would let a
denied principal retry the same token after a grant — a replay window the current code closed.

### D. `ON CONFLICT DO UPDATE` no-op so RETURNING always comes back

Rejected. Both callers would look like winners. `DO NOTHING` plus empty RETURNING distinguishes
the first writer. Outcome refinement is a later UPDATE of the existing row, never an upsert that
revives the token.

### E. Merge replay rows into the P5 event store

Rejected. Event ordering/collision/pagination are a different contract. A consume table with a
unique `(tenant_id, jti)` arbiter can be proved with two concurrent INSERTs; it must not wait
on event-batch design.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P3.1 | token-replay contract, migration, adversarial cases | P1 contracts, P2.1 tuple | One normative envelope/consume/key owner before code. |
| P3.2 | PHP/Dart codec and key provider | P3.1 | Parser/key vectors do not need PostgreSQL. |
| P3.3 | store port, PDO/SQL adapter, cleanup | P3.1 | Durable claim and retention are independent of codec field layout tests. |
| P3.4 | service/protocol wiring, restart/concurrency receipt | P2.3, P3.2, P3.3 | Integration proves the supported path no longer has a local set. |

```text
P1.2/P1.4/P1.5 + P2.1 -> P3.1 -> P3.2
                              -> P3.3 -> P3.4
P2.3 -------------------------------/
```

`token-replay` groups P3.1–P3.4 because contract, codec, durable consume and service wiring form
one linear replay boundary and reuse the same envelope/store/adversarial corpus. The group is
`recommended:`; every item still closes independently and a dependency-safe prefix remains
executable after D2's whole-plan design gate opens.

## Stable error intent

P3.1 freezes spellings. Closed semantic classes:

- token malformed / oversize / duplicate-key / unknown version
- unknown or retired/compromised kid; algorithm mismatch
- issuer/audience/purpose/tenant mismatch
- not-yet-valid / expired
- spent (CNF-20)
- digest/jti collision

P2 keeps policy/approval/authorization codes. P6 later puts P3 codes in the shared registry.
External errors stay redacted: no raw token, key material, jti in logs by default (safe digest
only).

## Validation strategy

1. Shared machine-readable manifest drives PHP/Dart parser and consume cases.
2. Duplicate-key, unknown-alg, oversize and prefix-stripping mutants must deny.
3. Two concurrent consumers against one PostgreSQL unique arbiter yield exactly one RETURNING
   row; restart with a new process still denies the winner's token.
4. Cleanup of an unexpired row is a failing negative test; cleanup after retention does not
   resurrect a still-unexpired token.
5. Frozen `2026-08` bytes remain unchanged; in-memory store cannot be a production preset.
6. Full PHP/Dart tests and analysis stay green; a happy-path HMAC round-trip cannot close P3.

## Risks retained by design

- Trusted composition root can still point at an empty key ring or a shared secret across
  tenants; P3 tests lookup isolation, not KMS.
- Empty RETURNING under Read Committed can mean a concurrent in-flight INSERT that later rolls
  back. P3 treats that as fail-closed denial; a later retry of the same presentation is allowed
  only if no committed row exists. This is availability, not a replay hole.
- Injected SQL executor is a trust seam; P3 does not sandbox SQL.
- P8 still owns mutation/fuzz depth beyond the P3 parser corpus.
- Unexpired in-flight old-format tokens die at cutover; that is an accepted 0.x private-package
  migration, not a silent dual parser.
