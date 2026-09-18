---
id: D157
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Добавлен P4.15: до W2 расширить только закрытый driver-only contract typed inputs для policy rules (verdict/scope), confirmation proposal/token digest/fingerprint и audit-export boundary/attributes.** Supersedes D155/D156 только в предположении, что P4.9 уже выражает все stimuli P4.10: candidate сохраняется, но W2 не стартует до терминального P4.15. **Consequences:** платим одним source+bookkeeping циклом и переносом precondition carrier; взамен distinguishing pairs не используют `journal.payload` или произвольные objects как скрытые stubs, а resume снова имеет ровно W2-only dirty set.

## Почему

`research/18-p4-driver-contract-remediation-2026-08.md`; `fixtures/2026-08/schema/vector.json`; read-only review W2 2026-08-04. `RAG:— (repo-grounded)`
