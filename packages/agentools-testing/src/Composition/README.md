# Scoped consumer doubles

Private, development-only helpers in `AgenToolsPlatform\Testing\Composition`. Depend on
`agentools/platform-testing` through `require-dev`. They implement existing core ports;
there is no Laravel, PDO, SDK or provider dependency in this directory.

`Scope.begin()` requires a host-authenticated `ExecutionContext`, an explicit operation
allowlist and per-operation resource refs. Every operation rechecks current host authority,
tenant and purpose. Model/user/plugin text never grants a resource or resolves a secret.
`FakeAuthority.grant()` and `FakeSecrets.seed()` are trusted test setup operations.

| Double | Preserved behavior |
| --- | --- |
| `FakeTransport` | Core preflight, exact request identity/hash, explicit budget reservation, optional trusted secret resolver, response binding, provider lookup after a lost reply. Stream yields started/terminal events only. No tools or effects execute. |
| `FakeBudget` | Default denied; enqueue outcomes by tenant-scoped operation ref. Unknown/unavailable remain distinct. Admission replay/conflicts, reservation expiry, sent/release exclusion, tenant-bound usage-event settlement. Renewal is explicitly unsupported. |
| `FakeHistory` | Untrusted context blocks only, source/purpose grants and expiry/current authority checks. Implements `ContextAccess`, without claiming a Chatom history implementation. |
| `FakePayloads` | Tenant/session/scope and byte hash checks. Synthetic plaintext only; no encryption or durable storage claim. |
| `FakeOwner` | Default denies effects. An explicit `EffectBoundary` must validate the real committed confirmation/intent/fence before every write or replay. Lookup without a known receipt is unknown. Native operation identity and request hash prevent duplicates. |
| `FakeDelivery` | Core delivery intent identity/hash and exact `ScriptedDisclosure` release check; unknown acknowledgement remains null, not zero. Retry does not count a second delivery. This is a test helper, not a production transport adapter. |

`ExternalState` represents synthetic external-owner facts, including tenant-keyed scripts.
It is separate from per-job client caches: reset never rewrites an external outcome, erases
revocation or manufactures rollback. `Profile.reset()` attempts every client reset and then
clears the scope and authorization cache, even after a failure. Secrets, history refs, payload
refs, model/delivery caches and the effect-boundary handle do not survive the job.

Create one synthetic state for a test, supply a correctly qualified `PreciseClock`, then
construct the doubles and `Profile`. Start each request/job with explicit trusted grants;
call `Profile.reset()` in `finally`. The fixture in `tests/Composition/Fixture.php` is a
core-only consumer example. Never bind these helpers in production. The Laravel provider
requires both the testing environment and explicit testing flag; its production guard rejects
testing payload, secret, budget and transport classes. Production no-dev archives exclude this
package and its reference/testing autoload.

`Failpoints` are one-shot. Arm and assert consumption of `BeforeExternal`/`AfterExternal`
to model an unsent call or lost acknowledgement. The Laravel test consumer keeps the real
kernel and PostgreSQL journals, adds a deferred-commit failure and a failure after committed
intake, and proves recovery without a second model send or owner write. Its `PayloadBridge`
registers real PG technical refs while bytes remain synthetic. `HookDoubles` implements
Availability/Plugin/DataSink with the same scoped history and verifies fragment hashes;
reset clears transient hook caches while authoritative revocations survive. `Boundary` obtains the actual
request from `Postgres\Effects\Ledger`; it does not approve an effect by test flag.

Run focused files/classes:

```
php packages/agentools-testing/tests/Composition/ports.php
python3 packages/agentools-laravel/tests/Testing/run.py --filter ProfileTest
python3 packages/agentools-laravel/tests/Testing/run.py --filter FaultTest
```

The `composition-testing` gate registers 7 portable cases, 8 real PG consumer cases and
4 packaging/static checks. Missing cases, required skips, missing PG isolation and evidence
tier substitution fail closed. The fixture matrix is
`fixtures/platform/v1/composition/testing-profile.json`. Portable evidence is
`contract-scripted`; real PG journal tests are `isolated-postgres`. Neither proves live
provider support, external owner durability, remote integration or production readiness.
No classical mutation testing is used. Existing `ManualClock` fractional precision and
P3.5 audit/reset uncertainties remain unresolved; these tests use `PreciseClock` instead.

All helpers here are target implementations, with no copied Botkit source. The reused
core contracts and lifecycle fixtures retain their original source ownership. Donor API
parity is not a target contract.
