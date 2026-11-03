# Trusted host integration v1

Additive server composition contract. It changes no dated ActionStatus, SDK namespace,
managed entry point or wire format. No routes, container lookup or default owner bindings
are installed. Browser/model input supplies only an opaque `Managed\Input` handle.

`HostOwner.request(handle)` authenticates the current request/job principal and resolves
host-owned session, instance, source event and content hash. `Boundary.open` constructs
`ExecutionContext.fromHost`, then obtains current `ContextState` and approved slices.
The authorization ref is evidence, never a permanent grant. Tenant/actor/acting-as/subject,
purpose, consent and policy revisions survive queue rehydration. Jobs queue only the handle;
a worker calls `reset` in finally before another tenant. No cached authorization is reused.

Envelope fields map to existing core values: tenant/actor/acting-as/subject/authorization/
consent = TrustedInput.context.identity; purpose/session/correlation_id = context;
source kind/event_id/assistant instance = SourceIdentity; payload_hash and optional
conversation/message/utterance refs = InputPayload. ContextState adds locale, traceparent,
education/realtime session refs, context snapshot/revision, policy_versions, capability,
retention_policy_ref and residency_policy_ref. Inapplicable optional refs are null.
Empty refs and a default/global tenant are forbidden by core value constructors.
Host values never come from model JSON or browser allow/role/consent flags.

`HostOwner` is a public host API port, not access to its tables. ContextBlock carries owner,
source/version, purpose, classification, trust, content ref/hash, expiry and evidence.
Boundary rejects cross-tenant, expired, unauthorized or wrong-purpose slices, checks bounded
model projections and rehydrates current context before every operation. Changes to actor,
child, consent, tenant, role policy, purpose, snapshot revision or emergency policy require
new authorization/snapshot. The compiler still owns planning, token counting and finalization;
this adapter never promotes text into trusted policy or treats a pinned snapshot as authority.

`EducationApi.readReport` returns ToolResultView with minimal model text and separate raw,
user and audit refs. `ReportAdapter` is an OwnerPort for the existing Actions effect service,
not another effect-capable loop. Execute requires `ReportEvidence.assertCurrent`: load the
saved approved Actions binding, authenticated affirmative event, actor, request/input hash,
preview TTL, current epoch/cancellation and expected report revision. A constructed
ConfirmedAction is insufficient. Edufy must repeat current permission, consent, rights,
report version and stable operation-key/hash checks atomically at its own mutation boundary.
The API accepts no arbitrary table, operation name, container, grant or class name.
Lookup checks current permission but must remain usable after confirmation expiry; missing
receipt means unknown, never failed/absent or zero. Owner result refs stay tenant scoped.

Source intake retains existing `(tenant, source.kind, source.event_id, assistant instance)`
identity and payload hash. Same identity/hash returns the original Turn/Run/ACK. A different
hash gives contract error SOURCE_PAYLOAD_CONFLICT mapped to existing integrity_conflict,
with no new work. Production uses existing atomic PG intake/outbox; the example is a
single-process contract stub, not a new runtime store.

## Ownership

AgenTools stores technical session/turn/run/invocation, manifest, proposal/effect/delivery
journal/checkpoint/outbox refs. Edufy AiCore owns EducationSession, task orchestration,
pedagogy/packs/providers/scorers, official report facts, consent/rights/handoff and personal
knowledge map. Chatom owns visible transcript/membership/history; Loqum owns media and
playout; Files/Vaulter owns immutable bytes; Library/Content owns meaning/publication/rights;
ControLLM owns metering; host/Tariffler owns entitlement; existing Billing and money owner
keep ledgers. No second transcript, report, learning map, entitlement or billing store here.

## Export and limitations

`examples/platform/edufy-host/contract.json`, `fixture.json` and `change-request.json` are
versioned integration exports for a real Edufy implementation. Local evidence kind is
host_contract_harness at contract-scripted tier. External Edufy implementation, host_e2e,
live provider support, educational qualification and pilot remain pending. A missing owner
fails closed; it never selects the scripted stub as a production fallback. P10.2 should use
the same source identities and host rehydration, with Chatom history/delivery through ports.
