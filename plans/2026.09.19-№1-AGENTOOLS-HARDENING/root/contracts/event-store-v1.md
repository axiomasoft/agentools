# Event store / audit / observability contract v1 — AgenTools (PLAN1)

**Version:** v1
**Status:** P5 executable contract
**Evidence date:** 2026-09-20
**Cases:** `fixtures/hardening/event-store-v1.json`
**Decisions:** D10
**Bounds:** RL-012 (default page 500), RL-013 (hard max 1000), RL-014 (batch max 64), RL-020 (50 concurrent append)

## Supported boundary

P5 replaces the supported journal persistence port `EventLog.append(ActionEvent)` /
unbounded `readFrom` with `EventStore.append(stream, expectedPrevSeq, UncommittedEvent[])`
and paginated `load`. Storage assigns `seq` and `global_position`. Callers never supply
them. Dual uncommitted+caller-seq production append is forbidden.

[RAG:✅] [Kurrent TCP appending](https://docs.kurrent.io/clients/tcp/dotnet/21.2/appending)
one operation appends one or more events atomically; numeric expected version plus
identical EventIds can be idempotent. `ExpectedVersion.Any` does **not** guarantee
idempotence and is not an Agentools API. Agentools strengthens EventId-only match with a
content hash (F05). Frozen `spec/revisions/2026-08/**` stays byte-identical. Exactly-once
consumer delivery is a non-guarantee.

Stream identity is `(tenant_id, action_id)`. Empty stream `expected_prev_seq = 0`; first
committed `seq` is 1 (ALC-6/20). Tenant is stream identity, not a new ALC-5 envelope field.

## Uncommitted vs committed

| Field | UncommittedEvent | Committed `ActionEvent` (ALC-5) |
|:--|:--|:--|
| `event_id` | caller, ≥128-bit CSPRNG UUID/ULID | same |
| `kind`, `event_version`, `spec_rev`, `actor`, `ts`, `payload` | caller | same |
| `epoch` | optional | optional; ALC epoch, **not** a P4 lease token |
| `seq` | absent | storage-assigned, consecutive from 1 |
| `expected_prev_seq` | append argument (stream), not per event | storage-written per row |
| `prev_hash` | absent | ALC-36 chain on committed envelope |
| `global_position` | absent | store-wide `GENERATED ALWAYS AS IDENTITY` |
| `tenant_id` / `action_id` | stream id | `action_id` in envelope; tenant in store key |

`event_id` is not `ev-{action}-{seq}`. Seq-derived ids are validation. Trace / causation /
span ids live in `payload` (AOB-17), never as envelope columns or metric labels.

## Append API

```text
append(stream=(tenant_id, action_id), expected_prev_seq, UncommittedEvent[1..64])
  -> AppendResult { first_seq, last_seq, last_global_position, outcome }
```

`outcome` is `appended` or `idempotent_replay`. There is no `ExpectedVersion.Any`, empty
batch, or single-event `append(ActionEvent)` on the production port. Test helpers may wrap
a one-event batch so frozen `2026-08` vectors still observe seq/kind without new spec bytes.

### Classify-before-INSERT (normative)

```text
1. Reject empty batch, batch > 64, duplicate event_id in the batch, seq-derived event_id,
   missing tenant/action, audit-forbidden payload fields.
2. Canonicalize each uncommitted envelope (stream identity + uncommitted fields).
   content_hash = "sha-256:" + hex(SHA-256(RFC 8785 bytes)).
   Hash exclusions: seq, expected_prev_seq, prev_hash, global_position.
3. BEGIN; lock stream header (INSERT header ON CONFLICT DO NOTHING, then SELECT … FOR UPDATE).
4. actual = header.current_seq (0 if empty).
5. Lookup existing rows by (tenant_id, event_id) for the batch ids.
6. Classify:
   a. none exist and expected == actual → INSERT all, seq = actual+1 … actual+n,
      expected_prev_seq of event i = actual+i-1, prev_hash from previous committed/new row,
      update header.current_seq, COMMIT, return appended.
   b. all exist as the exact committed suffix at expected+1, same order, same hashes
      → COMMIT, return idempotent_replay (zero new rows).
   c. any id exists with a different hash → ROLLBACK, event_id_collision.
   d. partial overlap → ROLLBACK, event_batch_partial_overlap.
   e. expected != actual and not case b → ROLLBACK, version_conflict.
7. Unique(tenant, action, seq) and Unique(tenant, event_id) are safety nets, not the classifier.
8. RETURNING supplies assigned seq and global_position. Never hash those columns.
```

[RAG:✅] PostgreSQL [`INSERT`](https://www.postgresql.org/docs/current/sql-insert.html)
`RETURNING` returns only rows **actually inserted or updated**. `ON CONFLICT DO NOTHING`
does not return the conflicting row and MUST NOT be the collision detector.
[RAG:✅] [Identity columns](https://www.postgresql.org/docs/18/ddl-identity-columns.html)
and [RETURNING](https://www.postgresql.org/docs/18/dml-returning.html): `global_position`
is `GENERATED ALWAYS AS IDENTITY`; callers omit it.
[RAG:✅] [RFC 8785 JCS](https://www.rfc-editor.org/rfc/rfc8785.html) is the collision
digest serialization. P6 still owns the cross-language JCS oracle.

[RAG:✅] [Kurrent Python appending](https://docs.kurrent.io/clients/python/v1.2/appending-events.md)
does not enforce unique EventIds; reuse across streams can record duplicates. Agentools
unique `(tenant_id, event_id)` is intentional and stricter. Retry MUST keep the same ids
**and** the original expected version. A new id on retry duplicates facts; the store cannot
infer “same intent, new id”.

Fifty concurrent appends at one expected version yield exactly one winner and 49
`version_conflict` (RL-020). A two-event batch is all-or-nothing: conflict rolls back
every row of that batch.

Application path MUST NOT DELETE events (ALC-31). P3 consume rows and P4 lease rows stay
separate tables. `epoch_started` may share a batch with later effect-registry events;
that epoch is not a lease token (ALC-28, D9).

## Load / pagination

```text
load(stream, after_seq=0, limit=500) -> EventStreamPage { events, last_seq, has_more }
```

Keyset only: `seq > after_seq ORDER BY seq ASC LIMIT limit`. `OFFSET` is forbidden.
Default 500 (RL-012), min 1, hard max 1000 (RL-013). Larger `limit` is
`agentools.budget.event_page_limit`. Page N+1 `after_seq` equals last `seq` of page N;
pages MUST NOT skip events. `lastSeq` may remain a convenience; it is not required to append
and is not an atomic pair with append.

`readFrom` without a limit is unsupported after cutover (RL-025).

## Audit envelope

`audit.*` kinds occupy the **same** journal and `seq` chain (AOB-1). Journal remains the
source of truth. `AuditSink` is an optional redacted derived export; exporter exceptions
MUST NOT roll back append (AOB-18). Absence of a sink MUST NOT fail append.

Secret/PII fixtures are rejected **before** persist, not masked after write.

**Audit allow:** `tool_name`, `side_effect`, `verdict`, `limit_name`, `limit_value`,
`observed`, `scenario_id`, `outcome`, `step_id`, `tokens_in`, `tokens_out`,
`amount_minor`, `currency`, `price_table_version`, opaque ids, digests, closed enums,
optional `trace` block of opaque trace/span ids (AOB-17).

**Audit deny:** raw arguments, prompts, tokens or token parts, PII, unbounded text,
floating-point money (AOB-14/15/16). Forbidden fields →
`agentools.validation.audit_forbidden_field`.

## Observability

No OpenTelemetry SDK runtime dependency. Span-link rules stay AOB-20..22 (do not hold a
span across confirmation wait).

[RAG:✅] [Prometheus instrumentation](https://prometheus.io/docs/practices/instrumentation/)
each labelset is a time series; unbounded identifiers must not be labels.
[RAG:✅] [OpenTelemetry Metrics SDK](https://opentelemetry.io/docs/specs/otel/metrics/sdk/)
default cardinality limit is a memory guardrail (`otel.metric.overflow`); Agentools
fail-closes the label set instead of relying on overflow.

**Metric allow:** `event_kind_class` (`lifecycle` / `audit` / `effect`),
`append_result` (`appended` / `idempotent_replay` / `conflict` / `validation`),
`conflict_class` (`version` / `collision` / `partial`).

**Metric deny:** `tenant_id`, `action_id`, `event_id`, `trace_id`, `span_id`, `user_id`,
raw unbounded `kind` strings, exception messages. Forbidden labels →
`agentools.validation.metric_label_forbidden`.

## Snapshot provenance

Optional `ActionSnapshot` / session projection carries `seq`, `spec_rev`, and the content
hash of the committed event at that `seq`. Restore is snapshot+tail fold. On hash or
`spec_rev` mismatch the snapshot is discarded and the caller folds the journal; events are
not deleted. The journal is never truncated and never replaced by a snapshot (ALC-32..34).

## Production adapters

Production adapter is injected PDO/SQL (same as D8/D9). In-memory is testkit/reference-only
and MUST NOT be a production constructor default. PHP `suggests` `ext-pdo_pgsql`. Dart does
not add a `postgres` package. Versioned SQL is a package artefact; PLAN1 does not migrate
an owner production database. SQLite may implement the same methods but cannot close
P5.3/P5.4 without PostgreSQL IDENTITY/lock evidence.

Payloads, tokens, and PII are never written to default logs.

## Stable external refusals

Existing `agentools.conflict.version_conflict` remains ALC-21. P5 freezes additional
spellings; P6 later registers them in the shared error catalog. Frozen `2026-08` error
tables stay byte-identical until then.

| Condition | Code | Category | Retryable |
|:--|:--|:--|:--|
| expected ≠ actual and not exact suffix replay (ALC-21) | `agentools.conflict.version_conflict` | conflict | true |
| same `event_id`, different content hash | `agentools.conflict.event_id_collision` | conflict | false |
| some but not all batch ids already committed | `agentools.conflict.event_batch_partial_overlap` | conflict | false |
| empty batch | `agentools.validation.event_batch_empty` | validation | false |
| batch length > 64 | `agentools.validation.event_batch_limit` | validation | false |
| duplicate `event_id` inside the batch | `agentools.validation.event_id_duplicate_in_batch` | validation | false |
| seq-derived `event_id` (`ev-{action}-{seq}`) | `agentools.validation.event_id_seq_derived` | validation | false |
| missing tenant/action | `agentools.validation.event_stream_identity` | validation | false |
| audit payload deny-list hit | `agentools.validation.audit_forbidden_field` | validation | false |
| metric label deny-list hit | `agentools.validation.metric_label_forbidden` | validation | false |
| `load` limit > 1000 or < 1 | `agentools.budget.event_page_limit` | budget | false |

## Source-of-truth and staged ownership

P1 owns guarantees, threat, and resource policy (RL-011..014, RL-020, RL-023, RL-025).
P5 owns EventStore, collision/redaction/cardinality, snapshot provenance, and journal
wiring. P3 consume and P4 lease/fencing stay out of event rows. P6 owns shared schema/error
revision. P7 owns final `EventLog` export removal. P8 owns mutation/fuzz/page-size depth.
P9 owns CI PostgreSQL matrix. In-memory evidence cannot close P5.3/P5.4.
