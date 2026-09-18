---
id: D165
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P3.25 пропущен по решению: Task Contract fail-closed вернул `LOOP_BUDGET_EXCEEDED`, а F17 уже полностью разрешён D164/Q-28 на неизменном LOP-26. Обход компилятора запрещён; исполнимая remediation остаётся P4.16–P4.19.** **Consequences:** отдельный evidence-файл P3.25 не создаётся, P3 сохраняет исторический `🟠`; source truth восстанавливается прямой трассировкой D164→Q-28→LOP-26.

## Почему

Pre-mutation result P3.25 `block:LOOP_BUDGET_EXCEEDED`; owner directive не разрешает нарушать machine gate. `RAG:— (repo-grounded)`
