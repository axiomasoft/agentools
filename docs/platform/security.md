# Observability and diagnostic security

Managed authorization, accounting, confirmation, disclosure and owner Effects keep
their existing ownership and guards. The additive observability module records
technical metadata; it neither grants access nor creates another execution loop.
The dated ActionStatus and SDK wire contracts are unchanged.

## Closed trace and metric surfaces

`Core\Observability\Event` reuses `Security\LogProjection` and `TraceLinks`. It
retains tenant/session/actor IDs, host request/trace correlation, scope hash, a
closed stage/outcome/entry, typed reference links, hashes and optional duration.
Nested untrusted fields and complete exception chains are discarded. Only the
existing typed error code, or `internal_error`, is projected; message, stack,
previous exception, prompts, child text, books, tools, HTTP and provider bodies
never enter the record. JSON and debug output contain that same closed projection.
Opaque trusted IDs remain IDs; callers must not put plaintext into identifier fields.

Trace links cover Turn/Run/Snapshot/Invocation/Action/Effect/Delivery, decision,
usage and opaque diagnostic IDs. Diagnostic IDs are locators requiring a separate
permission, never bearer grants or encrypted-store/key handles. The PostgreSQL
`AuditJournal` stores immutable tenant/event identity plus canonical hash. Identical
append replays; differing hash conflicts. It is an append-only metadata journal,
separate from Action/Effect/usage sources of truth. The database assigns audit time.

`Laravel\Observability\TraceGraph::read` checks the current context and Run/session
binding, then reads only allowlisted durable IDs. Use pages of 1..100 nodes with a
`kind:id` cursor; no fan-out join can silently skip siblings sharing one Run step.
It links direct Action Effects through run_steps and runtime proposals through
proposal_map. It never reads checkpoints, state_ref, payload content, credentials,
ciphertext or diagnostic keys. Snapshot and provider links come from the checked
InvocationPlan at the managed boundary. Effect execution remains owner-controlled;
its durable graph can be inspected after it commits.

`Metric` accepts a closed `Signal`, nonnegative finite value or null, closed stage,
outcome and entry, and bounded category/certainty. There is no arbitrary label map.
Tenant, session, Run, Invocation, Action, Effect, Delivery, prompt/tool/skill IDs and
raw model/deployment refs belong in protected traces, not metric dimensions.
Unknown numeric usage/cost/duration stays null with certainty `unknown`; explicit
zero has a numeric zero and known certainty. Unsupported/high-cardinality categories,
negative values, infinities and unknown-as-zero are rejected.

Signals distinguish queue delay, run/compiler/provider/tool/action/reconcile/delivery
latency, section tokens, cache hit/cost, outbox age, privacy/budget rejects, voice
interruption and usage categories. `ObservedTransport` measures the existing
provider call once with a monotonic clock, without copying the response. Managed
stage intervals and operations recovery intervals are also measured. Queue age,
section counts, cost/currency, tool/owner timings and segment acknowledgements must
come from the relevant host boundary as actual measurements; registering a Signal
is not evidence that every host exports it. No SLO, price or threshold is guessed.

## Integration and failure policy

Telemetry is opt-in through the final optional `HostBindings::$telemetry` argument.
All managed entries retain the same admission/guard sequence. Existing mandatory
`HostAdmission::decision` calls still run; the new trace is additional, not a
replacement or optionalization of authorization/accounting. No routes, exporters,
credential resolvers or fake implementations are automatically registered.

Construct `Telemetry` with a durable `AuditSink`, a host `MetricSink` and explicit
`metricsRequired` policy. Every event appends its mandatory audit before metrics.
Audit failure throws a sanitized `Failure('audit_unavailable')` and stops the
protected next step. Required metrics failure also stops; optional metrics failure
sets visible sticky `health()['metrics_degraded']`. Hosts must expose that health
signal through their worker/doctor monitoring rather than discard it when a scoped
binding retires. Optional metrics cannot catch or downgrade authorization,
accounting, confirmation, provider uncertainty or owner failures.

A provider exception is observed as unknown; it is never proof of failure/zero.
An audit failure after provider IO likewise leaves normal runtime reconciliation
responsible for uncertainty. `ObservedTransport` does not retry, fabricate lookup
support, merge streams or claim delivery. Delivery traces retain null ACK as unknown;
generated tokens/audio are not delivered/heard units.

Trace attempt IDs are new administrative observation identities. Turn/Run/provider/
proposal/effect/delivery/usage identities remain the original durable identities.
The scoped telemetry keeps only its current per-job context, attempt counter and
monotonic timer; `HostBindings::reset` clears them in finally and attempts all other
resets. Failed reset quarantines the existing managed lifecycle. Retired transport
cannot resume with the old tenant context. Only user-free health flags may persist.

Operations `Facade` can receive the same optional telemetry for recovery/relay/prune.
A mandatory audit precedes work. Doctor, inspect-run and status stay read-only and
do not create telemetry audit rows. No inspection path calls DiagnosticVault.

## Encrypted diagnostics are separate disclosure

Explicit deployment setup runs core migrations, then
`Observability\Schema::install($connection)`. Runtime commands never install DDL.
`observability_events` and `observability_diagnostics` are separate tables with
separate lifecycle rules. Tests use the proven isolated PostgreSQL harness only.

`DiagnosticVault` requires a current repository context, a distinct
`DiagnosticAuthority`, a scoped `KeyResolver`, a host-pinned tenant key Ref, clock,
mandatory Observer, maximum retention seconds and maximum payload bytes. Hard
technical caps are one day and 4 MiB; the host supplies stricter policy as needed.
These are safety bounds, not performance/SLO promises.

Capture is an explicit sensitive API, not automatic prompt retention. The existing
host Context Diagnostics adapter may call it only for an integrity-checked compiled
context under the separate capture grant. Run inspection permission is insufficient
for `diagnostic_capture`, `diagnostic_read` or `diagnostic_purge`. The vault checks
normal Run/session scope and current diagnostic ACL/purpose/emergency permission;
caller-supplied grants or arbitrary secret refs are not accepted.

Capture records a durable audit intent before resolving the pinned key. It encrypts
an identity-bound envelope with sodium secretbox and a fresh nonce. The stored
binding includes tenant, diagnostic ID, target, scope, expiry and content hash.
Same identity with differing input conflicts; no plaintext storage/log fallback
exists. The vault never returns the encryption key, key locator or ciphertext.
Resolver exceptions are sanitized and ephemeral key/plaintext buffers are cleared.

Read checks the distinct current grant, audits the attempt, resolves only the
configured key, verifies authenticated encryption, binding and content hash, then
rechecks permission and TTL before audit completion and again before release.
Database time and host clock both enforce expiry. Revocation or expiry during
resolver/decryption blocks release. Resolver outage, corrupt ciphertext, missing
rows, expired evidence and audit failure remain explicit non-success outcomes.
Denied/expired diagnostic attempts are metadata-audited when the authenticated
scope permits the audit; audit failure cannot become a plaintext fallback.

Successful read returns a separate `SensitivePayload`. Its explicit `reveal()` is
authorized plaintext disclosure; JSON/debug redact it and serialization is refused.
It must not be put into inspection views, error context, logs, manifests or durable
application caches. TTL is checked at disclosure; it cannot retract plaintext that
an authorized caller already received.

Expired diagnostic ciphertext can be pruned only with current purge permission and
no legal hold. SQL guards reject early purge, ciphertext mutation and row deletion;
prune keeps identity/hash/expiry and audit evidence as a tombstone. Expiry still
denies reads during a legal hold. This lifecycle does not delete source Run/Action/
Effect/reservation facts or their unresolved queues. Hosts own audited legal-hold
placement/release under their privileged retention policy.

Key resolver and diagnostic authority reset on every exit, including denied and
failed attempts. All resets are attempted. A reset failure quarantines the vault;
construct a fresh authorized scope instead of reusing residual credential caches.
Production composition must call `assertProduction` on the vault and telemetry,
which recursively rejects test/fake nested bindings. External key services and
monitoring exporters remain host integrations requiring their own qualification.

## Evidence

`python3 tools/platform_gate.py test --suite observability-security` is the
conductor-owned combined gate. It requires the exact named privacy case set,
nonempty real isolated PG diagnostics/managed partitions, cron/voice/current-guard
and accounting regressions, scoped static analysis and Composer validation.
Missing partitions, cases, isolation proofs, failures or required skips fail closed.

`fixtures/platform/v1/redaction` contains synthetic nested child-text/key/provider/
tool sentinels and case obligations. It is not execution evidence. Actual focused
reports state their tier, case counts, failures/skips, hashes and isolation proof.
Local scripted external ports are not live provider, external resolver/exporter or
production-readiness evidence. P12.5 owns deployment measurements and incident/live
qualification handoff. Classical mutation testing is not run in this phase.
