# P4 fencing parity receipt

Exactly-once external effect is a non-guarantee. The store fences the idempotency
row; `native_key` is cooperation with a downstream key, not a wrapper around HTTP.

## Supported path wiring

- PHP and Dart `IdempotencyStore` expose `acquire` / `heartbeat` / `complete(lease)` /
  `fail(lease)` / `cleanup`. Unfenced `resolve` / `complete(request)` are absent.
- `IdempotencyCoordinator` is a required `ToolPort`: store and catalog are constructor
  arguments. High-risk + unknown denies before insert. Takeover + non-retryable
  capability stores `idempotency_ambiguous_effect` without `ToolPort.call`.
- Reference `SliceToolCoordinator` invokes through the coordinator, not `RegistryToolPort.call`.
- `ScenarioRunner` still takes a `ToolPort`; fencing is injection of the coordinator.
- PostgreSQL unique `(tenant_id, tool_name, idempotency_key)` is the durable arbiter.
  SQLite implements the same CAS methods for the reference slice and is not a
  multi-worker close substitute.

## Evidence

| Case | PHP | Dart |
|------|-----|------|
| 50-way acquire one winner | `LeaseFenceTest`, `PdoPostgresIdempotencyStoreTest` | `lease_fence_test.dart` |
| in-flight immediate, no lease | `LeaseFenceTest` | `lease_fence_test.dart` |
| fingerprint reuse | `LeaseFenceTest`, `PdoPostgresIdempotencyStoreTest` | `lease_fence_test.dart` |
| takeover + stale complete/fail/heartbeat | `LeaseFenceTest`, `PdoPostgresIdempotencyStoreTest`, `SqliteIdempotencyStoreTest` | `lease_fence_test.dart` |
| completed error replay / expiry | `LeaseFenceTest`, `PdoPostgresIdempotencyStoreTest` | `lease_fence_test.dart` |
| in-progress survives TTL; completed-only cleanup | `LeaseFenceTest`, `PdoPostgresIdempotencyStoreTest` | `lease_fence_test.dart` |
| two-connection unique arbiter | `PdoPostgresIdempotencyStoreTest` | SQL constants + scripted executor (`sql_idempotency_store_test.dart`) |
| empty RETURNING is lost-lease | `PdoPostgresIdempotencyStoreTest` | `sql_idempotency_store_test.dart` |
| unknown high-risk deny-before-insert | `IdempotencyCoordinatorTest` | `idempotency_coordinator_test.dart` |
| native_key forwards durable key / takeover reinvoke | `IdempotencyCoordinatorTest` | `idempotency_coordinator_test.dart` |
| at-most-once takeover stores ambiguous | `IdempotencyCoordinatorTest`, `FencingGateTest` | `idempotency_coordinator_test.dart`, `fencing_gate_test.dart` |
| read-before-write probe / missing probe | `IdempotencyCoordinatorTest` | `idempotency_coordinator_test.dart` |
| 50 nested coordinator acquires, one invoke | `FencingGateTest` | `fencing_gate_test.dart` |
| stale worker after PostgreSQL takeover | `FencingGateTest` | Dart has no live PostgreSQL driver; parity is SQL CAS + PHP postgres gate |

Parity field/case check from `tools/validate_idempotency_lease_v1.py`: `missing=[]`, extra none, status green (21 cases).

## Residual owners

- **P5**: lease attempt is not ALC epoch; event journal/epoch stays out of this row.
- **P6**: shared error registry / spec revision for the three new codes.
- **P7**: final barrel/PSR-4 removals; in-memory remains testkit/reference-only.
- **P8/P9**: mutation/fuzz depth and CI matrix for `ext-pdo_pgsql` / ephemeral PostgreSQL.
