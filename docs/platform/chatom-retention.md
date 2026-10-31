# Chatom payload retention controls

These additive controls live in the existing PLAN2 checkout. They do not activate a
host profile or supply its authorization, numeric policy, key service or backup policy.

`0006_delivery_claims.sql` is the unchanged Chatom integration migration from
`6fe2a25`. PLAN2 concurrently added `0007_action_submissions.sql`; retention uses
`0008_payload_holds.sql`. Existing migration checksums are preserved.

## Policy, holds and scanning

`BoundedPayloadPolicy` decorates the host's **current** `PayloadPolicy`. The host
explicitly supplies `classification => purpose => maximum TTL in seconds`. There
is no default classification, duration, authority decision or key. Registration
must expire in the future and within the configured bound, measured by PostgreSQL.
The decorator still requires the underlying source policy on every operation.
It grants no authority from an opaque payload/session reference alone.

`PayloadHolds::place/release` requires a fresh trusted authorization callback for
the exact context, payload, hold and operation. Returned decision references must
have stable replay semantics. Hold placement and purge reservation serialize on
the parent payload row. SQL rejects hold placement after purge starts and purge
while any hold remains active. Released hold identities and release receipts stay
stored; replaying a released identity never reactivates it. Holds do not extend
readability or permit dereferencing an expired payload.

`ExpiryCandidates::scan(context, limit, after)` is a bounded, tenant/session/purpose
metadata scan for the **existing owner scheduler**. It includes held, unresolved,
orphan and purge-pending entries. Call `PayloadRepository::purge` for each candidate
under the current payload policy; a candidate is not permission to delete.
Continue with the last returned id, and begin a fresh pass to revisit retained
entries. Do not retry the first unresolved page forever or interpret enqueue as a
cleanup receipt. No second scheduler, transcript, delivery or Action journal is added.

All previous unresolved checks remain. A new direct Action submission's `input_ref`
is protected too: until the Action owner defines an exact payload-release outcome
contract, its reference remains unresolved even after an acceptance/terminal label.
A new submission cannot link a purging/purged payload. This deliberately does not
invent a "no effect" result or erase possible-effect facts.

## Physical deletion and recovery

The existing payload purge reserves its SQL token, deletes the encrypted primary
handle and every registered materialization, then commits the final SQL audit and
tombstones. A crash or lost acknowledgement after physical deletion leaves
`purging`; repeat the same purge to reconcile. Keys, hashes, holds, delivery facts
and technical tombstones are retained. The file backend now fsyncs the directory
after rename and unlink, including the tombstone **before** ciphertext removal.
This backend requires a filesystem that supports those durability operations.

Registered orphan exports (payload metadata/bytes committed but no response
submission) can be found by the same expiry scan. Unregistered legacy blobs,
crash-left `write-*` files, external provider copies, backups and key destruction
still require their storage owner's inventory and policy. Do not scan arbitrary
files and infer that absence of a live response permits deletion.

## Evidence and remaining boundaries

`tests/Isolation/RetentionControlsTest.php` exercises real PostgreSQL and sodium
disk storage: numeric limits, current source denial, multiple holds/restart,
registered copies, registered orphans, lost deletion ACK, no resurrection,
unknown delivery preservation, and real direct Action submission dependency.
Host policy/authorization and key-provider collaborators are explicit fixtures.
The source and installed-consumer receipts are linked from
`CHATOM-INTEGRATION-HANDOFF.md`; they do not qualify a real Edufy authority or a
live provider's retention behavior.

The seven non-source cleanup dependencies remain pending without real owner
delete/status/receipt APIs. Neither this scanner nor source cancellation is an
acknowledgement of their erasure. No deployed migration, production retention
schedule, master-key deletion or backup removal was performed.

Continuation: [registered derived payload stores](chatom-derived-cleanup.md) now
provide actual encrypted storage and durable cleanup receipts for explicitly
registered materializations. That scope does not include unregistered host or
provider stores; their gates remain open. Associated crash-left writes can be
purged by exact handle. Legacy unattributed `write-*` files remain preserved.
