# P4 unfenced complete / IdempotencyStore migration inventory

**Status:** closed-green after P4.2–P4.4 gates. Final exports/removal remain P7-owned.

Unfenced `resolve` / `complete(request)` are removed from the production interface after
cutover. Dual unfenced+fenced production complete is forbidden. Frozen `2026-08` harnesses
may acquire internally so observer states stay three without new spec bytes.

| Consumer/reachability | Before | P4 disposition | Deferred |
|:--|:--|:--|:--|
| `php/src/Contracts/IdempotencyStore.php` | `resolve` + `complete(request)` | P4.2 `acquire`/`heartbeat`/`complete(lease)`/`fail(lease)`/`cleanup` | P7 surface |
| `dart/agentools/lib/src/contracts/idempotency_store.dart` | same unfenced port | P4.2 matched fenced port | P7 surface |
| `php/src/Idempotency/InMemoryDeduplicationStore.php` | unfenced in-memory; deletes expired in-flight | P4.2 CAS by token; completed-only expiry; testkit only | P7 packaging |
| `dart/agentools/lib/src/idempotency/in_memory_deduplication_store.dart` | same | P4.2 matched CAS; testkit only | P7 packaging |
| `php/reference/Persistence/SqliteIdempotencyStore.php` | unfenced SQLite; `deleteExpired` includes in-flight | P4.3 same CAS contract or marked insufficient for durable close | P9/P7 |
| `php/src/Idempotency/DeduplicationResolution.php` | three observer states, no lease | P4.2 `IdempotencyAcquisition` carries lease on `new` | — |
| `dart/agentools/lib/src/idempotency/deduplication_resolution.dart` | same | P4.2 matched acquisition | — |
| `php/src/Idempotency/DeduplicationPolicy.php` | retention/confirmation/retry timers | P4.2 keep; lease duration is a separate acquire clock | — |
| `dart/agentools/lib/src/idempotency/deduplication_policy.dart` | same | P4.2 keep | — |
| `php/src/Scenario/ScenarioRunner.php` | `ToolPort.call` unfenced | P4.4 supported path injects `IdempotencyCoordinator` as the port | frozen 2026-08 harness may wrap internally |
| `dart/agentools/lib/src/scenario/scenario_runner.dart` | same | P4.4 matched | frozen harness |
| `php/reference/Scenario/SliceToolCoordinator.php` | `RegistryToolPort.call` after confirmation | P4.4 mutation/commit through coordinator | P7 packaging |
| `php/reference/Cli/SliceToolCoordinatorFactory.php` | constructs coordinator without store | P4.4 wire store + catalog | P7 packaging |
| `php/tests/Idempotency/InMemoryDeduplicationStoreTest.php` | `resolve`/`complete(request)` | P4.2 acquire/complete(lease); in-progress survives TTL | — |
| `dart/agentools/test/idempotency/in_memory_deduplication_store_test.dart` | same | P4.2 matched | — |
| `php/tests/Reference/Persistence/SqliteIdempotencyStoreTest.php` | unfenced resolve | P4.3 fenced acquire isolation | — |
| `php/src/Error/ToolErrorCode.php` | missing/in_flight/reuse only | P4.2+ add lost_lease/ambiguous/capability_unknown | P6 shared registry |
| `dart/agentools/lib/src/idempotency/idempotency_error_codes.dart` | same three codes | P4.2+ matched spellings | P6 shared registry |
| PHP PSR-4 `IdempotencyStore` reachability | candidate surface | fenced port retained | P7 surface |
| Dart `agentools.dart` barrel | exports store/in-memory | compatibility retained until P7 | P7 barrel |

Repository-wide production/reference search found no other `IdempotencyStore` implementations.
Test fakes must remain in test files. In-memory stores are testkit/reference only.
SQLite is a reference adapter: it may implement the same CAS methods but cannot close
P4.3/P4.4 without PostgreSQL unique-arbiter evidence.
