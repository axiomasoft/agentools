---
id: D170
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.20 терминально закрывает preflight remediation: Gate B round 3 разрешён только на source anchor `7abfd3e` и manifest `f01bb26b…fd8811e`.** 11 named semantic checks и их адресные mutations становятся частью A3, а не advisory отчётом. **Consequences:** P4 = 20/20 🟢; P5.1 больше не blocked отсутствием фактической remediation, но обязан вынести собственный cold-start verdict.

## Почему

P4.20 Validation; owner directive №4. `RAG:— (repo-grounded)`
