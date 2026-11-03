# Cancellation and operator operations

This module composes the existing headless Actions, confirmation and effect
ledger. It has no second worker/effect loop. The opt-in Laravel 12 provider now owns scoped container, queue and command
bindings; see `docs/platform/laravel.md` in the repository.
The package is optional for the direct dated SDK.

`Operation` binds a tenant-scoped command key, target, version/epoch, authenticated
actor/scope, safe reason code, evidence reference and operation-specific input
hash. `Authority` must verify current operator permission, target ACL, emergency
policy and the supplied evidence. IDs and a reason string are not authority.
Audit requests and accepted receipts are immutable. Same key/different hash is
a conflict. Status lookup is read-only and reports a missing receipt as pending;
it includes age and provenance, never secrets or generated text.

`cancelRun` requests `cancelling`, revokes the local worker fence and stops its
next step. It neither requests Action cancellation nor stops output. A separate
`run_finalize` operation requires verified policy evidence and no unresolved
effect, active linked Action or unknown Invocation. Terminal Run states stay
immutable. Source closure likewise leaves `cancelling`; it cannot silently skip
this decision. PostgreSQL requires the final cancellation audit receipt in the
same transaction as terminal cancellation.

`cancelAction` reuses the dated cancellation request. The Action's owner outcome
stays independent. Late success is accepted once through the original effect
ledger, even after an output interruption/cancellation request. Unknown effects
retain their reservation disposition and remain reconcilable.

`interrupt` commits its audit intent before calling host `OutputControl` outside
the SQL transaction. The host must enforce current output authorization, fences
and stable stop key/hash. On retry it must recover the original stop receipt.
Only acknowledged chunks/bytes/audio frames are recorded. Unknown acknowledgement
is null, never zero; generated units do not attest delivery. Stream/TTS stop does
not change any Action or effect. The test adapter uses synthetic transport
attestations and real PostgreSQL delivery controls, not a qualified audio provider.

`resolve` requires authenticated operator/evidence binding, current effect target
and epoch, and exact Action version. `ManualAuthority` verifies the owner evidence.
The manual journal event and operator receipt commit atomically with the accepted
effect outcome. A failed local commit leaves the intent pending and the effect
unknown. A repeated accepted command returns its receipt. A stale decision needs
a new command after inspection; raw mutable row edits are not a resolution API.
The lower-level effect resolution primitive now requires the expected version.

Compensation is always explicit. `compensationStatus` reports a missing declared
operation as unavailable. `prepareCompensation` verifies the original confirmed
effect, registered compensating definition and current policy, then prepares a
distinct Action bound to `compensation:<original effect digest>`. It never executes
the owner. Fresh host consent, confirmation commit and owner authorization still
use the ordinary write path. Failed confirmation presentation retains dated at1
consume-on-attempt semantics; an original token cannot authorize compensation.

No money-ledger release, arbitrary owner exactly-once guarantee, production
qualification or publication is provided by this local module.

The opt-in `Facade` adds doctor, inspect-run, recover, reconcile, relay and prune.
It preserves these cancellation/manual-resolution APIs and uses the ordinary
worker, Effects and delivery/accounting journals. See
[`docs/platform/operations.md`](../../../../docs/platform/operations.md) for trusted
operator composition, dry-run audit, bounded cursors, cadence and incident triage.
