---
id: D9
date: 2026-09-19
status: accepted
item: P4
items: [P4, P4.1, P4.2, P4.3, P4.4]
supersedes: []
superseded_by: null
---
# D9 — Fenced acquire/heartbeat/CAS; capability-gated takeover; no exactly-once

**Actor:** plan-designer/cursor-grok-4.6/high
**Evidence:** RAG:— repository recon in `research/P4-design.md`; RAG:✅ and Perplexity/web cross-check in `research/P4-design-rag.md`

## Решение

P4 заменяет поддерживаемый idempotency port на `acquire` / `heartbeat` / `complete(lease)` /
`fail(lease)`. Наблюдаемые состояния остаются IDE-10 (`new`, `duplicate_completed`,
`concurrent_in_flight`). Внутренний ряд — `in_progress` (lease token + `lease_until` +
`attempt`) или `completed` (сохранённый успех или ошибка). `fail` пишет `completed` с ошибкой;
четвёртого observer-состояния и retry того же ключа после доменного отказа нет (IDE-13).

Каждый acquire/takeover выдаёт новый opaque fencing token и монотонный `attempt`. Heartbeat,
complete и fail — CAS по текущему token; пустой `RETURNING` есть `idempotency_lost_lease`.
Lease duration независим от record retention и confirmation lifetime. Cleanup удаляет только
completed-истёкшие ряды; in-progress молча не удаляется.

Takeover после `lease_until <= now` допускается на уровне store. Повторный вызов инструмента
решает `IdempotencyCoordinator` по `ToolIdempotencyCapability`
(`native_key` / `read_before_write` / `compensatable` / `at_most_once_only` / `unknown`).
Unknown + high-risk (`side_effect` не `read`/`draft`) отклоняется до INSERT. Outbox/inbox и
saga не встраиваются. Lease fence не сливается с ALC epoch (ALC-28; epoch — P5).

Production adapter принимает внедрённый PDO/SQL, как D8; новых Composer/pub runtime-библиотек
нет. In-memory только testkit. Versioned SQL — артефакт пакета; live migration чужой базы
не выполняется. Frozen `spec/revisions/2026-08/**` не изменяется. Unfenced
`complete(request)` на supported path отклоняется.

## Почему

Split `resolve`/`complete` без fence оставляет crash window и stale-writer после takeover (F04,
Kleppmann). Powertools INPROGRESS expiry без generation token не закрывает stale complete.
Четвёртое состояние «упало, повтори тот же ключ» запрещено IDE-13. Event-store epoch не знает
о повторе запроса. Exactly-once для произвольного tool лгать нельзя: нужен downstream
`native_key` или общая транзакция, которых пакет не создаёт.

## Consequences

P4 должен доказать 50 concurrent acquire → один lease, takeover после expiry, lost-lease у
stale worker, детерминированный replay, fingerprint reuse, completed-only cleanup и
fail-closed unknown high-risk. P4 не обещает exactly-once effect, outbox, KMS и новую spec
revision. P6 вносит новые коды в registry; P7 — final exports; P8 — chaos/mutation depth;
P9 — PostgreSQL в CI matrix, если её ещё нет.
