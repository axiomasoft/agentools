---
id: D63
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Уведомление потребителей — честная граница, а не декларация:** внутренние потребители замороженной ревизии — фазы P6–P10 этого плана (столбец `Потребители` §7); планы контуров Flex (`05-Projects/01-Products/FlexCRM/plans/`) и Axioma живут в ДРУГИХ корнях `plans/`, поэтому кросс-план-линт их не сверяет — граница фиксируется записью в `open-questions.md` и строкой в `handoff.md` Open risks, а строки `Consumed` заводят те планы, когда появятся. Альтернатива «пометить их уведомлёнными в §7» отклонена: уведомление, которого не было, — ложное утверждение в контрактной таблице

## Почему

`plan-protocol` §11: «A consumer in ANOTHER repo is not checked by the linter — the boundary is named honestly»; прецедент того же раздела: донор поднял замороженный контракт до v2.0.2 при НУЛЕ упоминаний на стороне потребителя. `RAG:— (repo-grounded: plan.md §7 Contracts — колонка `Уведомлены` = «—»; open-questions.md строка Q-11; plan-protocol §11 «граница называется честно»)`
