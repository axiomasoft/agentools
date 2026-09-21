# Idempotency lease / fencing contract v1 — AgenTools (PLAN1)

**Version:** v1
**Status:** P4 executable contract
**Evidence date:** 2026-09-20
**Cases:** `fixtures/hardening/idempotency-lease-v1.json`
**Decisions:** D9
**Bounds:** RL-015 (default 30s), RL-016 (hard max 300s), RL-021 (50 concurrent acquire)

## Supported boundary

P4 replaces the supported idempotency port `resolve` / `complete(request)` with
`acquire` / `heartbeat` / `complete(lease)` / `fail(lease)`. The store fences the
**idempotency record**. It does not make an arbitrary external tool exactly-once.
[RAG:✅] [Kleppmann, 2016-02-08](https://martin.kleppmann.com/2016/02/08/how-to-do-distributed-locking.html)
a lease timeout does not mean the holder has stopped; a monotonic fence that storage
checks is required. Local CAS fences this row only.

`IdempotencyCoordinator` is the supported invoke path for mutation / `idempotent_commit`.
Omitting store or catalog is a construction error, not a silent unfenced call.
Frozen `spec/revisions/2026-08/**` stays byte-identical. Exactly-once tool effect is a
non-guarantee (NG; capability `native_key` is cooperation, not a wrapper around HTTP).

## Observer vs storage

Observer states remain IDE-10. There is no fourth observer state and no “failed, retry
the same key” license (IDE-13). `fail` is fenced complete-with-error.

| Observer `DedupState` | Storage row | Caller receives |
|:--|:--|:--|
| `new` | `in_progress` with this caller’s lease | `IdempotencyLease` (`takeover` may be true) |
| `duplicate_completed` | `completed` and `expires_at > now` | stored result + digest (success or error) |
| `concurrent_in_flight` | `in_progress` and `lease_until > now` | `idempotency_in_flight`, **no** lease |

Internal storage is only `in_progress` | `completed`. Audit-sketch `failed` is
`completed` with a stored error, not schema (F14). Lease token is **not** ALC epoch
(ALC-28; epoch is P5).

`in_progress` always has opaque `lease_token` (≥128-bit CSPRNG), `lease_until`, and
`attempt >= 1`. `completed` always has `result_payload` + `result_digest` and null
lease fields.

## Clocks

Three independent timers:

| Timer | Source | Rule |
|:--|:--|:--|
| Record retention `expires_at` | `DeduplicationPolicy.retention` (IDE-18/19/20) | Set on first insert. Cleanup deletes only `completed AND expires_at < :now`. |
| Confirmation lifetime | `DeduplicationPolicy.confirmationLifetime` | Unchanged; retention still covers confirmation + client retry. |
| Lease duration | acquire argument | Default **30s** (RL-015), min **1s**, hard max **300s** (RL-016). `lease_until = now + duration`. |

Injected `Clock` supplies `:now` (same testability as P3). Production workers must share a
clock whose skew is less than half the lease (default assumes skew ≪ 15s). Heartbeat
period is `lease/3`, minimum 1s. Extension is `now + duration` only if the token still
matches.

In-progress rows **survive** record-TTL cleanup. They transition by takeover or
capability-aware `fail`, never by silent delete that would hide an unconfirmed effect.

## Acquire ordering

```text
1. Require caller key (IDE-9) and compute args fingerprint (IDE-6/7).
2. Coordinator: high-risk + unknown capability → deny before INSERT (no row).
3. INSERT in_progress (attempt=1, new token, lease_until=now+lease, expires_at=now+retention)
   ON CONFLICT (tenant_id, tool_name, idempotency_key) DO NOTHING RETURNING *.
4. Winner → new (lease).
5. Else SELECT:
   a. fingerprint mismatch → idempotency_key_reuse.
   b. completed and expires_at > now → duplicate_completed (replay).
   c. completed and expires_at <= now → delete completed row, retry from 3 (IDE-21).
   d. in_progress and lease_until > now → concurrent_in_flight (immediate; IDE-14/15).
   e. in_progress and lease_until <= now → CAS takeover (new token, attempt=attempt+1).
6. If takeover and capability forbids re-invoke: fail(lease, ambiguous) without tool call.
7. Else invoke ToolPort; pass the same durable key when capability is native_key.
8. Heartbeat while the call runs. complete(lease, result) or fail(lease, error).
9. Lost-lease on 8 → do not retry the effect; return lost-lease to the caller.
```

[RAG:✅] PostgreSQL [`INSERT ... ON CONFLICT`](https://www.postgresql.org/docs/current/sql-insert.html)
`DO NOTHING` + `RETURNING` returns only actually inserted rows. First acquire uses that
arbiter. Do not `DO UPDATE` onto a completed row.
[RAG:✅] PostgreSQL [`UPDATE`](https://www.postgresql.org/docs/current/sql-update.html)
`RETURNING` returns rows **actually updated**. Empty RETURNING is lost-lease / lost-takeover.
Do not SELECT-after-empty to authorize an effect.

Takeover SQL:

```sql
UPDATE agentools_idempotency_leases
SET lease_token = :token,
    attempt = attempt + 1,
    lease_until = :lease_until
WHERE tenant_id = :tenant_id
  AND tool_name = :tool_name
  AND idempotency_key = :idempotency_key
  AND state = 'in_progress'
  AND lease_until <= :now
RETURNING *;
```

Complete / fail / heartbeat:

```sql
UPDATE agentools_idempotency_leases
SET ...
WHERE tenant_id = :tenant_id
  AND tool_name = :tool_name
  AND idempotency_key = :idempotency_key
  AND state = 'in_progress'
  AND lease_token = :token
RETURNING *;
```

Cleanup:

```sql
DELETE FROM agentools_idempotency_leases
WHERE state = 'completed' AND expires_at < :now;
```

Primary key is `(tenant_id, tool_name, idempotency_key)` (IDE-5). Partial unique indexes
on unexpired rows are forbidden. Tenant isolation is part of the primary key.
Fifty concurrent acquires against one PostgreSQL arbiter yield exactly one lease (RL-021).

## Capability (coordinator policy, not store physics)

The store always fences. `IdempotencyCoordinator` looks up `ToolIdempotencyCapability`
before/after acquire.

| Capability | First acquire | Takeover (`attempt > 1`) |
|:--|:--|:--|
| `native_key` | Invoke; pass the same durable key downstream | Invoke again; same key |
| `read_before_write` | Invoke | Invoke only if injected `ExternalEffectProbe` reports absent; else complete with the probed stored result |
| `compensatable` | Invoke | No automatic re-invoke; `fail(lease, ambiguous)` |
| `at_most_once_only` | Invoke | `fail(lease, ambiguous)` without invoke |
| `unknown` + high-risk (`side_effect` not `read`/`draft`) | Deny before acquire | n/a |
| `unknown` + not high-risk | Treat as `at_most_once_only` | `fail(lease, ambiguous)` |

Missing probe on `read_before_write` degrades to `at_most_once_only`. Outbox/inbox and
saga are not created. [RAG:✅] [AWS Powertools Python idempotency](https://docs.aws.amazon.com/powertools/python/latest/utilities/idempotency/)
INPROGRESS expiry without a generation token is **not** sufficient as the Agentools
complete primitive. [RAG:✅] [draft-ietf-httpapi-idempotency-key-header-07](https://datatracker.ietf.org/doc/html/draft-ietf-httpapi-idempotency-key-header-07)
aligns with IDE-1/12/14/16 (replay stored success or error; concurrent is immediate
conflict). The draft does not specify lease fencing or exactly-once side effects.

## Migration of unfenced complete

Unfenced `complete(request)` is unsupported after cutover. Dual unfenced+fenced
production complete is forbidden. `resolve` / `complete(request)` are absent from the
production interface. Test helpers may wrap `acquire` for frozen three-state assertions.
Frozen harnesses may acquire internally so `2026-08` vectors still observe three observer
states without new spec bytes. That is an accepted 0.x private-package migration.

In-memory stores are testkit/reference only and MUST NOT be the default production
constructor. PHP `suggests` `ext-pdo_pgsql`. Dart does not add a `postgres` package
dependency. Production adapters take injected PDO / SQL executor. Versioned SQL is a
package artefact; PLAN1 does not migrate an owner production database.

Tokens are never logged. Result payloads are not written to default logs.

## Stable external refusals

Existing `idempotency_key_missing`, `idempotency_key_reuse` and `idempotency_in_flight`
remain compatible. P4 freezes additional spellings; P6 later registers them in the shared
error catalog. Frozen `IDE-31` is unchanged until then.

| Condition | Code | Category | Retryable |
|:--|:--|:--|:--|
| missing caller key (IDE-9) | `agentools.validation.idempotency_key_missing` | validation | false |
| same key, different fingerprint (IDE-16) | `agentools.validation.idempotency_key_reuse` | validation | false |
| valid foreign lease (IDE-14/15) | `agentools.conflict.idempotency_in_flight` | conflict | true |
| stale heartbeat/complete/fail (empty RETURNING) | `agentools.conflict.idempotency_lost_lease` | conflict | false |
| takeover forbidden; stored completed error | `agentools.precondition.idempotency_ambiguous_effect` | precondition | false |
| high-risk + unknown, deny before INSERT | `agentools.precondition.idempotency_capability_unknown` | precondition | false |

Lost-lease after a successful downstream call is an ambiguous effect at the tool; the
worker MUST NOT publish or retry. The next takeover may invoke again only when capability
allows (`native_key` / successful `read_before_write` probe-absent).

## Source-of-truth and staged ownership

P1 owns guarantees, clock, and resource policy (RL-015/016/021). P4 owns lease/fencing,
capability catalog, and coordinator wiring. P5 owns the event journal and ALC epoch.
P6 owns shared schema/error revision. P7 owns final exports. P8 owns mutation/fuzz depth
and multi-host clock chaos beyond this corpus. P9 owns CI PostgreSQL matrix. In-memory
evidence cannot close P4.3/P4.4.
