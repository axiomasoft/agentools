# Registered derived payload cleanup

`DerivedPayloadRepository` supplies real encrypted stores for `context`, `summary`,
`memory-candidates`, `history-search`, `provider-cache`, `context-cache` and
`temporary-payloads`. This is a Chatom integration change. It does not close
AgenTools platform milestones or qualify an Edufy host/provider deployment.

The stores reuse `PayloadRepository` for bytes, TTL, authorization, registered
copies, holds, purge reservations and deletion reconciliation. `0012_derived_payloads.sql`
adds only source/payload bindings and cleanup operation metadata. It follows the
unchanged `0006` delivery, PLAN2 `0007` Action submission, `0008` holds and `0009`–`0011` Action/Operations migrations.
No second transcript, generation, scheduler, delivery or Action journal is added.

## Producer and reader contract

Register the payload under the current host policy, then `bind` it to an exact
tenant/session/source/revision/dependency **before** writing bytes. Retain every
materialization through the existing `PayloadRepository::materialize` contract.
The source fence and parent payload are locked by SQL on binding: closed/stale
sources, foreign sessions and expired/purging/purged payloads cannot acquire a
new link. Immutable binding metadata cannot be deleted or rewritten.

Read through `DerivedPayloadRepository::read`, which rechecks current host/source
authority and the source fence before and after the existing authorized encrypted
payload dereference. Source closure denies derived reads immediately; it never
changes an existing hold or bypasses a payload retention/unresolved-work guard.
The final read check is not distributed atomicity with an external disclosure.

## Cleanup and recovery

Close the source through the existing delivery owner before cleanup. `cleanup`
durably reserves its exact key/hash/session/source/dependency/watermark before
irreversible IO. Changed operation bindings conflict before deletion. Only the
existing payload purge can remove bytes, including registered copies. A hold,
TTL or unresolved effect leaves cleanup pending; it never produces completion.

Completion is committed only after SQL verifies that **every** registered payload
for this source/session/dependency has a physical purge tombstone. SQL independently
enforces this precondition, source closure and a positive erased count. Empty
coverage is pending, not an ACK on behalf of an absent storage owner. Completed
receipts cannot regress or be deleted. Duplicate cleanup returns the same receipt.
A crash/lost ACK after physical deletion leaves the original payload purge and
cleanup identities pending; reconstruct the repositories and retry those identities.

Work is bounded. `scan` returns unpurged payload refs in stable payload-id order;
advance `after` with the last returned id when an early page contains a hold or
unresolved payload. `cleanup` accepts that cursor and a limit. Begin another pass
to revisit retained entries. No background loop is installed by this package.

The file backend now names staged writes with their exact handle digest. Authorized
purge holds the same handle lock, writes/fsyncs the tombstone, then removes both
ciphertext and associated staged ciphertext/tombstone writes, and fsyncs the directory.
A child-process test actually exits after fsync and before rename. Legacy global
`write-*` files and another handle's staged files are preserved, because the backend
cannot infer their tenant/policy/hold provenance. Their inventory/reconciliation
remains a deployment obligation.

## Integration and evidence boundary

Chatom's optional `PostgresDerivedInvalidator` composes the source fence with this
repository and returns a completed `InvalidationReceipt` only after actual cleanup.
It is not enabled by default. Missing coverage stays pending. Existing Edufy AiCore,
remote search/provider caches, client copies and unregistered backups do not become
covered by constructing this adapter. Producers must register their actual copies;
host owners must supply current authorization, keys and executable numeric policy.

The source test uses actual PostgreSQL constraints/receipts and sodium disk bytes
for all seven dependencies, registered copies, closure, retention/holds, duplicate
cleanup, wrong key/hash/scope, bounded work and lost deletion ACK/retry. Host and key
authority collaborators remain explicit fixtures. These results do not prove actual
Edufy erasure, remote-provider deletion, distributed races or machine power loss.

The separate Git branch `integration/chatom-retention-cleanup` is based on
`integration/chatom-durable-delivery` / `6fe2a25`. It carries selected owner changes
and the prerequisite `0007` schema, not the full concurrently developed PLAN2 tree.
The active PLAN2 checkout retains its unrelated uncommitted work.
