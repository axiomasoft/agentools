# P4 design — lease/fencing idempotency and external-effect guarantees

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P4-design-rag.md`.

## Design question

How can Agentools close the `resolve`/`complete` crash window with acquire/heartbeat/CAS
fencing, without claiming exactly-once external effects, inventing an outbox/saga engine,
merging ALC executor epoch into the request key, or rewriting frozen `2026-08`?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| PHP/Dart `IdempotencyStore` expose only `resolve(request, now)` and `complete(request, result, digest)`. No lease, heartbeat, fail, attempt, or fencing token. | both contracts; F04 | Replace the production port with acquire/heartbeat/complete/fail. Unfenced `complete(request)` is not a supported path. |
| In-memory and SQLite stores treat `result == null` as in-flight. `complete` updates by storage key + fingerprint with `result_json IS NULL`; any holder of the request can finish the row. | `InMemoryDeduplicationStore`, `SqliteIdempotencyStore` | CAS must require the current `lease_token`. Fingerprint remains the misuse detector (IDE-16), not the ownership proof. |
| Expired records are deleted, including in-flight rows, so the next `resolve` is `new` (IDE-21). SQLite deletes expired rows inside `resolve`. | in-memory expiry test; `deleteExpired` | Record TTL applies to **completed** rows only. In-progress rows transition by takeover or capability-aware fail, never silent delete that would hide an unconfirmed effect. |
| Client-facing states are exactly `new`, `duplicate_completed`, `concurrent_in_flight`. Stored errors replay as completed results. | `DeduplicationState`; IDE-10/12/13; PHPUnit count=3 | Internal storage is `in_progress` \| `completed`. Audit-sketch `failed` is `completed` with a stored error, not a fourth observer state and not a retry license. |
| Scope uniqueness is tenant + tool + caller key. Policy already splits retention, confirmation lifetime, and client retry window. | `IdempotencyScope`, `DeduplicationPolicy`; IDE-5/18/19 | Keep that PK and those two timers. Lease duration is a **third** independent timer. |
| `ToolPort.call(tool, arguments, idempotencyKey)` has no capability. `ActionProfile` has `side_effect` and `idempotent_commit` only. Slice coordinator and ScenarioRunner invoke the port directly; they never call the store. | `ToolPort`, `ActionProfile`, `SliceToolCoordinator`, `ScenarioRunner` | P4 introduces `IdempotencyCoordinator` as the supported invoke path and a catalog of downstream capabilities. The store alone cannot choose retry policy. |
| Frozen spec forbids waiting on an in-flight duplicate and forbids a “failed, retry same key” state. ALC fencing epoch is a **different** mechanism from the idempotency key. | IDE-14/15/13; ALC-26/27/28 | Immediate `concurrent_in_flight`. Lease fence ≠ action `epoch`. P5 owns journal epoch; P4 owns request-key lease. |
| Audit SQL/interfaces are sketches. F14 forbids treating them as schema. Composer/pubspec still have no PostgreSQL driver. | `audits/2026-09-19-audit.md`; F14; manifests | P4.1 freezes the real contract. Production adapter is injected PDO/SQL, same as D8. |
| Conformance fixtures encode the three observer states, not leases. | `fixtures/2026-08/vectors/l1/idempotency-*.json`; `schema/idempotency.json` | Engine/SDK may acquire internally. Frozen bytes stay identical until P6. |

## Accepted boundary

P4 introduces one versioned lease/fencing contract consumed by both SDKs:

1. **Observer states stay IDE-10.** `acquire` maps to `new` (this caller holds a lease), `duplicate_completed` (replay stored success or error), or `concurrent_in_flight` (valid foreign lease). Fingerprint mismatch is `idempotency_key_reuse`, not a fourth state.
2. **Internal row.** `in_progress` always has `lease_token` + `lease_until` + `attempt >= 1`. `completed` always has `result_payload` + `result_digest` and null lease fields. `fail(lease, error)` writes `completed` with that error (IDE-12/13).
3. **Fence.** Each acquire/takeover issues a new opaque 128-bit token and increments `attempt`. Heartbeat/complete/fail are `UPDATE … WHERE state='in_progress' AND lease_token=:token`. Empty `RETURNING` / `rowCount !== 1` is lost-lease: the worker must not publish a result.
4. **Takeover.** `acquire` on `in_progress` with `lease_until <= now` CAS-replaces the token and returns `new` with `takeover=true` and `attempt' = attempt+1`. Concurrent acquire while the lease is valid returns `concurrent_in_flight` immediately (IDE-14/15).
5. **Clocks.** Record `expires_at` remains `DeduplicationPolicy.retention` (IDE-18/19/20). Lease duration is independent, default 30s, hard max 300s. Injected `Clock` supplies `:now` (same testability as P3). Production workers must share a clock whose skew is less than half the lease. Cleanup deletes only `completed AND expires_at < now`.
6. **Capability is coordinator policy, not store physics.** The store always fences. `IdempotencyCoordinator` looks up `ToolIdempotencyCapability` before/after acquire and decides whether to invoke the tool.

### Acquire ordering

```text
1. Require caller key (IDE-9) and compute args fingerprint (IDE-6/7).
2. High-risk + unknown capability → deny before INSERT (no row).
3. INSERT in_progress (attempt=1, new token, lease_until=now+lease)
   ON CONFLICT (tenant_id, tool_name, idempotency_key) DO NOTHING RETURNING *.
4. Winner → new (lease).
5. Else SELECT:
   a. fingerprint mismatch → idempotency_key_reuse.
   b. completed and expires_at > now → duplicate_completed (replay).
   c. completed and expires_at <= now → delete completed row, retry from 3 (IDE-21).
   d. in_progress and lease_until > now → concurrent_in_flight.
   e. in_progress and lease_until <= now → CAS takeover (step 3 semantics with attempt+1).
6. If takeover and capability forbids re-invoke: fail(lease, ambiguous) without tool call.
7. Else invoke ToolPort with the same durable key when capability is native_key.
8. Heartbeat while the call runs. complete(lease, result) or fail(lease, error).
9. Lost-lease on 8 → do not retry the effect; return lost-lease to the caller.
```

P5 may later append `effect_requested` / `effect_confirmed` with an executor epoch. That epoch is not this lease token (ALC-28).

### v1 numeric bounds

These are P4-owned until P1.5 execution records them as `RL-*` rows. They are not P8 timing SLOs.

| Bound | Value | Rule |
|:--|:--|:--|
| Default lease | 30s | `lease_until = now + duration`. |
| Hard max lease | 300s | Acquire rejects a longer duration. |
| Min lease | 1s | Below this, expiry races the call. |
| Heartbeat period | `lease/3`, min 1s | Extension is `now + duration` only if token still matches. |
| Fence token | ≥ 128 bits CSPRNG | Opaque; never logged; new value every acquire/takeover. |
| Attempt | bigint, start 1 | Increment only on successful takeover CAS. |
| Concurrent acquire proof | 50 callers, one winner | Empty RETURNING / in_flight for the rest. |
| Clock-skew budget | lease > 2 × assumed skew | Default assumes skew ≪ 15s. |
| Record retention | `DeduplicationPolicy` | Unchanged; must still cover confirmation + client retry (IDE-19). |

### Downstream capability

| Capability | First acquire | Takeover (`attempt > 1`) |
|:--|:--|:--|
| `native_key` | Invoke; pass the same durable key downstream | Invoke again; same key |
| `read_before_write` | Invoke | Invoke only if injected `ExternalEffectProbe` reports absent; else complete with the probed stored result |
| `compensatable` | Invoke | No automatic re-invoke; `fail(lease, ambiguous)` — product saga is out of scope |
| `at_most_once_only` | Invoke | `fail(lease, ambiguous)` without invoke |
| `unknown` + high-risk (`side_effect` not `read`/`draft`) | Deny before acquire | n/a |
| `unknown` + not high-risk | Treat as `at_most_once_only` | `fail(lease, ambiguous)` |

Missing probe on `read_before_write` degrades to `at_most_once_only`. Outbox/inbox are not created. Exactly-once remains a non-guarantee unless the downstream system itself is idempotent (`native_key`) or a shared transaction exists (not this package).

## Alternatives

### A. Keep `resolve`/`complete(request)` and document single-worker use

Rejected. PHP-FPM, Dart isolates, and any crash after the effect falsify durability. F04 is already confirmed.

### B. Adopt Powertools-style INPROGRESS expiry without a fencing token

Rejected as the production contract. Powertools time-based takeover admits a second executor when `in_progress_expiration` passes and does not CAS-complete on an ownership generation (`P4-design-rag.md`). That is the stale-writer hole Kleppmann describes. Agentools issues a new token and rejects complete/fail with the old one.

### C. Merge lease `attempt` with ALC executor `epoch`

Rejected. ALC-28 forbids deriving one from the other. Request duplicates and executor replacement are different races. P5 owns `epoch_started` on the journal.

### D. Fourth observer state `failed_retryable` or blocking wait

Rejected. IDE-13/14/15. Domain failure is stored and replayed. Concurrent callers get immediate in-flight. Ambiguous crash for non-retryable tools is stored as completed error (`idempotency_ambiguous_effect`).

### E. Implicit transactional outbox/inbox for exactly-once

Rejected. P4.md excludes it. Capability `native_key` is “pass the key”; it is not an outbox product.

### F. Use audit SQL/enums literally, including storage `failed` and unfenced sketches

Rejected (F14). Useful as a starting hypothesis; P4.1 is the schema/API owner. `fail()` exists as a fenced method whose storage state is `completed`.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|
| P4.1 | lease/fencing contract, capability model, migration, adversarial cases | P1 contracts | One normative owner before code. |
| P4.2 | PHP/Dart lease types, acquire/heartbeat/CAS, in-memory adapter | P4.1 | Types and local CAS do not need PostgreSQL. |
| P4.3 | PostgreSQL adapter, takeover SQL, completed-only cleanup | P4.1 | Durable arbiter is independent of coordinator policy. |
| P4.4 | IdempotencyCoordinator, capability catalog, consumer wiring, crash/fencing gate | P4.2, P4.3 | Store physics do not close F04 while runners still call `ToolPort` unfenced. |

```text
P1.4/P1.5 -> P4.1 -> P4.2
                   -> P4.3 -> P4.4
```

`lease-fencing` groups P4.1–P4.4 because the contract, in-memory API, durable row and coordinator
form one linear fencing boundary and reuse the same capability/crash corpus. The group is
`recommended:`; every item still closes independently and a dependency-safe prefix remains
executable after D2's whole-plan design gate opens.

## Stable error intent

P4.1 freezes spellings. Closed semantic classes:

- `agentools.validation.idempotency_key_missing` (IDE-9, existing)
- `agentools.validation.idempotency_key_reuse` (IDE-16, existing)
- `agentools.conflict.idempotency_in_flight` (IDE-14, existing)
- `agentools.conflict.idempotency_lost_lease` (stale heartbeat/complete/fail)
- `agentools.precondition.idempotency_ambiguous_effect` (takeover forbidden; stored completed error)
- `agentools.precondition.idempotency_capability_unknown` (high-risk unknown, deny before acquire)

P6 later puts the new codes in the shared registry. Frozen `IDE-31` is unchanged until then. Logs never contain raw lease tokens or full result payloads.

## Validation strategy

1. Shared machine-readable manifest drives PHP/Dart acquire/takeover/CAS/capability cases.
2. 50 concurrent acquires against one PostgreSQL unique arbiter yield exactly one lease.
3. Expired lease admits one takeover; the stale worker’s complete/fail/heartbeat returns lost-lease.
4. Stored result replay is byte-stable; fingerprint mismatch is reuse, not replay.
5. In-progress rows survive record-TTL cleanup; completed rows expire per IDE-21.
6. High-risk unknown capability never inserts a row; at-most-once takeover stores ambiguous error instead of invoking.
7. Frozen `2026-08` bytes remain unchanged; in-memory evidence cannot close P4.3/P4.4.

## Risks retained by design

- A `native_key` downstream that ignores the key still double-applies on takeover. P4 documents the cooperation requirement; it does not wrap arbitrary HTTP.
- `read_before_write` is only as good as the probe. A missing/lying probe degrades to at-most-once.
- Injected Clock skew larger than half the lease can admit two overlapping executors. Production root should prefer a shared source (DB `clock_timestamp()` wrapper is allowed).
- Lost-lease after a successful downstream call is an ambiguous effect; P4 records it when capability forbids retry, otherwise the next takeover may invoke again.
- P8 still owns mutation/fuzz depth and multi-host clock chaos beyond the P4 corpus.
- Unfenced `complete(request)` dies on the supported path; frozen harnesses must acquire internally. That is an accepted 0.x private-package migration, not a silent dual API.
