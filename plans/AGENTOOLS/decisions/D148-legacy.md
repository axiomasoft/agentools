---
id: D148
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Supersedes D5 в части генератора idempotency key: ключ `at1:*` создаёт вызывающий и передаёт как намерение; сервер детерминированно вычисляет только `args_fingerprint` по каноническим аргументам.** JCS и версия-префикс сохраняются. **Consequences:** старые исполнители, читавшие D5 без `IDE-3`/`IDE-6`, должны сменить источник ключа; совместимость с несуществующей реализацией не требуется, но D5 остаётся историческим носителем раннего решения.

## Почему

`03_action-lifecycle.md`/`09_idempotency-errors.md` IDE-3, IDE-6; Audit P3 F6. `RAG:— (repo-grounded)`
