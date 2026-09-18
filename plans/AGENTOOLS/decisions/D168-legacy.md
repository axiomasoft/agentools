---
id: D168
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.18 сохраняет non-target coverage отдельными raw carriers и обновляет производный `coverage.md` в том же item, потому что target carriers становятся single-rule.** Совмещение target с прежними правилами отклонено: exact 13 checker не должен маскироваться агрегирующим positive. **Consequences:** `coverage.md` добавлен в Files P4.18; P4.19 всё равно переиздаёт финальный semantic manifest/A3.

## Почему

Фактический traceability diff после single-rule rewrite; P4.18 Validation full coverage=0. `RAG:— (repo-grounded)`
