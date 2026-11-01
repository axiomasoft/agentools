# Context compiler v2 (additive platform/v1)

`ReadContextCompiler::buildPlan(context, turn, run, bundle, blocks, now, pins)`
accepts only trusted `ContextBlock` values. It filters current source permissions,
expiry, purpose and tools before producing a provider-independent immutable plan.
There is no host model/container import or model-text metadata decoder.
`BlockKind`, `SourceKind`, `TrustClass` and `Classification` are tagged types;
the existing string constructor forms and properties remain compatible.
Host adapters assign all metadata. Non-host/policy sources remain untrusted;
summary, memory and tool transformations cannot promote source trust.
Policy, input, working state, promises and current tool results are mandatory.
Selected skills and goals are mandatory when the trusted host marks them so.

Blocks carry tenant-scoped ID, source/version, owner, content ref/hash, purpose,
trust, classification, evidence refs, expiry, mandatory flag, priority and
provenance (exact message IDs, through-turn, summary version, freshness and tool
ID). `rawResultRef` is audit metadata. `ToolResultView` keeps raw, model, user and audit views separate; its block's text is its minimal model
view; raw/user/audit payloads have no compiler input parameter. Optional denied,
expired, irrelevant, duplicate and over-budget blocks retain metadata and reasons.
Foreign optional sources are excluded with a redacted identifier. Mandatory
inaccessible sources refuse the plan. A duplicate block identity is a conflict;
content dedup uses kind, owner, purpose, trust, classification and content hash,
records the retained ID, and never removes mandatory content.

`selectDeployment` requires `DeploymentAccess`; unknown capabilities deny.
Candidates are considered in host policy order only after building the plan.
`finalize(plan,target,counter,now,renderer)` rechecks deployment and sources,
binds both adapters to exactly the selected deployment, and renders the concrete
request before admission. `ContextTarget` can still represent a host-preselected
legal target for the original read vertical; production composition must supply
the current deployment/capability guard. Core never imports a provider SDK.

Allowance = context window - output reserve - safety margin. All values are
nonnegative interoperable integers; reserve must cover requested maximum output.
`TokenCounter.version()` identifies the selected deployment's counting contract.
`exact` promises that deployment's tokenizer; `conservative` promises a documented
upper bound for the exact rendered API representation. Hosts document the bound
under that version. No other-model tokenizer may claim exactness. Invalid counts,
unknown confidence and changing version/confidence refuse finalization. The test
adapter uses UTF-8 serialized bytes as a scripted bound, with no live tokenizer
qualification. `SectionRenderer` preserves the historical text layout; providers
with API envelope overhead must supply a versioned `Renderer` with documented
layout. Renderer/tokenizer calls must be deterministic and side-effect-free.

After relevance and content dedup, optional content is dropped by ascending
priority, then example/summary/background/recent/evidence/goal rank and stable
section/ID order. Every drop has a reason. Each candidate is fully rerendered and
recounted. Mandatory overflow throws `BudgetOverflow`, a `DomainException` with
code `CONTEXT_MANDATORY_OVERFLOW` and a metadata-only budget report retaining all
mandatory blocks. No provider IO or message truncation occurs in this compiler.
There is no fallback outside original constraints; each changed target requires
new finalization and admission.

The immutable manifest contains tenant/session/turn/run/snapshot identities,
bundle and dependency pins (skills, policy, domain, material, corpus, release,
fragment and cache refs/versions/hashes), routing decision, model params,
model/capability snapshot versions and hashes (explicit null for legacy targets),
compiler/renderer/tokenizer versions and confidence, source message IDs and
per-block provenance, budget, framing and section token attribution, exclusions,
classification and retention. Null provenance fields mean unavailable, never a
fabricated source version or zero. Section used tokens are prefix-count deltas;
framing plus section deltas equals full rendered count. A non-monotone counting
adapter refuses rather than emitting negative section counts.

`immutable_content_hash` is the tenant-domain JCS hash of `{manifest, rendered}`
before adding the derived `snapshot_id` and `immutable_content_hash` fields.
The manifest contains no full rendered text. The opt-in PostgreSQL `Context\SnapshotRepository` stores immutable manifest
JSON, snapshot identity and a separately verified manifest hash. It validates the
compiled content hash before insert, allows idempotent replay, refuses differing
hash reuse, reauthorizes reads/replays and detects stored manifest corruption.
`Context\Schema::install` is explicit and version-hash guarded. No raw rendered
prompt or diagnostic payload column exists. Hosts may supply another durable
owner with equivalent integrity and authorization; they never persist
`CompiledContext.rendered` by default. The optional
`Diagnostics` port must authorize, encrypt, audit and enforce a requested future
TTL; its separate `DiagnosticRef` is bound to the snapshot hash and tenant.
No plaintext diagnostics mode is accepted. A receipt attests the host operation;
core does not implement encryption, secret resolution or access policy.

Text and decoded voice deltas use the same plan, pins, guards and finalization.
Given identical pinned state and adapter versions, snapshot hashes are identical.
History/summary acquisition belongs to P7.5. Disclosure-time source access,
emergency guards and diagnostic access/retention remain mandatory host obligations;
P7.6 owns their aggregate regression. Finalization is not a delivery permission.
No dated SDK wire, ActionStatus or namespace is changed.
