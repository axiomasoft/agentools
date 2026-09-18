---
id: D34
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Гигиена артефактов bakeoff:** песочницы и логи фазы P2 живут под `bakeoff/`, правила игнорирования — во **вложенном** `bakeoff/.gitignore` (создаёт P2.1): `!results/*.log` переисключает логи из-под корневого `*.log`, `php/vendor/` и `*/.dart_tool/` исключаются явно (корневые правила заякорены `/` и `bakeoff/` не покрывают), lock-файлы песочниц **коммитятся** как доказательство прогнанных версий. Корневой `.gitignore` репозитория не правится

## Почему

`RAG:— (repo-grounded: ~/projects/packages/agentools/.gitignore — `*.log`, `/php/vendor/`, `/dart/.dart_tool/`; репро вложенного переисключения с наблюдаемым `git status --short` — `findings/P2-bakeoff-recon-2026-07-27.md` §4)`; без этого `git add` логов — молчаливый no-op, Deliverables не попадают в item-коммит при зелёной локальной Validation
