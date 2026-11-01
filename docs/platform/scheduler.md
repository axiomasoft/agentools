# Optional schedule triggers

`agentools/platform-scheduler` is a private optional adapter in
`extensions/scheduler`. It requires the existing Laravel composition; the core
`Scheduling\SchedulePort` remains a PHP-only interface. Core imports no scheduler,
Laravel or Cadence code. No routes, scheduler hooks, cron entries, queue handlers
or provider calls are registered on installation.

The target inventory does not establish reusable tenant/replay semantics for
Cadence. This slice implements the required occurrence contract directly and
adds no `directorytree/cadence` dependency or donor code. Native daily wall-time
rules are the supported rule type; a general cron engine remains outside this
implementation. No Botkit data/schema/job migration is supplied.

The host explicitly installs `src/schema.sql` after the existing platform
migrations, using `Schema::install($pdo, $applicationSchema)`. Installation owns
only the extension tables/functions and requires an idle connection and explicit
application schema. It never runs at package boot. The normal migration owner
must record this additive extension installation in its deployment process.

Create a scoped `Store(new RepositoryContext(...), $authority)`. `Authority`
authenticates the current tenant/session/actor/purpose, checks current permissions
for configure/scan/prepare/accept/stop/dispatch, supplies a qualified UTC clock and
resolves an approved template into an immutable payload reference and snapshot.
Template payload bytes and secrets stay in the host-owned encrypted store. The
schedule pins actor, subject, acting-as and agent principal as well as tenant,
instance, session, purpose and template ref. Current authorization and emergency
policy still apply on every invocation; creating a schedule grants no permanent
execution permission.

Register an explicit `Schedule` version, then call:

```php
$store->register($schedule, $authority->context());
$receipts = (new Scanner($store))->scan($schedule->id);
```

Each version fixes an IANA timezone, daily `HH:MM:SS`, UTC active range
`[startsAt, endsAt)`, overlap (`choose-first`, `choose-last`, `reject`), missing
wall-time (`skip`, `reject`), misfire policy, catchup cap and grace interval. The
active range is bounded to 3660 days and catchup cap to 1..100. Worker default
timezone is irrelevant. A missing wall time is never normalized silently to a
different local time. Overlap selection compares actual UTC candidates.

Misfire policies operate on `(persisted_cursor, scan_through]`:

| Policy | Selected occurrences |
| --- | --- |
| `skip` | Due occurrences within the configured grace interval, including its exact boundary |
| `one` | First due occurrence in the window |
| `latest` | Last due occurrence in the window |
| `catchup` | Last `catchupLimit` due occurrences, in chronological order |

The scanner rejects a `scan_through` later than the host's current clock. It
atomically persists a bounded batch of exact selected timestamps and advances
the cursor under the schedule row lock. Unselected earlier misfires are skipped
according to that policy. Concurrent/retried scanners reuse the active batch;
they cannot choose a fresh backlog slice after a crash. A completed scan retried
with the same boundary returns the same receipts. A later scan handles only the
new interval. Batch planning does not create a model invocation.

Occurrence identity is `(tenant_id, schedule_id, planned_occurrence_at UTC)`.
Timezone/config version and immutable payload hash bind the occurrence content;
they do not create a second identity for the same instant. Equivalent UTC
fraction spellings normalize to the same identity. Same identity/different hash
is a conflict. Version changes preserve old occurrences and receipts, invalidate
unaccepted older batch slots and retain the high-water mark. Stop is persistent
and a version update does not implicitly re-enable it.

`Store::accept`, the concrete `SchedulePort`, uses the existing
`IntakeRepository` transaction and local `IntakeAdmission` hook. Occurrence
dedup, source Turn, Run, inbox and wake outbox commit together; deferred foreign
keys reject an occurrence without its intake. The schedule lock serializes stop
against acceptance. The scanner performs no provider, business-effect or
delivery IO. A failure after metadata planning resumes that exact pending batch;
a failure inside intake rolls back the occurrence and intake together.

The immutable source uses kind `scheduler.occurrence` and a tenant-scoped
`occurrence:<digest>` event/host handle. Retries reuse it and the original input
ref. They never replace the planned timestamp with retry time.

The host explicitly wires `ScheduledHost($ordinaryHost, $store)` into its scoped
`Managed\HostBindings` and makes the same `Store` available to its occurrence
job. The ordinary host authenticates the handle, resolves the stored scheduled
`TrustedInput` with the current context and compiles that input through the
existing runtime. The decorator checks frozen source/payload binding, requires
the bound background handle, and rechecks current schedule permissions before
deployment/disclosure/delivery. Compiled Work must retain the accepted Turn/Run
IDs. A raw alias or a compiled plan for another Run cannot bypass this guard.

```php
$delivery = (new Dispatch($store, $app))->run($occurrenceHandle);
```

`Dispatch` requires the installed `ScheduledHost` with the same Store instance,
then calls the existing `Executor` using `Entry::Background`. Existing profile,
data, deployment, budget, tool, confirmation and disclosure guards remain in
that composition. No second runtime or effect loop exists. Priority is not a
permission and no schedule field can disable these guards. The host owns the
outbox/queue wake transport and calls this adapter explicitly; the adapter does
not automatically enqueue jobs or spend provider budget.

`Store::resolve` returns the accepted occurrence's version/source/payload refs
and Turn/Run IDs under current authorization. Stop forbids new occurrences;
accepted Runs and unknown Actions retain their original identities and remain
inspectable. Stop does not rewrite an owner outcome or undo a committed effect.
Business cancellation/reconciliation uses the existing ordinary APIs. Unknown
outcomes cannot be treated as safe re-execution permission.

Keep Store/Authority/Dispatch and host bindings scoped. Scanner and Dispatch
clear authority caches in `finally`; the host decorator also resets authority
and ordinary host state through the existing managed lifecycle. A reset failure
is fatal; Dispatch poisons the existing worker health. A standalone scanner
caller must retire its worker on `authority_reset_failed`. Production composition
uses the recursive `ProductionBinding` guard, including the nested ordinary host
and Authority. Fault probes are refused in production. Test bindings require the
existing explicit code-bound testing environment.

The owning gate suite is `scheduler`, with a `contract-scripted` combined tier,
an `isolated-postgres` partition and a separate calendar `unit` partition. Exact
case IDs are declared in the gate registry and
`fixtures/platform/v1/scheduler/cases.json`. Source/fixture/config/lock and
case/tier changes alter its manifest hash. The suite executes package behavioral
tests, strict Composer validation, package/core-port PHPStan analysis and fresh
headless/adapter no-dev installations. Required missing/skipped/zero tests and
missing concurrent/SIGKILL/unknown-action proof fail. Focused proof supports
`tests/run.py --filter METHOD` and `tests/calendar.py --filter METHOD`.

Tests use real Laravel, PostgreSQL and existing runtime/confirmation journals
with synthetic host, budget, provider and delivery owners. SIGKILL verifies a
committed occurrence before recovery; it is not a production rollout rehearsal.
No live provider, external owner, production deployment, ProfileBundle publisher
or P7 router is qualified. P5.5 reviewed checks.2 and correction-1 are consumed
with their actual hashes. P5.2 historical RED/single owner correction, P2.4
fractional ManualClock finding and P3.5 audit/reset uncertainty remain unresolved.
Calendar boundary tests use explicit microsecond-qualified UTC instants. D8
excludes classical mutation testing and mutation scores.
