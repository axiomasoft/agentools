# P5 EventLog.append(ActionEvent) migration inventory

**Status:** P5.1 inventory. Runtime cutover is P5.2–P5.4. Final exports/removal remain P7-owned.

Single-event `EventLog.append(ActionEvent)` is removed from the production interface after
cutover. Dual uncommitted+caller-seq production append is forbidden. Frozen `2026-08`
harnesses may wrap a one-event batch so seq/kind assertions stay valid without new spec
bytes. Seq-derived `event_id` (`ev-{action}-{seq}`) is unsupported.

| Consumer/reachability | Before | P5 disposition | Deferred |
|:--|:--|:--|:--|
| `php/src/Contracts/EventLog.php` | `append(ActionEvent)`, unbounded `readFrom`, `lastSeq` | P5.2 `EventStore.append`/`load`; caller-seq append absent | P7 surface |
| `dart/agentools/lib/src/contracts/event_log.dart` | same single-event port | P5.2 matched EventStore port | P7 surface |
| `php/src/Persistence/InMemoryEventLog.php` | silent event-id no-op; SELECT-then-append race | P5.2 in-memory classify-before-insert; testkit only | P7 packaging |
| `dart/agentools/lib/src/persistence/in_memory_event_log.dart` | same silent no-op | P5.2 matched; testkit only | P7 packaging |
| `php/reference/Persistence/SqliteEventLog.php` | PK `(action_id, event_id)`; unique seq; no tenant; no limit | P5.3 same contract or marked insufficient for durable close | P9/P7 |
| `php/src/Ops/JournalAppender.php` | mints `ev-{action}-{seq}` and assigns `seq` before persist | P5.4 UncommittedEvent + CSPRNG id through EventStore | — |
| `dart/agentools/lib/src/ops/journal_appender.dart` | same seq-derived id | P5.4 matched | — |
| `php/src/Ops/AuditEventFactory.php` | caller seq + `ev-{action}-{seq}`; sink gets full document | P5.4 redacted UncommittedEvent; sink after persist | — |
| `dart/agentools/lib/src/ops/audit_event_factory.dart` | same | P5.4 matched | — |
| `php/src/Action/ActionJournal.php` | in-process append of pre-built `ActionEvent` | P5.4 fold stays; persist through EventStore | frozen harness wrap |
| `dart/agentools/lib/src/journal/action_journal.dart` | same | P5.4 matched | frozen harness |
| `php/src/Action/ActionSnapshot.php` | `seq` + `spec_rev` only | P5.4 add content hash at seq; mismatch discards | — |
| `dart/agentools/lib/src/journal/action_snapshot.dart` | same | P5.4 matched | — |
| `php/src/Action/SessionStateProjection.php` | cached fold without event hash | P5.4 provenance hash or discard-on-mismatch | — |
| `dart/agentools/lib/src/journal/session_state_projection.dart` | same | P5.4 matched | — |
| `php/conformance/SdkConformanceEngine.php` | `journal->append(kind)` caller seq | P5.4 one-event batch wrap for frozen vectors | P6/P7 |
| `php/reference/conformance/SqliteJournalExercise.php` | `SqliteEventLog.append(ActionEvent)` | P5.4 EventStore or marked insufficient | P9/P7 |
| `php/reference/Audit/CollectingAuditSink.php` | exports full document | P5.4 redacted document or nothing | P7 packaging |
| `php/src/Ops/LongOperationCoordinator.php` | `JournalAppender.append` | P5.4 inherits EventStore wiring | — |
| `php/src/Effects/EffectCommitter.php` | `ActionJournal.append(ActionEvent)` | P5.4 persist via store; epoch ≠ event_id | — |
| `php/tests/Persistence/PersistenceContractsTest.php` | silent id no-op as “ALC-7” | P5.2 collision vs identical replay | — |
| `dart/agentools/test/journal/persistence_contracts_test.dart` | same | P5.2 matched | — |
| `php/tests/Reference/Persistence/SqliteEventLogTest.php` | single-event OCC | P5.3 batch/collision/pagination or insufficient | — |
| PHP PSR-4 `EventLog` reachability | candidate surface | EventStore retained; EventLog removed from supported path | P7 surface |
| Dart `agentools.dart` barrel | exports EventLog/in-memory | compatibility retained until P7 | P7 barrel |

Repository-wide production/reference search found no other `EventLog` implementations.
Test fakes must remain in test files. In-memory stores are testkit/reference only.
SQLite is a reference adapter: it may implement the same methods but cannot close
P5.3/P5.4 without PostgreSQL IDENTITY + header-lock evidence.
