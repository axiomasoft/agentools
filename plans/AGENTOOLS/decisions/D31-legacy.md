---
id: D31
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P2.1 понижен до implementation/medium + `Exec = plan-exec`** (был в диапазоне `implementation/high`, `manual`). Корпус проб и шкала вердиктов предписаны целиком (`research/01-…` §3), открытых решений в item'е нет → Tier policy даёт implementation, effort medium. Побочный выигрыш: item проходит ЖЁСТКИЙ routing-гейт `plan-exec`, а не мягкий inherited-plan `plan-run`. Диапазон `P2.1–P2.4` был неоднороден (`plan-protocol` §9 «Гранулярность»): авторинг данных и эмпирическая интерпретация — разные риск-классы

## Почему

`plan-protocol` §9 Tier policy + «Гранулярность»; `RAG:— (repo-grounded: phases/P2.md P2.1 Scope Included — 10 названных проб, закрытое перечисление вердиктов)`
