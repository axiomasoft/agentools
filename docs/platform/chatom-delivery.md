# Private Chatom delivery integration candidate

2026-10-31. This optional PostgreSQL owner implementation is saved on the
`integration/chatom-durable-delivery` Git branch in AgenTools. Its parent snapshot
preserves the input PLAN2 candidate, based on `8c22b5a…`; the following integration
commit contains the new owner changes. See `CHATOM-INTEGRATION-HANDOFF.md` at the
repository root. It is not a merged PLAN2 milestone, deployed service or public
release. No SDK/wire/Dart contract changes are introduced.

## Composition and authority

`DeliveryRepository(RepositoryContext, authorizeSource)` requires a current host
callback `(ExecutionContext, Ref, operation): void`. It must enforce source scope
and current permission for open/admit/submit/claim/publish/attest/cancel, and trusted
cleanup authority for close even after deletion. Tenant/session repository checks
and source identifiers do not replace this policy. The host remains responsible
for visible-only export, purpose/classification, retention and key lifecycle.

`bindInput(context, inputRef, sourceRef, revision)` durably binds the immutable
input reference to one session/source/revision. Use `new IntakeRepository(env,
new SourceAdmission)` for the fenced profile. The hook locks the source fence
inside the actual intake transaction before inbox/Turn/Run/wake writes. Missing,
closed or mismatched bindings reject intake. This is opt-in for compatibility;
unbound historical rows need explicit reconciliation before profile activation.

`openSource` cannot reopen a closed source. `closeSource` monotonically closes it
and advances the watermark, optionally binds an invalidation operation key/hash,
then fences/cancels bound active Runs and delivery claims in the same transaction.
Reordered/duplicate closure cannot restore permission. A source receipt confirms
only this SQL work; it does not acknowledge cleanup of external summaries,
memory, indexes, provider/context caches, files or payload bytes.

## Persist, claim, deliver and reconcile

After registering/writing the encrypted export using `PayloadRepository` and its
actual store, call `submit` with `DeliverySubmission`. The transaction binds an
existing session Run, immutable response, payload hash, strict destination key,
source revision, policy revision and exact display hash. It reuses the existing
`responses`, `delivery_intents` and `delivery_receipts`; it stores no response
body or second transcript. Reusing any immutable identity with other data fails.

`pending(context, limit)` scans committed controls independently of wake signals.
`claim` takes a bounded PostgreSQL lease with random owner, version and epoch.
It commits native delivery outcome `unknown` before a remote effect is possible.
`assertCurrent` is read-only and verifies exact proposal/token, live lease,
current policy/source and unexpired readable payload; repeat it at the destination
transaction boundary. It does not promise cross-database atomic cancellation.

`settle` appends a hash-bound native versioned receipt and changes the native
intent/control with CAS. Known success/failure requires an explicit destination
receipt; exceptions never imply failure or absence. Exact duplicate settlement
is idempotent; stale claims cannot change the current fact. Cancel/source closure
preserves delivered/failed facts and possible effects. On lost ACK retry the same
saved export and strict key at the destination, then persist the returned receipt.
An unavailable/revoked source or expired export must not trigger regeneration or
rekeying. Manual reconciliation remains necessary if ordinary replay is denied.

`attests` binds the exact session/Turn/version/content/destination display hash to
persisted owner state and current source/policy/expiry. The Chatom adapter also
checks the assistant against the actual Session instance; the host must never
derive an ExecutionContext from untrusted response IDs alone.

## Schema and encrypted disk backend

Migration `0006_delivery_claims.sql` is additive to the five existing migrations.
It adds technical source fences, delivery controls, immutable input-source bindings
and content-free invalidation receipts, plus indexes/guards. The old migrations
and checksum history are unchanged. Original schema tests cover clean/reapply,
additive upgrade, old-reader compatibility and failed-upgrade rollback.

`FileEncryptedPayloadStore` implements the existing storage port with sodium
secretbox, authenticated ciphertext, immutable per-handle identity, file locking,
0600 temporary writes, flush/fsync and atomic rename. Supply a pre-existing
private directory and per-operation trusted key provider; there is no default or
cached key. Tombstone-before-unlink makes process-restart purge idempotent and
prevents resurrection. The store is an IO backend: callers must use
`PayloadRepository` for policy/audit/hash/expiry and metadata purge phases.

Expired exports cannot be dereferenced or delivered. `PayloadRepository::purge`
still refuses unresolved/unknown dependencies; this implementation does not
weaken that guard. Source closure does not automatically erase orphan exports.
The host must reconcile dependencies, apply explicit retention/hold/redrive and
tombstone horizons, and schedule policy-approved purge. Production key management,
materialization/backup erasure and power-loss directory durability are not
qualified by the disk backend's process-crash tests.

## Evidence and remaining gates

From this worktree, `python3 packages/agentools-postgres/tests/Isolation/run.py`
provisions its own restricted-role PostgreSQL test database and verifies the
allow marker before mutations. The complete existing isolation suite passes
54 tests / 1411 assertions with the additive migration. No owner guard is bypassed.

Chatom's isolated installed consumer uses PHP 8.5.11, PostgreSQL 17, Laravel
13.35.0 and Opis 2.6.0, with Composer path **mirrors**, not source autoload
overrides. It exercises real owner persistence, encrypted IO, native Chatom
delivery/receipts, unknown/lost-ACK replay, lease/CAS, a separate process crash,
expiry/purge and native source-edit cancellation. Host identity, snapshot,
routing and payload policy are declared fixtures. Existing component tests keep
their explicit owner/media doubles; they do not qualify those external owners.

Canonical commands, lock/source hashes and actual test counters are in Chatom
`tasks/2026-10-31-chatom-ai-transcript-I5.3-continuation.md` and
`var/quality/ai-transcript-completion-20261031`. This candidate does not close
the owner PLAN2 P4.4/P10.2/P11.2 milestones, Edufy MessengerPort R1–R12,
derived cleanup/retention or live Loqum media evidence gates.
