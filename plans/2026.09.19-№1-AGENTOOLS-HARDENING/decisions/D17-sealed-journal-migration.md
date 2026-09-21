---
id: D17
date: 2026-09-21
status: accepted
item: P0.1
items: [P0.1]
supersedes: []
superseded_by: null
---
# D17 — Digest-bound migration of invalid sealed journal rows

**Actor:** owner
**Evidence:** direct owner instruction on 2026-09-21: «исправь все дефекты в этой сессии»; P0.1 escalation `SEALED_JOURNAL_MIGRATION_AUTHORITY_REQUIRED`; `plan-lint --gate all` 24 ERROR / 0 WARN pre-migration.

## Решение

Разрешить одну точную `journal-migration/v1` для PLAN1. Миграция обязана
сохранить исходные bytes `journal.jsonl`, зафиксировать SHA-256 каждой
изменённой строки и всего preimage/target, а также оставить постоянный
manifest и receipt в `journal-migrations/2026-09-21-p0.1-repair/`.

Допустимы только нейтральный backfill пропущенных sealed-полей и
точное восстановление `terminal-evidence/v1`. Миграция не может менять
`ts`, item identity, command, attempt, result, actor и note; не может трогать product
code, historical `AGENTOOLS`, release verdict или G-RELEASE authority.

## Почему

Обычный append не может сделать невалидную историческую строку валидной,
а D51 exception намеренно разрешает только exact `friction`. Полный preimage и
дигестно-связанный manifest устраняют schema/terminal defects без потери
форензической восстанавливаемости и без общего механизма waiver.

## Consequences

`plan-lint --gate all` обязан стать 0 ERROR / 0 WARN на мигрированном журнале.
Любое расхождение live bytes, manifest, preimage или receipt отклоняет операцию.
Новые journal events по-прежнему только append-only.
