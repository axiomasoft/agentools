---
id: D156
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.10 заменяет broad behavior-carriers каноном «одно round-1 FAIL-правило — одна negative/positive pair» и exact `rule_evidence`: 65 behavior rules; `longop-002/102` — отдельный F2-retained carrier. `SEC-17`/`AOB-26` передаются P4.12 как static pairs.** Supersedes D154 только в части неявного допущения, что тематически сгруппированная replacement pair может доказать все rule ids исходного carrier; exact 27 source carriers/116 FAIL rows и headline 32 provenance сохраняются. D155 resume сохраняет candidate как вход review/fix, но structural green больше не является семантическим PASS. **Consequences:** платим ростом behavior-корпуса до 65 pairs и двумя static pairs; единственная fix-итерация W2 исполняется пятью file-disjoint shards с детерминированным aggregate и единым re-review. Взамен каждый rule id получает адресный counterfactual, а две authoring-нормы перестают маскироваться runtime absence assertion.

## Почему

`research/17-p4.10-behavior-carrier-dor-2026-08.md`; read-only разбор W2-candidate (`audit-004/104`, `confpolicy-001/101`) и нормативных `SEC-17`/`AOB-26` 2026-08-04. `RAG:— (repo-grounded)`
