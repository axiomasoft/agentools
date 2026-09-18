---
id: D183
date: 2026-08-05
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P6.2 становится терминальным executable-admission gate, а весь reference adapter переносится в P6.3.** Решение supersedes operational boundaries D180/D181: закрытый correction включает D180 `clarification-004`, удаление неисполнимого self-report carrier `conftoken-001`, protocol 1.1 runner-owned probes, oracle-safe `run.vector`, observation matcher и partition 121+92=213. P6.2 не пишет adapter; P6.3 не меняет frozen corpus/harness и принимает adapter stages core→extension→full. **Consequences:** P6.3 имеет ровно один машинный вход — tagged admission bundle; P6.4 получает ровно `full.json` 213/213; duplicate-stimulus conflict и observation transport проверяются до product implementation, а не посреди неё. Старый tag object сохраняется, CAS/push rules D180 остаются.

## Почему

Прямое требование владельца устранить серийные конфликты и сделать следующий пункт выводом предыдущего; полный repo inventory `research/25-p6-executable-admission-chain-2026-08.md`. `RAG:— (owner direction + repo-grounded)`
