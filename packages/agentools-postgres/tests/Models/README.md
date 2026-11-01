# Durable invocation checks

Run one focused scenario from the repository root, for example:

```sh
python3 packages/agentools-postgres/tests/Models/run.py --filter testSecondHostReadExample
python3 packages/agentools-postgres/tests/Models/run.py --filter testKilledWorkerBoundaries
```

The runner verifies the restricted database owner, loopback connection and isolation
marker before mutations, or creates and removes its own labelled Docker PostgreSQL.
Never supply a development database. Missing credentials/dependencies/tests fail.

`SecondHostFixture` is a runnable generic batch read composition using the same
AdvanceRun and PostgreSQL journal as the first fixture. It creates a different
compiled request, no projected tools, and a batch result destination. The resulting
response and pending delivery intent are committed together. No delivery is inferred.

External provider, budget, encrypted payload owner and Action acceptance are explicitly
scripted test ports; they persist their facts in isolated host_* fixture tables.
These tests prove real PostgreSQL checkpoints, fencing and process restart, not live
provider support, encryption qualification, business accounting or effect execution.
`worker.php` is SIGKILLed only after the parent observes its named barrier. Connection
competition is proven with separate PDO connections, not simultaneous provider loops.

Production composition supplies InvocationGuard (current entitlement, emergency and
clock), BudgetPort (idempotent authoritative status/admit), InvocationPayloads
(encrypted immutable payload storage and policy-bound payload_refs registration),
ProposalDispatcher (durable dated Actions acceptance of the saved identity) and the
selected ModelTransport. ProviderLookup is optional, verified by request hash and an
evidence ref. None has an unlimited or fake production default. The tests autoload
current core source ahead of stale installed Composer path mirrors; vendor and lock
are untouched. PostgreSQL analysis scans that same current source.

AdvanceRun consumes a current AdvanceLease obtained from ClaimRepository. Pass the
returned lease only while held; after waiting_action/unknown completion releases it,
claim again. New workers first read RunRepository, stop for terminal runs, and resume
with the immutable InvocationPlan (run/turn/step/request ref, snapshot, bundle and
deployment identity). Same Invocation with different content conflicts. Accepted
response/proposals are never reconstructed through generation. A replacement is
allowed only from explicit unknown with parent/reason and its own admission. For
completed tools, status is checked before returning continued; the host builds the
next immutable plan from those results with parent/reason and a new step key.

Continuation is bounded across the run by persisted invocation/tool/error/estimated
unit counters, current cancellation/deadline checks and owner admission. Read dispatch
is deliberately serial (`maxParallelReads=1`); parallel effect execution is absent.
Unknown reservations are retained. This slice emits a technical invocation.usage
outbox entry pointing to immutable usage_versions, with full nullable model usage in
the immutable response payload and checkpoint. Full UsageEvent export, settlement and
operator reconciliation remain owner/P9 concerns. No business debit or automatic
release/settle is performed here.
