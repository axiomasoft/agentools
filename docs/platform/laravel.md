# Private Laravel host adapter

`agentools/platform-laravel` is an additive, opt-in Laravel 12 adapter (PHP >=8.3).
The lock pins Laravel 12.70.2; Octane 2.22.0 is a development-only lifecycle test
dependency. Install does not register a provider, HTTP route, admin surface or
scheduler. The dated `agentools/sdk`, PHP/Dart namespaces, `LlmPort::respond(array):array`,
`ScenarioRunner` and closed 2026-09 ActionStatus remain unchanged. Existing dated
consumers continue to use their existing SDK directly.

Register `AgenToolsPlatform\Laravel\AgenToolsServiceProvider` explicitly in the host,
then register a host provider that supplies a **scoped** `Managed\HostBindings`.
For example, the host's own provider can use:

```php
$this->app->scoped(HostBindings::class, function (Application $app): HostBindings {
    return $app->make(MyHostFactory::class)->fresh();
});
```

`MyHostFactory` is host-owned code to implement, not a bundled default or an
object deserialized from settings. `fresh()` supplies explicit PostgreSQL
connections/schema, canonical JSON/hasher, current authorization/revisions,
code-only ActionRegistry/policy/schema validator, confirmation authority and
trusted keys/sealer/clock, proposal-only ModelTransport, BudgetPort, immutable
encrypted InvocationPayloads, encrypted payload lifecycle and HostAdmission.
No network/PDO/framework dependency enters core construction. The factory must
clean up any partially constructed resource if construction itself fails.

The provider binds these ports with scoped lifetime. Executor owns concrete
PostgreSQL InvocationRepository, SubmissionLifecycle, confirmation Service and
ScopedReplayStore construction. It does not accept a replacement journal or
lifecycle from an option/plugin. Production refuses known testing/reference,
Fake/InMemory/Scripted bindings. Host-owned durable implementations still require
their own qualification: a class name is not a durability certificate. There is
no default persistence, credential resolver, host authority or public controller.

Call the managed facade with an opaque host handle:

```php
$receipt = $app->make(Executor::class)->execute(new Input('inbox:123', Entry::Managed));
```

The facade accepts only `Input(ref, entry)`. It accepts no tenant, actor, key,
allow/skip flag, arbitrary callback, DB class/URL or classifier decision. Host
`authenticate()` returns an audit ref, `trusted()` rehydrates current tenant,
session, actor, purpose and source/payload from host-owned records; the adapter
checks that their tenants match and reauthorizes between boundaries.

`HostAdmission` resolves pinned profile plus CURRENT data/source ACL, compiles
stable intake/lease/work, checks deployment/emergency policy, checks full buffered
disclosure and enqueues durable delivery. The host records decision refs/codes
and correlates request, Turn, Run, Snapshot, Invocation, Action, Effect and Delivery.
Returned decision/content/delivery refs must match the trusted tenant. A pin,
classifier allow, editable prompt or plugin option never grants permission.
Host guards must record their denied decisions before throwing typed failures.
Technical logging includes ids, hashes, codes, durations, model/usage refs only;
optional telemetry policy cannot mask authorization or accounting failure.
Raw exception messages/previous exception traces do not escape Executor.

The fixed sequence is authentication -> tenant/session -> profile/current data
access -> context compilation -> deployment -> budget -> model -> tool/Actions
policy and confirmation -> disclosure -> delivery. Runtime's existing durable
journal records budget decisions, model result/usage, proposal identities and
Actions links; the host records surrounding guard decisions. No second loop or
owner handler is invoked by this adapter. Each call advances one bounded
exchange. Runtime may return waiting/unknown rather than pretend completion.
Read and write effects remain the existing durable Actions owner's responsibility;
business owners reauthorize immediately before actual writes.

Direct Actions use the same host admission, then a stable tenant-scoped budget
key derived from origin/submission identity and a request hash. Unknown/expired
admission refuses submission. Model is not applicable. Read submissions enter
ActionApplication; write submissions prepare the existing confirmation Service.
Confirmation commit and effect execution retain the existing headless P5 ports;
this facade never treats a proposed write, consent transcript or prepared token
as an executed effect. It persists input bytes before durable Action acceptance.

All `Entry` values (`managed`, `custom`, `raw`, `build`, `stream`, `voice`,
`background`, `direct-action`) use this sequence. Raw/custom/build are guarded
aliases, not an export of a builder or transport capability. Stream/voice select
a host delivery channel **after full buffered disclosure**; Executor returns a
DeliveryReceipt, not an iterator of unchecked provider tokens or audio. A null
acknowledgement is unknown, even if a host may already have delivered the content.
Actual delivery IO must recheck current disclosure authority and use the existing
durable delivery/outbox identity/hash and acknowledgement protocol.

Unsupported API runbook: do not port donor `raw()/build()/stream()` methods,
unrestricted callback/tool loops, raw SDK builders, live token streaming or
voice sessions onto Executor. They are absent from this additive API and must
fail as unsupported; do not silently forward them to a provider. Route an opaque
host input through `execute(Input(..., Entry::Raw/Build/Stream/Voice))` for the
buffered managed behavior. Keep existing AgenTools SDK consumers on their dated
API. Incremental checked-segment/live voice needs its owning transport and
explicit disclosure contract; it is not qualified here. No Botkit API/data/user
migration, admin publish flow or scheduler is supplied.

Queue `Jobs\RunInput('inbox:123')` stores only the host handle. At execution the
host authenticates the job principal and resolves CURRENT permissions; a queue
payload is never an authorization snapshot. `agentools:run {input_ref}` uses the
same background entry; no tenant/credential/skip switches exist. The command
prints only technical delivery ids and nullable acknowledgement.

Every entry resets PostgreSQL session state, encrypted payload handles and all
host actor/credential/profile/policy/retrieval/diagnostic state before work and in
`finally`, including exception/cancellation. HostAdmission.reset must clear every
host port's transient state, not just the facade's fields. Port instances are
forgotten after work, so repeated entries in one request resolve fresh bindings.
Retained scopes and facades cannot run after lifecycle retirement or cancellation.
Reset failures attempt every cleanup, poison process health across scoped flushes
and throw `LifecycleFailure`; the host must retire that
worker rather than retry on an uncertain connection. The sole singleton stores
only that health boolean; every user-bearing service is scoped. Process death is not a PHP
finally guarantee: durable PG/owner recovery remains the existing boundary.

Queue processing/processed/exception/failed/attempted, ordinary HTTP completion
and termination, and Octane request/task/tick/error events clear resolved scoped
state, including ports resolved without Executor. Octane cleanup selects the
actual event sandbox, not the worker application. No user service is constructed
for an unused cleanup. The real Octane OperationTerminated **contract** is used,
not a nonexistent Events\OperationTerminated class. Concurrent use of one scope
is refused. Independent workers still rely on the existing PostgreSQL fencing.

The testing opt-in requires both the code-bound `agentools.testing === true` and
`env === testing`. Production defaults to guarded real ports; this flag is never
read from an input, DB profile or plugin. P6.3 owns reusable testing fakes.
P6.2 can add code-only registry composition through the existing ActionRegistry
and HostBindings, without replacing this mandatory sequence.

Verification is registered as `laravel-composition` in tools/platform_gate.py.
It requires the exact named cases, isolated PostgreSQL with verified database,
restricted owner and allow marker, Composer validation/static analysis, a clean
no-dev source install and current source-to-inventory/consumer mapping. Missing
PG/dependencies, a missing case, zero tests or required skips fail. The existing
safe PG bootstrap provisions Docker if explicit isolated credentials are absent.
Focused checks can use `tests/Composition/run.py --filter METHOD`.

The source attribution map is generated by
`python3 packages/agentools-laravel/tests/Composition/reuse.py --write` and checked
by the suite. All P6.1 composition code is new target code; zero donor source was
copied. Inventory references map the selected ideas to consumer tests. Reuse in
place remains under the core/PostgreSQL/dated SDK owners and proprietary project
license; donor revision is retained as design provenance, not transferred code.

Evidence is contract-scripted hosts/providers on real Laravel and isolated PG.
Octane events/sandboxes are exercised without a Swoole/RoadRunner server; no live
provider, real external host, production rollout or deployment rollback is
qualified. P5.5 checks.2 and correction-1 evidence are consumed with their source
hashes. P5.2 historical RED/rejected review remains historical; the owner-directed
correction was not independently re-reviewed. P2.4 fractional ManualClock and
P3.5 audit/reset uncertainty remain unresolved. These tests do not resolve them.
D8 excludes classical mutation testing and mutation scores.

Framework references: [scoped container bindings](https://laravel.com/docs/12.x/container#binding-scoped-singletons),
[queue events](https://laravel.com/docs/12.x/queues#job-events), and
[explicit package providers](https://laravel.com/docs/12.x/packages#service-providers).
