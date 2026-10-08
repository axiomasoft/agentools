# P5 event-store parity receipt

Exactly-once consumer delivery is a non-guarantee. The store assigns `seq` /
`global_position`; callers never do. Identical suffix replay is idempotent;
a colliding `event_id` never overwrites.

## Supported path wiring

- PHP and Dart `EventStore.append(tenant, action, expectedPrevSeq, UncommittedEvent[])`
  plus keyset `load`. `EventLog.append(ActionEvent)` is not the supported path.
- `JournalAppender` requires an `EventStore`. Event ids are CSPRNG (`bin2hex(random_bytes(16))`
  / `Random.secure()`), not `ev-{action}-{seq}`.
- `AuditEventFactory` mints the same CSPRNG ids. `AuditRedaction` runs before
  `AuditSink` export; exporter exceptions do not roll back a committed append.
- `MetricCardinality` (Persistence, re-exported from PHP `Ops`) rejects tenant/action/event/trace labels.
- `ActionSnapshot` carries `seq` (folded state) + `spec_rev` + optional content hash.
  A mutated hash discards the snapshot; events stay.
- PostgreSQL `GENERATED ALWAYS AS IDENTITY` is the durable `global_position` arbiter.
  SQLite `SqliteEventLog` remains `EventLog` and is insufficient for P5.3/P5.4 close.
- Dart EventStore is synchronous, matching PHP, so journal wiring stays a constructor
  error rather than an omitted Future.

## Evidence

| Case | PHP | Dart |
|------|-----|------|
| 50-way append one winner | `EventStoreCollisionTest`, `PdoPostgresEventStoreTest`, `JournalAppenderStoreTest`, `EventStoreGateTest` | `event_store_collision_test.dart`, `journal_appender_store_test.dart`, `event_store_gate_test.dart` |
| atomic two-event rollback | `EventStoreCollisionTest`, `PdoPostgresEventStoreTest` | `event_store_collision_test.dart` |
| identical replay zero extra rows | `PdoPostgresEventStoreTest` | SQL constants + in-memory replay |
| payload collision no overwrite | `EventStoreCollisionTest`, `PdoPostgresEventStoreTest`, `JournalAppenderStoreTest` | `event_store_collision_test.dart` |
| keyset page abut, no OFFSET | `PdoPostgresEventStoreTest` | `sql_event_store_test.dart` |
| IDENTITY position | `PdoPostgresEventStoreTest` | SQL schema + RETURNING |
| seq-derived id rejected | `EventStoreCollisionTest`, `JournalAppenderStoreTest` | `event_store_collision_test.dart`, `journal_appender_store_test.dart` |
| audit PII / sink failure | `EventStoreCollisionTest`, `JournalAppenderStoreTest`, `AuditRedactionTest` | `event_store_collision_test.dart`, `journal_appender_store_test.dart`, `audit_redaction_test.dart` |
| metric label deny | `EventStoreCollisionTest` | `event_store_collision_test.dart` |
| snapshot hash discard | `EventStoreGateTest` | `event_store_gate_test.dart` |
| JournalAppender 50-way through store | `JournalAppenderStoreTest`, `EventStoreGateTest` | `journal_appender_store_test.dart` |

Parity field/case check from `tools/validate_event_store_v1.py`: `missing=[]`, `extra=[]`, `mismatch=[]` (21 cases).

## Residual owners

- **P6**: shared error registry / spec revision for EventStore codes.
- **P7**: final barrel/PSR-4 removals; in-memory remains testkit/reference-only.
- **P8**: mutation/fuzz/page-size depth beyond the 50-way and keyset abut proofs.
- **P9**: CI PostgreSQL matrix (`ext-pdo_pgsql` / ephemeral PostgreSQL). Dart has no live
  PostgreSQL driver; durable concurrent evidence is PHP PDO plus Dart injected SQL.
