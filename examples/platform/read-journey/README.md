# Headless support read journey

This is a private, runnable **local-host-e2e** example. Model transport, authentication,
admission and destination are explicitly scripted external owners. Persistence,
claims, intake/outbox, Runtime, Actions facade, dated journal and reducer are the
real platform implementations. It performs no live provider request or business debit.

Run a focused example check from the repository root:

```sh
python3 conformance/platform/read-journey/run.py --filter testRunnableExample
```

The test lifecycle verifies a restricted isolated PostgreSQL owner and database
marker, or provisions a disposable labelled Docker PostgreSQL. It never accepts a
development/production database. PHP needs pdo_pgsql and sodium; the existing
PostgreSQL package's Composer dev dependencies must be installed. Missing
prerequisites fail; there is no fake database or skip fallback.

For an already proven isolated database, `php examples/platform/read-journey/run.php`
uses the explicit AGENTOOLS_TEST_PG_DSN/USER/PASSWORD environment variables, creates
one isolated schema, prints a sanitized result, and removes only that schema.

The example's PreflightJournal rechecks disclosure/emergency after the durable
Invocation intent and before entering transport. A known policy denial is returned
as that denial and records fenced, request-hash-bound `not_sent` evidence. Once
permissions return, the same intent/admission can proceed without another intent
or any fabricated unknown/zero usage. Missing evidence or an already unknown
exchange never authorizes this retry. The scripted model consumes the immutable
prepared request and does not perform a fresh host read for its answer.

The first compiled request advertises the current registry intersection before
model IO. Its code-pinned profile permits only `support.ticket.read`. The read is
classified as private disclosure with a finite cost bound, not assumed harmless
because it is a read. The host handler checks current subject ownership, visibility,
revocation, emergency and cancellation before returning a minimal model/user view.
The private owner note remains solely in the host table. Raw owner rows never enter
the prompt, display response or technical metadata.

`ReadContextCompiler` builds a provider-independent plan, then finalizes for the
scripted deployment using a versioned conservative UTF-8 byte bound. Output reserve
covers requested max output. Optional exclusions have reasons; mandatory overflow
refuses with CONTEXT_MANDATORY_OVERFLOW. Source refs, trust/classification, scope,
profile pin, tokenizer/renderer versions and budgets are in an immutable manifest.
This bound is qualified only for the scripted deployment, not Anthropic or another
provider tokenizer. Full prompt bytes are transient in core; this synthetic host
explicitly stores immutable encrypted request/response payloads with ACL and TTL.

Intake commits Turn/Run/outbox before the relay publishes a stable wake signal.
AdvanceRun commits admission and Invocation intent before transport; response,
nullable usage and stable proposal mapping before Action submit. Submit accepts
only. A later claimed job performs one host read through the guarded facade and
dated journal, then a separately admitted final invocation uses the saved minimal
read result. There is no second effect-capable model loop or write tool executor.

Response and delivery intent commit together. Completed Run does not imply delivery.
The single native-key destination bridge records preparation before IO,
checks current visibility and policy, and verifies key/hash binding on status lookup.
Lost ack stays unknown; retry reads the saved response and destination receipt,
without model reconstruction or duplicate destination messages. In this scripted
SQL destination, its accepted-send count commits atomically with the native-key
message. Pre-send authorization denials and process kills consume no send attempt;
the finite actual-send bound remains enforced. This fixture transaction is not a
claim of atomicity across an arbitrary external API.

The SQL fixture in conformance/platform/read-journey/host.sql represents synthetic
external owners only. This is not a production adapter, accounting ledger, Chatom
qualification, full delivery cancellation/purge implementation, or rollout. Usage is
a technical immutable fact/outbox; full UsageEvent export and settlement remain
owner/P9 scope. General profile/session migration and provider fallback are outside
this example; changed immutable plans fail closed. No unlimited admission default
or production testkit autoload is introduced.
