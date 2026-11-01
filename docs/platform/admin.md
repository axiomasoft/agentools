# Optional read-only admin inspector

`agentools/platform-filament` is a private optional adapter in `extensions/filament`.
It uses Filament 5 (locked 5.11.0), Laravel 12 and PHP >=8.3. The headless Laravel
package has no dependency on Filament. Both AgenTools providers are explicit and
mount no inspector/admin routes. The host owns Filament's panel and transport
registration. No donor source was copied.

Register `AgenToolsPlatform\Laravel\AgenToolsServiceProvider` and
`AgenToolsPlatform\Filament\InspectorServiceProvider` in trusted host code.
Bind `AgenToolsPlatform\Filament\Access` with a **scoped** factory. The host's
existing scoped `Managed\HostBindings` supplies the real persistence and current
authorization/policy ports. The adapter's scoped `Bindings` creates a reader that
calls only `RunRepository::get` and `ActionApplication::status`. It never advances
a run, dispatches an action or writes a business outcome.

Add the plugin to the chosen authenticated host panel explicitly:

```php
return $panel->plugin(new \AgenToolsPlatform\Filament\InspectorPlugin());
```

The panel then exposes the GET page `agentools-inspect?kind=run&target=<id>`
(or `kind=action`). Filament retains ownership of panel login, middleware and
Livewire transport. The page has no navigation entry, Eloquent resource, runtime
mutation action or publication form. Draft/validate/publish/assign/retire UI
belongs to P7.7 after the P7.1 headless publisher exists. No ProfileBundle
publisher or substitute is included here.

`Access::context()` must authenticate the current principal and select the
trusted tenant/session/purpose. Browser/query/Livewire input supplies only the
resource kind and ID. `Access::authorize()` must recheck current actor, tenant,
resource and `inspect` permission, including current revocation and emergency
policy. An existing run/session pin grants no inspect access. Headless session,
actor, definition and policy checks still execute. A run absent from the selected
session returns `not_found`; an absent or inaccessible action returns the
headless `access_denied` without revealing existence.

Every valid target inspection records `Access::record()` with the trusted
context, target, permission and a bounded code. The host owns durable audit
storage. Audit failure prevents disclosure. Authentication failure auditing
belongs to the host authenticator before a trusted context exists. The adapter
checks current authority before and after the read and again after audit. It
does not cache projections in public Livewire properties. Every render uses a
fresh read; a revoked view yields empty fields. Error messages and previous
exceptions are never forwarded.

The run projection contains tenant/run/turn IDs, status, version and fencing
epoch. The action projection adds the existing dated status, nullable owner
outcome, safe next step and code registry definition ID/version/hash. Unknown
owner outcomes stay `unknown`; unobserved outcomes stay null. Null and zero are
rendered separately. Checkpoints, nested diagnostic values, input/output payloads,
result refs, credentials, prompts and tool schemas are excluded by construction.
Blade escapes the displayed values. A forged SQL projection is verified by the
existing headless journal reader; UI status edits cannot manufacture success.

Sensitive diagnostics have a separate, host-only API:

```php
$result = $inspector->diagnostic('run', $runId, ttlSeconds: 30);
// $result->payload is sensitive; $result->expiresAt bounds this access.
```

The host must explicitly bind scoped `Diagnostics`, which resolves only a
resource-bound encrypted reference and enforces its retention. No arbitrary
diagnostic reference is accepted from a browser. The adapter checks the separate
`diagnostics` permission, resource existence through headless reads, a TTL of
1..300 seconds, current permissions before/after IO and audit, and expiry before
release. Audit codes `requested` and `authorized` describe access decisions,
not delivery acknowledgements. The result is never saved or shown by the page.
The host must respect expiry for any downstream retention or transport; these
bytes are not a reusable permission grant. No raw diagnostic route is mounted.

All user-bearing bindings are scoped. HTTP completion, termination, queue and
Octane request/task/tick/error boundaries retire retained inspectors and reset
even partially resolved admin ports. `Access::reset()` and `Diagnostics::reset()`
must clear principal/credentials/permission caches and sensitive handles. A reset
failure attempts both ports, poisons the existing process health and requires
worker retirement. The headless provider retains ownership of database and host
runtime cleanup. Production uses the same recursive `ProductionBinding` guard;
testing implementations require both code-bound `agentools.testing === true`
and the `testing` environment. Settings cannot enable this flag.

The owning gate suite is `filament` with a `contract-scripted` combined tier and
an `isolated-postgres` partition. Its exact case IDs live in the gate registry
and `extensions/filament/tests/cases.json`. Source, fixture, view, config and lock
changes affect the suite dependency hash. The suite executes the real package
tests, strict Composer validation, package PHPStan analysis and two fresh
`--no-dev` source installations (headless without Filament and adapter with
Filament). Missing dependencies/cases, zero tests or required skips fail. Focused
execution supports `tests/run.py --filter METHOD` and `tests/shell.py --filter METHOD`.

Evidence uses synthetic host/audit/diagnostic ports with real Laravel/Filament
PHP components and isolated PG read services. It does not qualify a browser
deployment, production audit/encryption store, external host, live provider or
production rollout. Historical P5.2 RED and its single owner correction remain
preserved; P2.4 fractional ManualClock and P3.5 audit/reset uncertainty are
unresolved. P5.5 reviewed checks.2 and correction-1 remain upstream evidence,
not new execution claims. D8 excludes classical mutation testing and scores.

Framework sources: [Filament panel plugins](https://filamentphp.com/docs/5.x/plugins/panel-plugins),
[custom pages](https://filamentphp.com/docs/5.x/navigation/custom-pages) and
[installation requirements](https://filamentphp.com/docs/5.x/introduction/installation).
