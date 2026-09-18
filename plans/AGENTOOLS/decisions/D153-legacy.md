---
id: D153
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.9 возобновляется с узким расширением Scope: actionable-description `common/tool-read.json` приводится к D145 и синхронно меняется только одно поле в семи дословных carriers; selftest/Dart-control валидируют реальные corpus bytes без in-memory normalization.** Это уточняет исполнительскую границу D142, не меняя driver-state контракт. Отклонено: отдельный item — нет самостоятельного deliverable и он лишь дублирует prerequisite offline evidence P4.9; перенос в P4.12 — W2 ждёт terminal P4.9; правка только common — создаёт common-divergence; подмена строки в памяти — ложный green на несуществующих bytes.

## Почему

`research/12-p3-p4-gate-b-remediation-2026-08.md` §6; `fixtures/2026-08/README.md` §6; эскалация P4.9. `RAG:— (repo-grounded)`
