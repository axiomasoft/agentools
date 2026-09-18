---
id: D20
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Legacy-лексика MCP запрещена как правило авторинга** (не «не рекомендуется»): в ревизии `2026-08` нет `initialize`, `Mcp-Session-Id`, server-initiated запросов, SSE-resumability, Roots/Sampling/Logging/DCR, HTTP+SSE — только раздел «out of scope by design» с явным «запрещено строить на этом». Проверяется грепом в гейте P5

## Почему

`DR-01` §A3; директива владельца 2026-07-26 «legacy как понятия нет». `RAG:— (repo-grounded: 10_decisions/DR-01_architecture-2026-08.md §A3 — раздел «out of scope by design»; brief/01-refinements.md, блок директивы владельца 2026-07-26)`
