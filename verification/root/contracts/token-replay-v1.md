# Token replay contract v1 — AgenTools (PLAN1)

**Version:** v1
**Status:** P3 executable contract
**Evidence date:** 2026-09-20
**Cases:** `fixtures/hardening/token-replay-v1.json`
**Decisions:** D8

## Supported boundary

P3 replaces process-local spent-token sets with a versioned `at1` envelope and an atomic
durable consume. `ConfirmationService` remains the supported application boundary from P2.
Codec, key provider and consume store are constructor-injected trusted ports. Per-call callers
cannot choose `alg`, `kid`, replay storage or skip consume.

JWT/JWS compact serialization is not a requirement. [RAG:✅]
[RFC 8725](https://www.rfc-editor.org/rfc/rfc8725.html) algorithm, `iss`/`aud`/`kid`
and `"none"` controls apply as security properties, not as a JOSE product claim
(`research/P3-design-rag.md`). Frozen `spec/revisions/2026-08/**` stays byte-identical. Exactly-once
external tool effect is a non-guarantee.

## Envelope

Wire format is exactly three parts:

```text
at1. <base64url(canonical JSON payload)> . <base64url(HMAC-SHA256)>
```

There is no unsigned JOSE header. Version lives in the `at1.` prefix and in the signed `v`
claim. Raw token size is counted before any base64 decode (RL-008): **8192 bytes** maximum.
Extra segments, missing prefix, or unknown prefix deny as malformed.

Signing input is the compact UTF-8 JSON payload with lexicographically sorted keys and no
insignificant whitespace. PHP and Dart MUST byte-match that input. `alg` is signed inside the
payload and MUST equal the verification key's single algorithm.

Existing fixture `at1.eyJ2IjoxfQ.Zm9vYmFy` is spec illustration, not a valid v1 token.

## Closed v1 claim set

Payload is a flat JSON object, at most 24 members, depth 1. Unknown, extra or nested members
deny. Duplicate object names deny before interpreting claims ([RAG:✅]
[RFC 8259 §4](https://www.rfc-editor.org/rfc/rfc8259.html#section-4) does not
define last-wins; PHP `json_decode` / Dart `jsonDecode` are not a sufficient profile).

| Claim | Type | Rule |
|:--|:--|:--|
| `v` | integer | Must be `1`. |
| `alg` | string | Must be `HS256`. `"none"` and any other value deny. |
| `kid` | string | Non-empty lookup handle; not a trust anchor. |
| `iss` | string | Must match constructor-injected issuer. |
| `aud` | string | Must match constructor-injected audience. |
| `jti` | string | Token id; ≥128 bits CSPRNG (ULID/UUID acceptable if CSPRNG-backed). |
| `tenant_id` | string | Non-empty; consume primary-key tenant. |
| `purpose` | string | Closed enum: `confirmation`. |
| `action_id` | string | CNF-15 covered field. |
| `args_fingerprint` | string | CNF-15 covered field. |
| `descriptor_digest` | string | CNF-15 covered field. |
| `expires_at` | string | RFC3339 UTC second precision with `+00:00`; exp semantics. |
| `nbf` | string | RFC3339 UTC second precision with `+00:00`. |
| `iat` | string | RFC3339 UTC second precision with `+00:00`. |
| `principal` | string | CNF-15 covered field. |
| `proposal_id` | string | CNF-15 covered field. |
| `proposal_version` | integer | Positive. |
| `tool_name` | string | CNF-15 covered field. |

Identity: `token_id` is `jti`, never raw token bytes. `token_digest` is SHA-256 of the raw
token string and is forensic only; it MUST NOT appear in exception messages or default logs.

## Keys and algorithms

`TokenKeyProvider` returns tenant+purpose scoped material. Physical key use:

| State | Sign | Verify | Lookup miss / this state |
|:--|:--|:--|:--|
| `active` | yes | yes | — |
| `verify_only` | no | yes | sign denies |
| `compromised` | no | no | deny |
| `retired` | no | no | deny |
| unknown `kid` | no | no | deny |

[RAG:✅] NIST SP 800-57 Pt 1 r5 key-state vocabulary maps onto these names; NIST does not
prescribe Agentools class names, HSM or cryptoperiod. HS256 keys are CSPRNG bytes **≥ 32**.
Human-memorizable passwords MUST NOT be used as MAC keys ([RAG:✅] RFC 8725 §3.5). Each key
is bound to exactly one algorithm. Cross-tenant or cross-purpose material MUST NOT verify.
`TokenSigner` remains a deprecated one-key adapter for tests, not the supported production port.
No production in-memory key ring may share secrets across tenants.

Clock: injected. Default skew **60s**, hard max **300s**, applied only to `nbf`/`expires_at`
(RL-017). `expires_at - iat` default max **72h**, hard max **7d** (RL-018). Prepare rejects a
longer proposal window.

## Consume ordering

Presentation-consume (`presentation-consume`) is a property of a valid presentation (CNF-20), not of authorization
success.

```text
1. Bound raw token size.
2. Parse at1 envelope; reject duplicate names and closed-set violations.
3. Resolve verification key by (tenant, purpose, kid); pin alg.
4. Verify signature over the exact signing input.
5. Check iss/aud/purpose/tenant, nbf/expires_at with injected clock and bounded skew.
6. Compare P2 binding tuple to the authoritative confirmation record.
7. INSERT consume row (outcome=presented). Empty RETURNING → spent.
8. Evaluate current authorization/policy (P2). Refine outcome. Never unspend.
9. Return authorized invocation or structured denial.
```

Steps 1–5 are pre-presentation: they MUST NOT write the consume store. Step 6 failures are
still pre-presentation. Step 7 is the presentation boundary. Authorization denial refines
`outcome` and still returns spent on retry.

Consume SQL (normative arbiter, not the audit sketch rejected by F14):

```sql
INSERT INTO confirmation_consumed_tokens (
  tenant_id, token_id, token_digest, purpose, outcome, expires_at, presented_at
) VALUES (
  :tenant_id, :jti, :token_digest, :purpose, 'presented', :expires_at, :now
)
ON CONFLICT (tenant_id, token_id) DO NOTHING
RETURNING token_id;
```

[RAG:✅] PostgreSQL [`INSERT ... ON CONFLICT`](https://www.postgresql.org/docs/current/sql-insert.html)
`DO NOTHING` + `RETURNING` returns only actually inserted rows. Empty
RETURNING is spent. Do not SELECT-after-empty to authorize an effect. Under Read Committed a
concurrent in-flight INSERT that later rolls back is fail-closed denial; a later retry of the
same presentation is allowed only if no committed row exists.

Primary key is permanent unique `(tenant_id, token_id)`. Partial unique indexes on unexpired
rows are forbidden. Outcome starts as `presented` and may be updated once to
`authorized | authorization_denied | policy_denied`. UPDATE MUST NOT delete the row.

Cleanup: `DELETE WHERE expires_at + retention < :now` using the injected clock. Default
retention **24h**, hard min = skew (RL-019). Unexpired rows MUST survive. Cleanup MUST NOT
open a replay window.

In-memory stores are testkit/reference only and MUST NOT be the default production constructor.
PHP `suggests` `ext-pdo_pgsql`. Dart does not add a `postgres` package dependency. Production
adapters take injected PDO / SQL executor.

## Migration of old tokens

Old two-segment tokens (`payload.sig`) without `at1.` / `v` / `kid` deny after cutover. A
dual-read parser is forbidden. Unexpired in-flight old-format tokens die at cutover; that is
an accepted 0.x private-package migration.

## Stable external refusals

Existing `confirmation_token_invalid`, `confirmation_expired` and `confirmation_token_spent`
remain compatible. P3 freezes additional spellings; P6 later registers them in the shared
error catalog. Errors never expose raw tokens, key material, `jti`, tenant IDs or secrets.

| Condition | Code | Category | Retryable | Consume? |
|:--|:--|:--|:--|:--|
| malformed, oversize, extra segment, duplicate name, unknown version, extra/nested/missing claims, old format | `agentools.validation.confirmation_token_invalid` | validation | false | no |
| `"none"` / unknown `alg`, alg mismatch, unknown/retired/compromised `kid`, cross-tenant/purpose key, `verify_only` sign | `agentools.permission.confirmation_token_key_denied` | permission | false | no |
| `iss` / `aud` / `purpose` / `tenant_id` mismatch | `agentools.permission.confirmation_token_binding_mismatch` | permission | false | no |
| not yet valid (`nbf` + skew) | `agentools.precondition.confirmation_token_not_yet_valid` | precondition | false | no |
| expired (`expires_at` + skew) | `agentools.precondition.confirmation_expired` | precondition | false | no |
| empty RETURNING / restart / already presented | `agentools.precondition.confirmation_token_spent` | precondition | false | row exists |
| same `jti` different `token_digest` | `agentools.conflict.confirmation_token_collision` | conflict | false | row exists, not overwritten |

## Source-of-truth and staged ownership

P2 keeps authorization, policy and approval. P3 owns envelope, keys, consume and cleanup.
P4 owns idempotency leases. P5 owns the event journal. P6 owns shared schema/error revision.
P7 owns final `TokenSigner` export removal. P8 owns mutation/fuzz depth beyond this corpus.
P9 owns CI PostgreSQL matrix. Versioned SQL is a package artefact; PLAN1 does not migrate an
owner production database.
