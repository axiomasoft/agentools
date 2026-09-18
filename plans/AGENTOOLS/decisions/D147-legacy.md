---
id: D147
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Schema-remediation использует один канон открытого строкового перечисления: `oneOf` core-`const` + string-fallback, который через `not`/`enum` исключает весь core-набор; A2 исполняет positive/negative значения и markdown↔schema согласование.** Альтернативы `anyOf` и закрытый `enum` отклонены: первая маскирует пересечение, вторая ломает must-ignore/vendor-extension. P3.20 чинит `ResultType`/`StatusChannel`/`TaskStatus`, P3.21 — `EventKind`, P3.23 переиздаёт гейт.

## Почему

`09_idempotency-errors.md` IDE-35…IDE-37; Audit P3 F1–F3; `research/13-p3-red-audit-remediation-2026-08.md` §§2–4. `RAG:— (repo-grounded)`
