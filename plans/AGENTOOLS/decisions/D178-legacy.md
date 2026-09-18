---
id: D178
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Audit P5 исправляет устаревшую Q-2 premise: remote `origin=https://github.com/axiomasoft/agentools.git` настроен; visibility, branch protection и запуск CI не доказаны, push/tag publish не выполнялись.** **Consequences:** исторические наблюдения D69/P6-recon сохраняют дату 2026-07-27, но активный P6.7 обязан проверять фактический CI run/merge protection либо честно доказать только локальный gate; наличие remote само по себе не означает работающий CI.

## Почему

`git remote -v` на audit tree; P5 Audit F1. `RAG:— (repo-grounded)`
