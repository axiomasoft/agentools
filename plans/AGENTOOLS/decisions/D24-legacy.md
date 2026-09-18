---
id: D24
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Волны `DR-01` §K названы фазами `Pn`, а не `A0/A1/A2/A3/B`.** Альтернатива (литеральные метки) отклонена фактом инструментария: `plan-lint` парсит только `P\d+`, `plan-exec`/`plan-close`/`plan-audit` — только `P\d+\.\d+` → литеральные фазы были бы нелинтуемы, а команды по ним не работали бы. Метки §K сохранены в заголовках фаз и карте §4

## Почему

`RAG:— (repo-grounded: plan_lint.py:89 ITEM_HEADER_RE, :101 HANDOFF_TITLE_RE, :107 PLAN_EXEC_RE, :151 PHASE_FILENAME_RE)`
