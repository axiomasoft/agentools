# Operations facade and recovery runbook

The optional `AgenToolsPlatform\Laravel\Operations\Facade` provides bounded,
typed operation services without loading Illuminate. Its command adapters load
Illuminate only when the host explicitly registers
`AgenToolsPlatform\Laravel\Console\OperationsServiceProvider`. The existing
Laravel provider still registers only `agentools:run`. There is no second
provider/effect worker and no dated ActionStatus change.

## Host composition and trust

Before exposing commands, the host must bind scoped `Facade`, `Host`, and
`RequestSource` implementations. Resolve `operation_ref` through trusted server
state into a `Command`: current `ExecutionContext`, tenant-scoped operation `Ref`,
exact target key/resource, bounded safe reason code and requested mode/page.
CLI input never supplies an actor, tenant grant, credential, consent or raw
checkpoint. Unknown handles fail closed. Every command, status lookup and page
requires current host authorization plus `Authority::authorize`: tenant/operator
permission, target ACL, emergency blocks and operator-configured rate limits.
The host authority must enforce its rate quota across workers; no process-local
limiter is presented as distributed admission.

`Command` accepts `doctor`, `inspect-run`, `recover`, `reconcile`, `relay`, `prune`.
`apply=false` is the default; limits are 1..100, default 50. Scan cursors are stable
`kind:key` metadata cursors, bounded to 256 bytes and bound into operation identity.
Each apply/dry-run page needs its own operation key. An identical key/input/scope
returns its immutable accepted receipt. Different input, actor, purpose or scope
under that key conflicts. Dry-run recovery/relay/prune records a redacted audit
intent and receipt; doctor and inspect-run do not write audit or product rows.
Thus dry-run performs no business operation but its administrative audit is durable.

Deploy core migrations, then explicitly call `Operations\Schema::install($connection)`
for the two append-only facade audit tables. Commands never install or repair schemas.
Setup requires the normal deployment role and is additive/repeatable. Database
restore/recovery is a separate deployment operation. Production mode is the default;
`HostBindings::assertProductionService` and `Host::assertProduction` reject test
bindings and must inspect nested real adapters. `UsageRelay` separately checks its
metering owner. `production:false` is an explicit local contract-test composition,
never a CLI option. Hosts must apply the existing production guard to payload,
credential and other nested bindings as well; no default host/fake is installed.

## Commands

All use `agentools:<verb> operation_ref [--apply] [--limit=50] [--after=kind:key]`.
The trusted handle binds target, resource, operator context, reason and operation ID.
`doctor` and `inspect-run` reject `--apply`. Doctor not-ready, unknown apply outcomes,
and command failures return nonzero; output contains safe codes, never exception text.

| Verb | Target/resource | Behavior |
| --- | --- | --- |
| doctor | deployment (empty target permitted) | Read-only metadata health; no secret/content dereference or repair. |
| inspect-run | existing run | Bounded ids/states/reasons/routes/version/epoch/age/hash graph (deadline when known, null otherwise; explicit unknown escalation code); no checkpoint/state_ref or payload reads. |
| recover | existing run | Wake same committed unstarted/stale Run, or reconcile saved subordinate identities. Waiting/terminal/live-claim states remain visible. |
| reconcile | existing run | Owner/provider status lookup or saved proposal mapping through existing journals; unsupported lookup stays unknown. |
| relay | delivery key, or resource `usage` + exact accounting event key | Retry only original undelivered key/hash. No generation, new response, effect or usage version. |
| prune | exact payload key | Purge only expired, unheld, resolved payload; retain tombstone, evidence and dependencies. No bulk SQL deletion. |

`Inspector` pages all linked invocations, effects and saved proposal mappings with
an allowlist of metadata columns. Unknown remains independent from waiting_action,
waiting_confirmation and waiting_external, failed, completed, cancellation_pending
and delivered. A committed Invocation without a result is unknown, not proven absent.
A missing Action projection for a saved proposal is `missing_action_submit`, with
its original Action identity. A live lease is `live_claim`; stale work wakes the
ordinary runtime whose claim path increments the epoch. Inspection itself never
changes a lease, journal, pin or owner outcome. Recovery tests cover direct Action
Effects linked by run_steps, even when they have no proposal_map row.

## Doctor probes

Doctor checks the installed PHP version and required PDO PostgreSQL/sodium/JSON extensions, reads Composer's actual installed SDK/core/PostgreSQL/framework versions,
checks every local core migration checksum against database history, and verifies
the facade tables exist. It requires distinct typed host probe findings for:
`bindings`, `registry`, `production_adapters`, `secrets`, `capability_evidence`,
`queue`, `scanner`, `outbox_lag`, `retention`, `pg_isolation`. Each finding is
`ready`, `not_ready`, or `unknown`, with a bounded code and optional installed version.
Missing, duplicate or corrupt probes and exceptions stay unknown/not-ready.

The host probes must inspect actual public bindings and registry/hash agreement,
credential resolver configuration without returning credentials, deployment evidence
expiry/revocation, required queue/scanner availability, configured outbox lag bounds,
retention/hold policy and its actual PostgreSQL isolation/role policy. A database
connection alone does not prove an isolation policy. Production fake/unverified
bindings and expired evidence must return not-ready. No guessed SLO, price, live
provider support or production qualification is inferred from healthy metadata.

## Recovery triage

1. Restrict tenant/operator/target scope; run doctor and inspect-run with a trusted
   handle. Use a bounded page and preserve operation IDs/cursors in the incident record.
2. `committed_unstarted`: apply recover to wake the same Run. `stale_claim`: wake the
   same Run; the existing claim/CAS path mints the next epoch. Do not create a Turn,
   source event or new Invocation from a scanner retry. Current permissions and
   emergency guards run again at actual dispatch.
3. Waiting for confirmation/action/external state: inspect the original reference;
   waiting is not an exception-driven retry grant. Missing Action submit uses the
   saved proposal/Action map through the normal confirmation/Actions boundary.
4. Unknown Invocation: lookup the original provider request through the host's
   qualified status port. Unsupported lookup remains unknown. A controlled new
   Invocation requires an explicit separate policy/budget decision with possible
   extra usage; this facade never creates one automatically.
5. Unknown Effect: lookup its original owner operation key/request hash with the
   existing `Effects\Service::reconcile(false)`. The supplied `EffectLookup` helper binds the original Effect key/hash/owner key and fence before that lookup; dry-run and mismatched identities are rejected. Never call execute or enable retry
   as a catch-all fallback. Unsupported/uncertain owner evidence stays unresolved;
   authenticated manual resolution uses the existing `Operations::resolve` path.
6. Unknown delivery: claim the original delivery key; destination must use lookup
   and dedup for its exact key/hash. `Host::deliver` calls its reauthorize closure
   immediately before every disclosure and destination commit. It returns only a
   verified receipt or null. Throw/lost ACK leaves unknown; it never proves zero.
   DeliveryRepository owns leases, fencing, retention/source guards and settlement.
7. Unknown usage export: `UsageRelay` restricts the existing Dispatcher to the
   explicit accounting event key. Status lookup precedes export; superseded versions
   retain causal order. Unknown owner ACK remains unknown, not a second usage event.
8. If the process dies between durable intent and receipt, status is unknown. Retry
   the same operation key/input. A wake is repeatable; owner lookup and destination
   dedup reuse the original identities. A later reconciliation pass can use a new
   administrative command key while keeping all resource identities unchanged.

`Host::reconcile` must use the original observation key/hash/owner key, verify owner
receipt binding and append through existing journals. It may return a receipt only
after that accepted local record commits. It receives a reauthorize closure for
every external IO/commit boundary. Owners remain authoritative. This is an explicit
host integration port, not a claim of remote owner qualification.

Unknown work blocks Run wake even if it is beyond the current metadata page:
`hasUnknown` checks the entire Run's unresolved invocation/effect dependencies.
Prune checks retention, legal holds and `platform_payload_unresolved`; the existing
PayloadRepository repeats current policy/SQL guards and uses its original stable
purge token across failures. Failed remote purge leaves unknown with no tombstone
until owner acknowledgement. Append-only facts, unresolved reservations/effects,
active references and legal evidence cannot be discarded by this facade.

## Optional cadence and DST

Cadence remains the existing optional `extensions/scheduler` adapter. It admits
work through the ordinary intake/outbox; it is not another workflow planner.
Occurrence identity is `(tenant_id, schedule_id, planned_occurrence_at UTC)`;
scanner retry/concurrent scanners cannot replace that identity or rewrite history.
Schedule versions pin timezone, active range and explicit DST/misfire policies.

* New York overlap, 2026-11-01 01:30:00: `choose-first` selects 05:30:00Z;
  `choose-last` selects 06:30:00Z. Worker locale does not choose the occurrence.
* New York missing wall time, 2026-03-08 02:30:00: `skip` emits no occurrence.
* Misfires select skip/one/latest/capped catchup according to the versioned policy.
  Catchup always has an upper bound; overlapping scans do not create an unbounded burst.

Scheduler evidence reused by the operations gate covers two concurrent scanners,
rollback between scan planning/intake commits, SIGKILL after acceptance commit,
and revocation before dispatch. Calendar checks cover overlap, gap, explicit policy
and capped misfires. Schedule cancellation does not cancel an already accepted Effect.

## Incident, rollback and restore

Emergency blocks target provider/account/deployment/model/region/tenant/task/audio;
the host checks them immediately before disclosure/provider/owner work, including
pinned sessions. Disable new admission to the affected runtime, drain known in-flight
work, then reconcile unknowns before selecting a compatible prior bundle/deployment
for new attempts only. Do not silently repin sessions or replay applied Effects.
Stopping output/TTS does not cancel an accepted Action. Compensation is separately
authorized. Mark historical sources revoked; do not rewrite their facts.

Restore a backup in an isolated database, rebuild projections and compare Action
sequence/digest, outbox receipts and usage corrections before restarting. Retain the
old reader during additive schema expansion. This is target AgenTools recovery;
it requires no Botkit cutover or old Botkit database.

## Evidence and baseline

`python3 tools/platform_gate.py test --suite operations` is the combined local
contract gate; `pg-test --suite operations --isolated` requires exact real durable
cases and per-part isolation proofs. Missing cases/dependencies/skips fail closed.
The coordinator owns those acceptance runs. Individual files/classes support scoped
executor checks; no mutation testing or live provider calls are part of this slice.

Responses expose per-page `observed` and `unknown` counts plus item age/reason,
version/epoch and a cursor. IDs/hashes belong in restricted traces/audits, not metric
labels. The runner records measured harness elapsed milliseconds and executed cases;
this is a local contract baseline with scripted external ports, zero live calls and
zero spend. Production queue/provider/owner latency, outbox SLOs, domain quality and
remote owner guarantees remain unmeasured. P12.4 can instrument these boundaries;
P12.5 owns measured deployment baselines and live qualification handoff. Set alert
bounds from operator policy after actual measurement; no synthetic success-percent
or price is presented as a production baseline.
