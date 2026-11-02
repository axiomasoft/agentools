# Deployment routing and outage handling

This additive module performs deterministic routing without provider calls. It does
not replace the runtime journal, host authorization, disclosure, Actions or budget
admission. The dated SDK API is unchanged.

`CatalogEntry::fromArray` is the semantic catalog validator. The structural schema
and synthetic example are in `fixtures/platform/v1/routing/`. Semantic validation
also checks bounded evidence lifetimes, limits and pricing dependencies. Inventory
may describe unknown support, but only supported, documented/probe verified,
currently valid capability and model/terms evidence can route. `costAt(now)` returns
null for missing, expired or unverified pricing. Synthetic names and amounts in
tests establish no real provider support or price.

A `RouteRequest` supplies trusted subject, host, purpose, immutable bundle and task,
allowed regions, actual data classes and capabilities, and every qualification
component: suite content versions, prompt, skills, tool schema and material corpus
hashes. `QualificationTuple` adds the exact deployment/provider/account/region/model
revision and the full immutable catalog hash. The current latest certificate for
that tuple must qualify it; rejection, insufficient evidence and expiry deny.
Equal-time disagreement denies. A future-issued certificate grants nothing.
Health never grants qualification.

`Router::route` filters tenant, legal/terms, data, region, capability evidence, admin,
health, emergency, current host permission/quota and exact qualification before
ranking. Unknown host facts deny. Code-defined known role/subject/screen route keys
are preferences among eligible candidates. Remaining order is quality descending,
latency then currently evidenced cost ascending (null last), then deployment ID.
Every non-selected candidate has typed reasons. No eligible candidate yields
`non_ai_text`; the host supplies its permitted text without calling a model.

## Composition and finalization

1. Compose `Catalog`, current `HostPolicy`, `CanonicalHasher` and explicit clock.
   HostPolicy checks current owner permissions and quota; it is not a grant from
   configuration, a model, a cached snapshot or a qualification certificate.
2. Select a deployment, persist `Repository::record` with the stable route event,
   and finalize context using its limits, model revision and catalog snapshot/hash.
   Call `revalidate` at finalization; a stale pin is refused, never silently rebound.
3. Compose `QualifiedTransport` around the selected transport **inside** existing
   `Runtime\GuardedTransport`. It repeats current catalog/state/emergency/host and
   qualification checks at the network boundary, binds credential and deployment,
   and runs existing transport preflight, including opaque continuation scope.
   Stream checks execute when iteration starts. Adapter cancellation and the host
   disclosure policy remain responsible for stopping an already open exchange.
4. `admitted=true` is only for a trusted existing journal admission whose drain
   policy permits completion. It never permits a new invocation on a draining
   deployment, or bypasses an incident, quarantine, disabled or retired state.
   Mandatory guards, accounting and owner authorization remain in the runtime.

## Operational state runbook

The PostgreSQL `Deployments\Schema::install` is explicit and version/hash checked;
there is no automatic migration or route registration. Each repository is bound to
a tenant actor and a current host `Authority`. New catalog records start disabled
with unknown health. Reusing a tenant-scoped catalog, qualification, emergency or
trace identity with different content raises an integrity conflict.

| Admin state | New invocation | Existing admitted invocation |
| --- | --- | --- |
| enabled | Current filters apply | Current filters apply |
| draining | Denied | Current filters apply if host drain policy allows |
| disabled / quarantined / retired / incident_blocked | Denied | Denied |

Health is stored separately: healthy/degraded can remain eligible; unknown or
unavailable denies. Change state through `Repository::state` with the expected
version; stale writers fail CAS. Do not interpret a healthy provider as an admin
enable, quota grant or qualification certificate.

For an incident, append `Repository::block` with an explicit evidence reference
and provider/account/deployment/model/region/tenant/task/audio selector. Specified
dimensions match together; null dimensions are wildcards, including a meaningful
false audio selector. Empty selectors are rejected. Pins do not bypass an active
block. Lifting requires current `catalog.emergency.lift` authorization, expected
version and evidence; the state change and immutable audit commit together. Health
recovery alone never lifts a block. A renewed incident uses a fresh incident ID.

## Outage matrix and host responsibilities

`FallbackRouter` only plans the next boundary. It cannot call a provider, submit an
Action, reconcile an owner operation or disclose output. `retryActions` is always
false; the origin retains the exact bundle, goal, Action and disclosed segment refs.

| Condition | Outcome |
| --- | --- |
| Unknown effect | reconcile_required; host looks up the same owner operation first |
| No disclosure, qualified allowed alternate | reroute with a fresh Invocation identity |
| Only forbidden/unqualified/unavailable alternates | non_ai_text |
| Already disclosed | interrupted; original segment attribution is retained |
| Disclosed, explicit new segment and current host policy permits | new_segment with a fresh Invocation and explicit segment ref |

Before continuation, the host uses existing owner reconciliation and the existing
single runtime journal. It records the fallback event and durably opens the fresh
Invocation, then finalizes a fresh deployment-bound context and budget admission.
There is no implicit Action retry, copied provider opaque state or merged answer.
Pass all previously attempted deployments to prevent cycles. Invocation identity
is stable for tenant/original Invocation/failure event; a changed plan for that
same event conflicts in `Repository::recordFallback`. A subsequent reconciliation
or explicit segment decision needs its own stable event ID.

## Evidence and following work

`routing` / `deployment-catalog` gate partitions register 25 contract-scripted cases
and six isolated PostgreSQL cases. The fixture matrix maps the named outage cases.
This is local technical evidence, without live provider, host deployment, educational
qualification or production readiness claims. V1/V2 acceptance is conductor-owned.

P9.2 should keep nullable prices/usage unknown, retain current BudgetPort admission
and quota ownership, and attach usage to each fresh Invocation. Route quality and
latency are host ranking inputs, not money or admission facts. This slice creates
no usage or ledger event and performs no extra admission or effect-capable loop.
