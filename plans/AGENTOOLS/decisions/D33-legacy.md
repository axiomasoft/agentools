---
id: D33
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P2.5 поднят до frontier/high** (был `implementation/high`). Item замораживает списки ключевых слов, против которых фаза P3 авторит все схемы ревизии, и несёт ветку отмены решения `DR-01` §J6; ошибка обнаруживается на 14 items фазы P3 и стоит переписывания схем. Альтернатива (оставить implementation) отклонена по Tier policy: «цена семантической ошибки необратима/замораживается»

## Почему

`plan-protocol` §9 Tier policy; `RAG:— (repo-grounded: phases/P3.md — 14 items авторинга против списков `DR-02`; phases/P2.md P2.5 Code Guidance — ветка эскалации §J6)`
