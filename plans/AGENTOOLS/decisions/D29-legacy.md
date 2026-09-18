---
id: D29
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Route-ячейка §3 — голая пара `<класс>/<effort>`, колонка `Items` — точный id или диапазон одной фазы.** Подсказки провайдера (`(sonnet/high)`) и требуемые capabilities вынесены в «Почему»; черновые строки `P3.*`/`P6.*` заменены диапазонами по фактическому числу items. Альтернатива «оставить форму, чинить компилятор» отклонена: компилятор заморожен и общий для флота, а форма — наш дефект

## Почему

`RAG:— (repo-grounded: packages/task/lib/task/task_contract.py:250–261 `_resolve` — `partition("/")`, :46–47 лестницы, :93 + :263–272 матч строки по `Items`; репро и вывод — `findings/P2-bakeoff-recon-2026-07-27.md` §1)`; инцидент `journal.jsonl` 2026-07-26T22:03:20Z (`P2.1` → `blocked`, friction `routing-blocked`) — блокировались ВСЕ items плана, включая готовую P1
