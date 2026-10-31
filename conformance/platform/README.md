# Private platform compatibility harness

`python3 tools/platform_gate.py test --suite testkit-parity` composes bounded
scripted testkit tests, six negative checker tests, and the shared vectors in
`fixtures/platform/v1/compatibility/`. Missing consumers, tests, dependencies,
observations, or required languages fail. The registry has 31 exact case IDs.
No command installs dependencies, calls a provider, or changes protocol baselines.

The PHP and Dart workers call the existing dated Action reducers with hash
chains. The reference path uses the existing SDK-backed ActionJournal, so it is
not a third independent reducer. Shared cases check terminal callbacks, stale
claims, sequence conflicts, cancellation and unresolved effects. The workers
also check portable Action/Usage/Error schemas locally: this is `design-schema`
evidence and does not claim native Dart platform UsageVersion implementation.
Dart compiles a local reference into the published root schema with the same
json_schema primitive used by its SDK validator; that validator's public lookup
accepts complete schema IDs, not fragment IDs.

The applicability matrix keeps all portable contracts mandatory in reference,
PHP and Dart. PHP core host composition and future Laravel composition have
explicit Dart N/A rationales. The absent Laravel adapter stays planned with no
evidence tier. N/A is never converted into a skipped portable test or success.

The harness compares both production semantic exporters with the current
2026-09 manifest using the existing SemanticParity implementation, then checks
2026-08 against HEAD including untracked additions. It writes no generated
manifest, lock or conformance baseline. The outer platform gate owns receipts.
Per-case tiers preserve unit and schema-only results inside this scripted suite.

Focused worker commands accept selected case IDs. For example:

```
php conformance/platform/worker.php php portable.action.terminal-late
php conformance/platform/worker.php reference portable.action.terminal-late
dart --packages=dart/agentools/.dart_tool/package_config.json run conformance/platform/worker.dart portable.action.terminal-late
python3 -B conformance/platform/test_harness.py --report
```

The testkit adds controllable clock/entropy, strictly bound model/owner replies,
current host/policy doubles and buffered disclosure refs. Its repeatable
scheduler and one-shot failpoints model before/after commit, external operation
and disclosure boundaries. Memory commit schedules are contract tests, not real
PostgreSQL transactions or crash recovery. Tests retain real core guards/FSM,
SDK confirmation and SDK idempotency; there is no replacement effect loop.
