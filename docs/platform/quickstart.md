# Private platform quickstart

Install the private packages as described in [installation](../installation.md).
The platform is additive to the dated SDK; keep existing SDK consumers on their
documented API. External owners, provider credentials and durable storage must be
supplied and qualified by the host.

## Headless read

From the checkout, follow [the runnable support read example](../../examples/platform/read-journey/README.md).
It uses real runtime and isolated PostgreSQL with scripted external owners. The
example calls `SupportJourney::accept()`, `drive()`, then
`delivery->deliver()`; completion and delivery are separate. Its test command
requires an isolated PostgreSQL or the documented disposable Docker setup. It
does not demonstrate a live provider or a real host integration.

## Direct Action

The independent headless API is
`AgenToolsPlatform\Core\Actions\ActionApplication::submit(ActionSubmission $submission): ActionReceipt`.
Construct `ActionApplication` with an `ActionRegistry`, `ActionPolicy`,
`HostAuthorization`, `InputSchemaValidator` and `ActionLifecycle`; construct
`ActionSubmission` with `ActionRequest`, input JSON, `CanonicalHasher` and
`CanonicalJson`. Supply host-owned implementations and a durable lifecycle.
Submission is acceptance, not proof of a completed effect. Writes still require
current authorization, confirmation and the effect owner's reconciliation contract.
The independent confirmed-action example and its current-guard checks are in
`packages/agentools-testing/tests/Journeys/SecondHostTest.php`.

## Managed Laravel

Explicitly register `AgenToolsPlatform\Laravel\AgenToolsServiceProvider`, bind
scoped host-owned `HostBindings`, and call:

```php
use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, Input};

$receipt = $app->make(Executor::class)->execute(new Input('inbox:123', Entry::Managed));
```

For a direct action use `Entry::DirectAction` with a host-resolved input handle.
See [Laravel composition](laravel.md) for the required bindings, guard sequence,
reset lifecycle and delivery semantics. No public routes or authority are installed
by default. No example authorizes a second effect-capable loop.
