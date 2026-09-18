---
id: D6
date: 2026-07-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Conformance runner — PHP ≥8.3 CLI без фреймворка; adapter-протокол — JSONL по stdio (`init`/`run`/`event`/`result`)

## Почему

PHP-first (D14 мастер-плана); нулевая Node-зависимость; JSONL тривиален в обоих SDK _(уточнён `DR-01` §J1: последовательность `start → config → run × N → stop`, фикстуры принадлежат раннеру)_
