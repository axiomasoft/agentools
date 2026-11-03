# Qualification storage

`Schema::install(PDO, schema)` is an explicit, version/hash-checked owner setup;
selection never performs DDL. It creates independent qualification snapshot,
policy, publication-head, audit and session-pin tables. Existing runtime sessions,
Actions, delivery, usage and profile assignments are untouched.

`Repository` requires current tenant-scoped host `Authority` for writes and reads.
Snapshot/policy/pin/audit rows have tenant-scoped stable identities and content
hash verification; SQL triggers reject updates and deletion. Revised assessment
is a new snapshot ID. Newest issued rejection, insufficient evidence or expired
assessment denies use; it never falls back to an older green. Schema source drift
under the same revision fails closed. No default fake Authority is shipped.

`publish(scope, command, policyId, expectedRevision, now, gate, compatibility)`
atomically commits the CAS publication head and reason-bearing audit. Same-key
replay returns the original revision under current guards; differing input hash
conflicts. Rollback requires an explicit origin and previously published stable
snapshot, plus current compatibility/qualification/material checks. Audit failure
rolls back the head. Publication does not update any session pin or replay Effects.

`pin(scope, session, measurements, now, gate)` locks the session, publication scope
and policy capacity, counts actual prior canary admissions and atomically records
one immutable assignment with measured evidence/values and audit refs. Concurrent
workers cannot exceed the policy's maximum. First assignment counts once; retries
recover the old pin regardless of the current head/cohort. Scope reuse conflicts.
Replay still checks expiry, latest assessment and current SelectionGate, so an
emergency/revocation stops further use without retargeting. A quota refusal is
not a business charge/release; external admission and money owners remain owners.

Compose `SnapshotCatalog` into Router/QualifiedTransport and provide trusted
SelectionGate/Compatibility adapters for publication/pinning. Repositories do not
send model requests or perform Actions. Current guards must be repeated at the
actual network/disclosure boundary after any DB transaction. The tests use real
Router and verified isolated PG with scripted external owners/deployments.

The CLI registry contains `test --suite qualification-rollout` (contract plus
required nested isolated-PG evidence) and `pg-test --suite qualification-rollout
--isolated` (exact durable partition). No required suite, case, skip, dependency
or isolation proof can be replaced with a green source inspection. Tests and
scoped static analysis run offline; live provider and rollout are not executed.
