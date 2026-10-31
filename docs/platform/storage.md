# PostgreSQL storage contract

This private adapter stores technical identity, version, journal, checkpoint,
encrypted payload references and transport state. It does not own Chatom history,
money, business permissions, consent or model/owner execution. The dated Action
EventStore/reducer remain authoritative. No transcript clone, provider worker,
parallel effect loop or production release is introduced.

Every tenant repository checks trusted host context and tenant/session binding;
composite keys and foreign keys prevent cross-tenant links. IDs never authorize a
read. Tables are explicitly schema-qualified; trigger query search paths are
pinned. The baseline does not depend on RLS or a connection tenant setting. RLS
would require its own verified role/pool/reset/migration/recovery decision.

Intake commits inbox, Turn, Run and wake outbox together. Claims use short SQL
transactions with version, random owner, epoch and PostgreSQL lease/deadline.
Queue messages only reference existing durable work. Checkpoint/heartbeat and
Action append reject stale workers. Journal append participates on the same PDO
as derived current, immutable effect intent and outbox; returning a participant
AppendResult alone is not commit proof. Rebuild folds the dated journal into fresh
staging, verifies canonical digest, then swaps derived current without owner IO.
Transport retry preserves event key/hash; owner idempotency/reconciliation remains
separate from local fencing. Unknown is never inferred as failed, successful or zero.

`PayloadRepository` registers encrypted storage/key handles with content hash,
classification, purpose, UTC retention and trusted session binding. Reusing an ID
with another immutable binding conflicts; same binding cannot resurrect a tombstone.
It stores no bytes. The required `PayloadPolicy` port owns current data/source ACL
and classification/retention decisions. `EncryptedPayloadStore` is an external
resolver/deletion port with no production fake implementation. Only trusted refs
reach it; neither prompts nor arbitrary caller IDs grant secret resolution.

Dereference commits a minimal authorization audit before resolver IO, verifies the
returned content hash, rechecks current policy/host permissions, then fences metadata
version, expiry and purge state before releasing bytes. Revocation, content mismatch,
expiry, audit failure or concurrent purge deny release. Audit rows contain only
payload/actor/decision refs, purpose, operation, outcome and DB times. They contain
no bytes, encrypted/key handles or external exception bodies. Resolver and policy
errors are translated to bounded typed failures without previous sensitive exceptions.

Purge reserves a durable token after DB expiry and dependency checks. Unresolved
outbox/deadletter, delivery, effect, invocation and active Run references retain the
payload. New links serialize on the payload row and cannot attach once purge starts.
The resolver deletes the root and registered materialized copies outside SQL using
stable per-slot operation keys. Automatic recovery requires advertised same-key
idempotent deletion/status semantics; unsupported deletion stays manual. Unknown
delete outcomes retain pending metadata and never fabricate completion. A final
transaction clears all handles and records tombstones/audit after acknowledged
deletion. Crash/rollback retries reuse the same keys. Hash, classification, purpose,
expiry and allowed provenance remain; unresolved authority records are not deleted.
External owners must register every local dependent materialization and implement
actual encrypted storage, key handling and policy-approved deletion/backup semantics.

`JobBoundary` explicitly owns a worker connection lifecycle. It refuses nesting,
resets before/after each job in finally, rolls back unfinished SQL and uses
`DISCARD ALL` to clear session role/settings/resources. The required host reset
callback clears actor, credentials, profile/policy and retrieval caches; the payload
store clears decrypted bytes, handles and credential caches. Construct fresh trusted
context per job. Repository services retain no user context. This lifecycle is
opt-in composition for a dedicated worker connection, not a reset of arbitrary
caller-owned transactions.

The `pg-isolation-retention` suite composes the exact schema, intake, claims/outbox,
Action journal and payload case sets: 52 required isolated PostgreSQL cases,
including projection rebuild and no-lock IO. Its owning command requires verified
database name, restricted owner and allow marker. Missing cases, zero tests,
required skips and missing dependencies fail. `tests/Isolation/run.py --partition
payloads --filter METHOD` is a focused payload proof; Composer runs existing
partitions once and then this payload-only partition. In-memory/scripted resolver
behavior is test-only and does not qualify live encrypted storage or owner APIs.

P3.1/P3.2 evidence and deviations, later Composer/test-helper/gate changes and
P3.3/P3.4 evidence belong in the requested P3.5 review. Covers metadata alone is
not execution evidence. Historical receipts remain historical after additive schema
changes. ManualClock F1 still truncates fractional time, remains deferred and is not
used as lease/deadline proof. The conductor owns acceptance V1/V2 and the single
requested review/correction; local focused checks do not establish production readiness.
