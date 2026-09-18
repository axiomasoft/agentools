---
id: D180
date: 2026-08-05
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**По прямому указанию владельца recovery остаётся внутри AGENTOOLS P6.2: item выполняет узкий corrective re-freeze `2026-08`, затем продолжает reference adapter.** Это исключение supersedes D72/D177 только для exact stimulus `fixtures/2026-08/vectors/l2/clarification-004.json`, его generated manifest, README freeze-note и локального tag action; `clarification-007`, нормативные документы, schemas, `rules.json`, `expect` и `spec_rule_ids` неизменны. Старый canonical tag target сохраняется аннотированным backup-tag, новый target ставится compare-and-swap после полного formal/semantic gate; `git tag -f` и push запрещены. **Consequences:** отдельный recovery-план и `2026-09` не создаются; цена решения — явно задокументированное исключение из immutability frozen corpus, зато P6.2 остаётся тем item'ом, который обнаружил, исправил и проверил дефект; до успешного re-freeze адаптер не продолжается.

## Почему

Owner correction после ошибочного plan-design; `research/23-p6.2-frozen-corpus-contradiction-2026-08.md` §1–§5; corpus driver `tool_result`; D179 tag-provenance precedent. `RAG:— (owner decision + repo-grounded)`
