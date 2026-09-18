---
id: D137
date: 2026-08-03
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Качество пакета — отдельный контракт до SDK и terminal release-gate после срезов.** AzGuard/Chatom дают evidence практик, но не становятся интеграциями/зависимостями AgenTools; stable API требует export + production consumer + behavioural test; public package code/docs не несут phase labels.

## Почему

`findings/R-12-package-quality-reference-2026-08.md`; `research/11-package-quality-and-release-architecture-2026-08.md`. `RAG:— (repo-grounded)`
