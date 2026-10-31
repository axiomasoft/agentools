# Private additive platform v1

This contract adds assistant identities, immutable context values, technical
receipts and pure Run transitions. It preserves the dated `2026-09` protocol,
its ten ActionStatus values, SDK namespaces, both ScenarioRunner key schemes,
and native PHP/Dart direct Actions. It implements no storage transaction,
worker loop, effect dispatch, confirmation service or external owner.

`schema/contracts.json` contains closed draft 2020-12 definitions. Validate the
named `$defs` shape, then the semantic rules in `profile.json`: standard JSON
Schema cannot express equality of all nested tenant IDs or current host
permissions. The shared fixtures explicitly name positive/rejected outcomes
and exact public reason codes. `check-spec` runs the real published schema and
its pure semantic profile; it reads no plan-local schemas or examples.

Only trusted server composition installs `HostAuthorization`. Its `authorize`
method authenticates and checks the current tenant, session, purpose, consent
and emergency policy, returning `HostIdentity` evidence. The private
ExecutionContext constructor is reachable through `fromHost` only; there is no
browser/model JSON hydration method. `browser_input` accepts only an untrusted
client event and input/attachment refs. An authorization ref is evidence, never
a permanent grant. Call `assertCurrent` with the current host port at each
owner write/disclosure; the host may deny even when all old pins match.
The DTO cannot make an application perform that check automatically.

Every ID/ref contains a concrete tenant. IDs are opaque, with distinct PHP
types for Turn, Run, Invocation, Proposal, Action, Effect, Delivery, UsageVersion,
Session and Instance. Source identity is tenant + source namespace + event ID +
assistant instance. `IdentityKeys` derives the new assistant identities from
stable scopes. Payload hashes are excluded from Turn/initial Run keys: a store
replays its saved AcceptedTurn for the same hash and rejects a changed hash.
The store still owns atomic Turn/Run/outbox acceptance and one active Run per
Turn. No in-memory DTO proves durable acceptance.

Proposal identity uses either a saved provider ID or an ordinal persisted once
after response normalization. Fallback is a new Invocation attempt. Action and
Effect IDs retain the dated schemas' nonempty-string form, without a new length,
ASCII, UUID or punctuation restriction. They remain IDs issued by the SDK/bridge; this contract
does not derive replacement dated IDs. Effect keys bind tenant/Action/ordinal;
owner operation refs and request hashes survive retry/takeover. Delivery binds
Run, response version and destination; UsageVersion binds Invocation/version;
schedule occurrence binds schedule and normalized UTC instant. Store mappings
must be persisted before their respective IO boundaries.

`CanonicalJson` is a port, not another JCS encoder. Adapter composition reuses
the existing RFC 8785 utility; the tests exercise the existing SDK encoder via
a test-only adapter. Hash input is JCS of
`{revision:"platform/v1", tenant_id, domain, payload}`. Domain and tenant separate
hashes. Source payload fields are closed and explicit, including content hash;
transport timestamps are absent. Unknown fields are rejected rather than
removed ad hoc. Duplicate JSON names are invalid, object and array stay
distinct, and integer fields are bounded to ±(2^53−1). UTC values require a valid
calendar instant ending in `Z`; normalized values trim trailing fractional
zeros. Correlation IDs do not change the authorization scope hash.

RunStatus is separate from ActionStatus. The exact graph is in
`alc-compatibility.json`; RunCheckpoint checks version/epoch and rejects terminal
callbacks. Unknown and unresolved effects survive transitions and block
completed/failed/cancelled. `resolved` requires a tenant-bound evidence ref
whose owner outcome and current operator policy must already have been checked
by the caller. Checkpoint values do not verify an external owner's claim or
provide database fencing. A completed Run/Action does not promise delivery.

`ActionPhaseMapping` projects an already journaled dated status. A ready phase
additionally requires trusted dispatch eligibility; neither value grants
permission to dispatch. Operational reasons such as manual resolution and
invocation uncertainty do not become ActionStatus values. The SDK reducer
remains the sole dated lifecycle authority.

`errors.json` fixes the additive vocabulary and permitted retry classes.
Unknown outcomes require lookup/manual resolution. A timeout never proves a
failed effect or zero usage. Missing usage facts remain null, including partial
usage with one observed token count; observed zero is a known numeric fact.
Same-version facts cannot change on replay. Dated errors wrap contextually via
the explicit mapping; platform errors are never synthesized as new wire enums.
Public error fields are closed, with generated message keys and opaque refs.
Raw provider responses, secrets and diagnostic text belong outside the public
payload.

The worked vectors preserve the named accepted-example scenarios at the
value/schema boundary. Example 10 proves the public confirmation-invalid
projection; it does not replace the dated ConfirmationService. Current-owner
fixtures use a scripted host port and pure checkpoints. No fixture proves real
provider support, PostgreSQL recovery, runtime guards, host integration or
production readiness.

The `contracts` registry partition owns 60 exact pure contract cases.
`platform-spec` owns 40 exact shared schema/profile vectors. Each focused PHP
file in `packages/agentools-core/tests/Common/` can run independently. Composer
test/analyse include the new package tests. Missing dependencies, empty or
changed case sets, unexpected acceptance, wrong rejection reasons and skips
are failures. Acceptance, durable and E2E suites remain with their owners.
