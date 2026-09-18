---
id: D179
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Audit P5 F2 исправляет freeze-order bug: README status flip изменил source-tree digest semantic manifest после prefreeze check.** Manifest пересобран только по `source_tree_sha256` (`be19c372…8e97f`), semantic payload exact 51/38+13 и mutations не изменился; source anchor `2333394`. Старый непубликованный tag target сохранён как `spec-2026-08-invalid-pre-audit`, канонический annotated `spec-2026-08` переиздан на `2333394` без push. **Consequences:** post-tag frozen diff 0 и deterministic projection снова проходит; исключение из запрета замены tag принято только потому, что ref локален, внешний publish отсутствует, а исходный объект сохранён recoverable backup-tag.

## Почему

P5 Audit F2; `--gate-b-remediation-check`; tag-object inspection. `RAG:— (repo-grounded)`
