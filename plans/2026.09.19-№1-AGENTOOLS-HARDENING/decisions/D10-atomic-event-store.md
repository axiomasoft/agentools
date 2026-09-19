---
id: D10
date: 2026-09-19
status: accepted
item: P5
items: [P5, P5.1, P5.2, P5.3, P5.4]
supersedes: []
superseded_by: null
---
# D10 — Storage-assigned atomic batch EventStore; content-hash collision; redacted audit; low-cardinality metrics

**Actor:** plan-designer/cursor-grok-4.6/high
**Evidence:** RAG:— repository recon in `research/P5-design.md`; RAG:✅ and Perplexity/web cross-check in `research/P5-design-rag.md`

## Решение

P5 заменяет поддерживаемый journal port на `EventStore.append(stream, expectedPrevSeq, UncommittedEvent[])`
и paginated `load`. Stream identity — `(tenant_id, action_id)`. Пустой журнал — `expected_prev_seq = 0`;
хранилище назначает непрерывный `seq` с 1 и store-wide `global_position` (`GENERATED ALWAYS AS IDENTITY`).
`ExpectedVersion.Any` не существует. Батч непустой, максимум 64, атомарный: все события или ни одного.

Идемпотентный retry — тот же набор `event_id` в том же порядке с тем же content hash как уже
записанный суффикс при исходном expected version. Тот же `event_id` с другим hash —
`event_id_collision`. Частичное пересечение батча — `event_batch_partial_overlap`, не дописывание.
Content hash — RFC 8785 по uncommitted envelope **без** `seq` / `expected_prev_seq` / `prev_hash` /
`global_position`. `event_id` задаёт вызывающий (UUID/ULID), не `ev-{action}-{seq}`.

`audit.*` остаётся в том же журнале (AOB-1). Export `AuditSink` — optional redacted derived adapter;
отказ экспортёра не откатывает append (AOB-18). Payload allow/deny — AOB-14/16. Метки метрик —
закрытый low-cardinality набор; `tenant_id` / `action_id` / `event_id` / `trace_id` запрещены.
OpenTelemetry SDK в runtime dependencies нет.

Снапшот несёт `seq`, `spec_rev` и content hash события на этом `seq`; при расхождении отбрасывается.
Журнал не усекается (ALC-32..34). ALC epoch не сливается с P4 lease token и не выводится из `event_id`
(ALC-28, D9). Production adapter — injected PDO/SQL, как D8/D9. In-memory только testkit.
Frozen `spec/revisions/2026-08/**` не изменяется. Single-event `EventLog.append(ActionEvent)` на
supported path отклоняется.

## Почему

Caller-assembled seq плюс `lastSeq()`/`append` оставляют гонку; silent event-id no-op скрывает
corruption (F05). Kurrent EventId-only match и `ExpectedVersion.Any` слабее ALC-20/21 и F05.
`ON CONFLICT DO NOTHING RETURNING` не отличает replay от collision. IDENTITY/seq в hash делают
digest неподготовимым. Второй audit-журнал или snapshot-as-SoT ломают AOB-1/ALC-1. Tenant/action
в metric labels — unbounded cardinality (Prometheus/OTel).

## Consequences

P5 должен доказать 50 concurrent append → один победитель, atomic batch, identical replay,
content collision, partial-overlap отказ, keyset pagination без дыр, secret/PII reject,
cardinality policy и snapshot≠journal. P5 не обещает exactly-once доставки потребителю, outbox,
KMS, vendor telemetry SDK и новую spec revision. P6 вносит новые коды в registry; P7 — final
exports `EventLog`; P8 — chaos/mutation/page-size depth; P9 — PostgreSQL в CI matrix, если её ещё нет.
