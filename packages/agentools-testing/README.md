# Private platform scaffold

`agentools/platform-core` owns `AgenToolsPlatform\Core\`; this development-only
package owns `AgenToolsPlatform\Testing\`. Neither owns the SDK's `AgenTools\`.
PHP 8.3 is the minimum. Production packages must never require this package;
consumers use `require-dev`. Nothing here implements a runtime loop or guards.

Install each package from its lock, then run its Composer `test` and `analyse`
scripts. The core smoke tests need only core's production autoloader. The testing
smoke tests also exercise the local core dependency. `analyse` runs PHPStan at
maximum level. Package archives exclude tests, fixtures, vendors and test tools.

The CLI is `python3 tools/platform_gate.py` from the repository root:

```
check-spec
check-boundaries
test --suite NAME
pg-test --suite NAME --isolated
e2e --scenario NAME
qualify --offline
acceptance --offline
doctor --offline
qualify --live --provider REGISTERED_ID --max-cost-minor N --currency ISO
```

Every command accepts `--receipt PATH` for a JSON receipt and hashed stdout/stderr
sidecars. The receipt records commands, source revision and dirty digest, input
hashes, toolchain, exact cases, modes, tiers, counts and limitations. Failed
runners retain their output. No command installs dependencies or calls providers.

P2.1 owns only `gate-self`, `core-smoke`, `testing-smoke`, `no-dev-smoke` in the `test` partition.
`gate-self` is tooling evidence, not platform acceptance. `check-boundaries`
inspects actual core PHP tokens, source imports/module direction, production
autoload owners and manifest/lock dependency closures; it is static evidence.
It rejects absent core source, executable IO, unapproved global functions,
framework/SDK/domain imports, dynamic calls and Actions → Runtime dependencies.
It cannot establish that future runtime guard implementations work.

`tests/no-dev/` is a separate Composer consumer fixture. Install it with
`composer install --working-dir=packages/agentools-testing/tests/no-dev --no-dev`;
its `composer test` proves core autoloads while the root dev-only testing
dependency is absent. This fixture must never be installed with dev packages.

Other suite owners extend the explicit `SUITES` registry in
`tools/platform_gate.py` with exact case IDs, dependencies, command, adapter mode
and canonical evidence tier. A runner emits one JSON object with `mode`, `tier`
and `cases: [{"id": "...", "status": "passed"}]`. Nonzero exit, malformed output,
missing/extra/duplicate cases, zero cases and any skip/unknown/failure are red.
Contract and durable partitions of one name remain independent. PG and E2E
partitions require PostgreSQL isolation proof with actual database name
(`agentools_test_*`), owner, verified allow marker and exact executed durable
case IDs. The owning runner must verify isolation before mutating its database.
An isolation flag or synthetic proof in a tooling test never qualifies runtime.

`platform-durability` and `read` in aggregate requirements remain
unimplemented prerequisites. P2.2 provides the actual `platform-spec` suite. `bootstrap-recovery`, knowledge, host and voice
scenarios also remain unregistered until their owning tasks implement them.
All missing suites fail closed; future owners extend aggregate `REQUIRED` sets
when their acceptance scope becomes concrete. P2.2 owns actual platform schemas,
fixtures and the `platform-spec` check. Offline means scripted external owners;
required PostgreSQL stays real. Live qualification is refused until a separate
execution scope and registered provider runner exist. No live or production
readiness is implied by scaffold checks.

P2.4 adds finite scripted external ports, clock/entropy and deterministic
scheduler/failpoints. They do not replace core policy, registry, FSM, schema
validation, SDK confirmation or deduplication. `tests/run.php` composes their
contract tests; the compatibility harness and language applicability are in
`conformance/platform/README.md`. The production no-dev consumer verifies that
both the old reporting helper and new scripted helpers remain unavailable.
