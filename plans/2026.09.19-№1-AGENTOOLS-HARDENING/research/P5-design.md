# P5 design — atomic event store, audit envelope and observability

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P5-design-rag.md`.

## Design question

How can Agentools close F05 (single caller-assembled `append(ActionEvent)`, silent event-id no-op, unbounded `readFrom`) with storage-assigned atomic batch append, content-aware collision, paginated load and a redacted audit/observability envelope, without merging P3 consume or P4 lease/fencing, replacing the journal with a snapshot, inventing high-cardinality metrics, or rewriting frozen `2026-08`?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| PHP/Dart `EventLog` expose `append(ActionEvent)`, unbounded `readFrom(actionId, fromSeq)`, `lastSeq`. Caller supplies `seq`, `expectedPrevSeq` and `eventId`. | both contracts; F05 | Replace the production port with `EventStore.append(stream, expectedPrevSeq, non-empty UncommittedEvent[])` and paginated `load`. `lastSeq` + later `append` is not an atomic pair. |
| Duplicate `event_id` is a silent no-op **without** payload comparison. Tests cite this as “ALC-7”; spec ALC-7 actually forbids ordering by `ts`. | `InMemoryEventLog`, `SqliteEventLog`, `PersistenceContractsTest` | Identical canonical content → idempotent success. Same id, different hash → typed collision. Partial batch overlap → typed conflict. |
| `JournalAppender` / `AuditEventFactory` mint `eventId = ev-{actionId}-{seq}` and assign `seq` before persist. | both factories | Caller supplies a unique event id **not** derived from seq. Storage assigns `seq` and `global_position`. |
| Envelope is ALC-5 closed: `event_id`, `action_id`, `seq`, `expected_prev_seq`, `ts`, `actor`, `kind`, `event_version`, `spec_rev`, `payload`, plus optional `epoch`/`prev_hash`. | ALC-5; `ActionEvent` | Uncommitted DTO omits storage-assigned fields. Committed `ActionEvent` still carries ALC-5. Tenant is stream identity, not a new envelope field. Trace/causation live in `payload` (AOB-17). |
| Hash input is envelope minus `prev_hash` via existing `Canonicalizer` (RFC 8785). | `JournalHashChain`, ALC-35/36 | Collision digest excludes `seq`, `expected_prev_seq`, `prev_hash`, `global_position`. Chain hash stays ALC-36 on the committed envelope. |
| SQLite `journal_events` PK is `(action_id, event_id)` plus `UNIQUE (action_id, seq)`. No tenant, no global position, no pagination limit, SELECT-then-INSERT. | `001_reference_slice_up.sql` | Production schema is tenant-scoped, IDENTITY global position, keyset pagination. SQLite is upgraded to the same contract or marked insufficient for P5.3 close. |
| Audit events are `audit.*` kinds in the **same** journal; `AuditSink.export` is optional and currently dumps the full document. Absence of a sink must not fail append (AOB-18/25). | AOB-1..16; `AuditEventFactory`; `CollectingAuditSink` | Journal remains SoT. Export is a redacted derived adapter. Secret/PII fixtures are rejected **before** append, not masked after the fact. |
| `ActionSnapshot` / `SessionStateProjection` carry `seq` + `spec_rev`, not the event hash at that seq. Snapshot restore exists; journal is not truncated. | ALC-32..34 | Snapshot provenance adds the content hash of the event at `seq`. Divergence discards the snapshot. Journal is never replaced. |
| `BaggageRefs` already enforces 64 items / 8192 bytes opaque ids. No metric port exists. | AOB-29..31; F13 is P1/P10 | P5 owns metric **cardinality policy** and audit redaction. It does not implement a vendor telemetry SDK. |
| ALC executor `epoch` is already a reducer guard; P4 forbade merging it with the lease token. | ALC-26..28; D9 | P5 may append `epoch_started` in a batch. Epoch ≠ lease token ≠ event_id. |
| Frozen spec has `agentools.conflict.version_conflict` only. No collision/pagination codes. | `09_idempotency-errors.md`; `ToolErrorCode` | P5 names error **intent**. P6 registers codes. Frozen `2026-08` stays byte-identical. |
| Composer/pubspec still have no PostgreSQL driver. Audit SQL/interfaces are sketches. | F14; D8/D9 | Injected PDO/SQL, same as D8/D9. Exact tables are P5.1/P5.3 artifacts. |

## Accepted boundary

P5 introduces one versioned event-store/audit/observability contract consumed by both SDKs:

1. **Stream identity** is `(tenant_id, action_id)`. Empty stream `expected_prev_seq = 0`. Storage assigns consecutive `seq` starting at 1 (ALC-6) and a store-wide `global_position` (`GENERATED ALWAYS AS IDENTITY`). Callers never choose those two numbers.
2. **Uncommitted event** carries `event_id`, `kind`, `event_version`, `spec_rev`, `actor`, `ts`, `payload`, optional `epoch`. It does **not** carry `seq`, `expected_prev_seq`, `prev_hash`, or `global_position`. `event_id` is caller-unique (UUID/ULID ≥ 128 bits), not `ev-{action}-{seq}`.
3. **Atomic batch.** `append` takes a non-empty list (max 64). All events land with consecutive `seq` in one transaction, or none do. Two concurrent appends with the same expected version yield one success and one typed `version_conflict`.
4. **Idempotent retry vs collision.** Under the stream lock, classify **before** INSERT:
   - no existing ids → new append if expected version matches;
   - **all** ids present, same stream, same order, same content hashes, forming the already-committed suffix that started at `expected+1` → success, no new rows (`idempotent_replay`);
   - same id, different content hash → `event_id_collision`;
   - partial overlap, duplicate id inside the batch, or empty batch → typed validation/conflict, never a partial write.
5. **Content hash** is `sha-256:` + SHA-256 of RFC 8785 bytes of the uncommitted envelope (stream identity + uncommitted fields). Storage-assigned columns are excluded so the digest is stable before INSERT.
6. **Pagination.** `load(stream, afterSeq=0, limit=500)` is a keyset: `seq > afterSeq ORDER BY seq ASC LIMIT limit`. Hard max 1000. Page boundaries must not skip events. `OFFSET` is forbidden. `lastSeq` may remain as a convenience but is not required to append.
7. **Audit.** `audit.*` events occupy the same journal and `seq` chain (AOB-1..4). Payload allow-list is AOB-14/16 (identifiers, enums, digests, integers; no raw text/args/tokens/PII). `AuditSink` is optional export of the **redacted** document; exporter failure must not roll back the journal (AOB-18).
8. **Observability.** Metrics use a closed low-cardinality label set (`kind` class, result, conflict class). Tenant/action/event/trace/span ids are logs/spans only. No OpenTelemetry SDK dependency. Span-link rules stay AOB-20..22 (do not hold a span across confirmation wait).
9. **Snapshot.** Optional projection carries `seq`, `spec_rev`, and the content hash of the event at that `seq`. On mismatch with a fold of the journal, the snapshot is discarded. The journal is never truncated (ALC-32..34).
10. **Production adapter** is injected PDO/SQL. In-memory is testkit/reference-only. Frozen `2026-08` is unchanged. Single-event `EventLog.append(ActionEvent)` is absent from the production port; tests/harnesses may wrap a one-event batch.

### Append ordering

```text
1. Reject empty batch, batch > 64, duplicate event_id inside the batch, seq-derived event_id, missing tenant/action.
2. Canonicalize each uncommitted envelope; compute content_hash; reject audit-forbidden fields (AOB-14/16).
3. BEGIN; lock stream header (INSERT header ON CONFLICT DO NOTHING, then SELECT … FOR UPDATE).
4. actual = header.current_seq (0 if empty).
5. Lookup existing rows by (tenant_id, event_id) for the batch ids.
6. Classify:
   a. none exist and expected == actual → INSERT all, seq = actual+1 … actual+n,
      set expected_prev_seq of event i to actual+i-1, assign prev_hash from previous committed/new row,
      update header.current_seq, COMMIT, return appended.
   b. all exist as the exact committed suffix at expected+1 with matching hashes → COMMIT, return idempotent_replay.
   c. any id exists with a different hash → ROLLBACK, event_id_collision.
   d. partial overlap or expected != actual (and not case b) → ROLLBACK, version_conflict or partial-overlap.
7. Unique(tenant, action, seq) and Unique(tenant, event_id) are safety nets, not the classifier.
8. RETURNING supplies assigned seq and global_position. Never hash those columns.
```

P3 consume rows and P4 lease rows stay separate tables. An `epoch_started` event may share a batch with later effect-registry events; that epoch is not a lease token (ALC-28, D9).

### v1 numeric bounds

These are P5-owned until P1.5 execution records them as `RL-*` rows. They are not P8 timing SLOs.

| Bound | Value | Rule |
|:--|:--|:--|
| Default page | 500 | `load` default `limit`. |
| Hard max page | 1000 | Larger `limit` is validation. |
| Min page | 1 | |
| Max batch | 64 | Empty forbidden; 4095 is not adopted. |
| Concurrent append proof | 50 callers, one winner | Same expected version. |
| Event id | ≥ 128 bits CSPRNG UUID/ULID | Opaque; not derived from seq; not logged as a secret, but not a fencing token. |
| Content hash | `sha-256:` + 64 hex | JCS uncommitted envelope; excludes storage-assigned fields. |
| Global position | bigint IDENTITY | Store-wide; unique; not in hash. |
| Metric labels | closed set | No tenant/action/event/trace/user/url. |

### Audit / metric policy (normative for P5.1)

**Audit allow:** `tool_name`, `side_effect`, `verdict`, `limit_name`, `limit_value`, `observed`, `scenario_id`, `outcome`, `step_id`, `tokens_in`, `tokens_out`, `amount_minor`, `currency`, `price_table_version`, opaque ids, digests, closed enums, optional `trace` block of opaque trace/span ids (AOB-17).

**Audit deny:** raw arguments, prompts, tokens or token parts, PII, unbounded text, floating-point money (AOB-14/15/16).

**Metric allow:** `event_kind_class` (`lifecycle`/`audit`/`effect`), `append_result` (`appended`/`idempotent_replay`/`conflict`/`validation`), `conflict_class` (`version`/`collision`/`partial`).

**Metric deny:** `tenant_id`, `action_id`, `event_id`, `trace_id`, `span_id`, `user_id`, raw `kind` strings unbounded by vocabulary, exception messages.

## Alternatives

### A. Keep caller-assembled `ActionEvent` and document single-writer use

Rejected. F05 is the missing atomic batch, storage-assigned seq, collision and pagination. A comment on `InMemoryEventLog` already says production stores must enforce optimistic concurrency atomically; the port does not.

### B. EventId-only idempotence (Kurrent default)

Rejected as the Agentools rule. Kurrent compares EventIds, does not enforce uniqueness, and does not hash payloads. Current PHP/Dart no-op is exactly that gap. Content hash is the F05 detector.

### C. `ExpectedVersion.Any` / disable OCC for “simpler retries”

Rejected. ALC-20/21 require `expected_prev_seq`. Kurrent itself says `Any` does not guarantee idempotence. P5 never exposes `Any`.

### D. Hash including `global_position` or `seq`

Rejected. IDENTITY/seq are assigned at INSERT; a pre-insert digest that includes them cannot be prepared, and retries would never match. ALC-36 hash input remains committed envelope minus `prev_hash` for the chain; collision digest is the uncommitted subset.

### E. Second audit log or snapshot-as-journal

Rejected. AOB-1/ALC-1: one journal. ALC-34: snapshot is a projection. `CollectingAuditSink` stays an optional export, never a read model that replaces `load`.

### F. OpenTelemetry SDK as a runtime dependency; tenant_id as a metric label

Rejected. AOB-25/26 keep vendor names in an adapter. Prometheus/OTel evidence forbids unbounded labels; SDK overflow (`otel.metric.overflow`, default 2000) hides breakdowns. P5 fail-closes the label set. No new Composer/pub telemetry library (D8/D9 style).

### G. Merge event rows into P3 consume or P4 lease tables

Rejected (P3 alternative E, D9). Consume uniqueness is `(tenant, jti)`; lease uniqueness is `(tenant, tool, key)`. Event ordering/collision/pagination is a different arbiter.

### H. Use audit SQL/`EventStore` sketches literally, including UUID-only event_id and 0-based EventStore revisions

Rejected (F14). Sketches inform invariants; P5.1 freezes the real contract with ALC numbering and tenant isolation.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|
| P5.1 | event-store/audit/observability contract, migration, adversarial cases | P1 contracts | One normative owner before code. |
| P5.2 | PHP/Dart EventStore types and in-memory adapter | P5.1 | Batch/collision/pagination can be proved without PostgreSQL. |
| P5.3 | durable PostgreSQL adapter, IDENTITY position, keyset load | P5.1 | Concurrent PG evidence is independent of in-memory types tests once the contract is frozen. |
| P5.4 | JournalAppender/AuditEventFactory wiring, redaction, cardinality, snapshot provenance, gate | P5.2, P5.3 | Integration proves the supported path no longer caller-assigns seq or silently no-ops collisions. |

```text
P1.2/P1.4/P1.5 -> P5.1 -> P5.2
                         -> P5.3 -> P5.4
```

`event-contract` groups P5.1–P5.2 because the in-memory adapter implements the frozen write DTO.  
`event-durable` groups P5.3–P5.4 because adapter, IDENTITY/lock SQL and consumer wiring share the store and PostgreSQL evidence. Both groups are `recommended:`; dependency-safe subsets remain executable after D2's whole-plan design gate opens.

## Stable error intent

- `agentools.conflict.version_conflict` (existing ALC-21; expected ≠ actual and not an exact replay)
- `agentools.conflict.event_id_collision` (same id, different content hash)
- `agentools.conflict.event_batch_partial_overlap` (some but not all ids already committed)
- `agentools.validation.event_batch_empty`
- `agentools.validation.event_batch_limit`
- `agentools.validation.event_id_duplicate_in_batch`
- `agentools.validation.audit_forbidden_field`
- `agentools.budget.event_page_limit`

P6 later puts new codes in the shared registry. Frozen error tables stay byte-identical until then. Logs never contain confirmation tokens, raw arguments, or PII.

## Validation strategy

1. Shared machine-readable manifest drives PHP/Dart append/collision/pagination/audit/metric cases.
2. 50 concurrent appends at one expected version yield exactly one winner and 49 version conflicts; the batch is all-or-nothing.
3. Identical retry (same ids, hashes, expected version) is success without new rows; different payload is collision; partial overlap is conflict.
4. `load` pages abut: last seq of page N equals `afterSeq` of page N+1 minus no gaps.
5. Secret/PII fixtures are rejected before persist; metric-label fixtures fail if they carry tenant/action ids.
6. Snapshot+tail equals full fold; a mutated snapshot is discarded; journal row count is unchanged.
7. Frozen `2026-08` bytes remain unchanged; in-memory evidence cannot close P5.3/P5.4.

## Risks retained by design

- Callers that mint a new event_id on retry will duplicate facts. The contract requires stable ids; the store cannot infer “same intent, new id”.
- Injected Clock skew does not reorder events (`seq` does), but identical `ts` values are allowed and must not be used as order (ALC-7).
- Unique `(tenant_id, event_id)` rejects cross-stream reuse of an id inside a tenant. That is stricter than Kurrent and is intentional.
- Optional `AuditSink` can drop exports (AOB-18). Compliance evidence is the journal, not the sink.
- P8 still owns mutation/fuzz depth, multi-host clock chaos and calibrated page-size benchmarks.
- Single-event `EventLog.append(ActionEvent)` dies on the supported path; frozen harnesses wrap a one-event batch. That is an accepted 0.x private-package migration, not a silent dual API.
