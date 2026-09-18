---
id: D23
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**`workflows/wf-agentools-drive.js` устарел, запуск запрещён** (скрипт написан под структуру фаз до пересборки: `kindOf()` матчит `P0.*`, ROUTING зеркалит прежний §3). Файл сохранён с баннером-предупреждением, не удалён (история решений). Новый оркестрационный скрипт пишется только той фазой, которая объявит оркестрацию по критерию `plan-protocol` §7 — кандидат P4 (A3)

## Почему

`RAG:— (repo-grounded: workflows/wf-agentools-drive.js:26–36 — ROUTING/kindOf под прежнюю нумерацию)`; волны A/B оркестрацию не объявляют (обоснование — `research/01-target-structure-2026-08.md` §5)
