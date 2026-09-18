---
id: D155
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**W2 поддерживает fail-closed resume уже собранного незакоммиченного candidate P4.10–P4.12.** Supersedes D154 только в части fresh-only precheck: `fresh` сохраняет clean-tree/единственный `CFH-55` debt; `resume` допускает только W2-only dirty-set с тремя представленными scopes, чистыми plan files и зелёными joined-tree fixtures/full gates, затем read-only восстанавливает author results и проходит прежние review/fix/close стадии. P4.10–P4.12 → `🟡 In progress`. **Consequences:** платим дополнительным read-only проходом и усложнением carrier; взамен не теряем candidate, не повторяем неидемпотентный authoring и не обходим `Review=full`. Любой чужой path/неполный scope/красный gate блокирует без reset/stash/solo-fallback.

## Почему

`research/16-p4-w2-dor-reconcile-2026-08.md` §5; `workflows/wf-agentools-p4-remediation.js`; фактические `git status`, fixtures/full lint и `git diff --check` 2026-08-04. `RAG:— (repo-grounded)`
