---
id: D108
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**INT-001 живёт в `root/contracts/`, а уведомление чужого корня `plans/` идёт строкой `Exported` — граница Q-11 называется, а не обходится.** Дом документа — `root/` (судьба — docs проекта, `plan-protocol` §3), а не `10_decisions/`: его читатель — ЧУЖОЙ план (`FLEX-2`, другой корень `plans/`), и путь обязан пережить архивацию этого плана. Носитель уведомления с нашей стороны — строка `Exported` в `## 7. Contracts` (колонка `Уведомлены` = `—`, потому что честно: кросс-план-линт чужой корень не сверяет), запись в `open-questions.md` и строка в `handoff.md` Open risks; строку `Consumed` заводят авторы плана-потребителя. Ни один item фазы P9 не правит файлы плана `FLEX-2` и канон Flex — включая «заодно поправить устаревший пин `laravel/ai` v0.8.2». Альтернатива «договориться правкой их плана» отклонена прямым запретом D63 и `plan-protocol` §11

## Почему

`RAG:— (repo-grounded: plan.md §5 D63; plan-protocol §3, §11; findings/P9-flex-corex-recon-2026-07-27.md §4; research/08-p9-crm-slice-2026-08.md §11)`
