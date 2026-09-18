---
id: D163
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**F16 синхронизируется без ложного terminal status:** P7.1–P7.10 переводятся `Not started` → `In progress` с anchors `b11cf41`…`e232fc5`; прежний route исполнителей неизвестен и не выдумывается, штатный terminal reconcile остаётся фазе P7.** На текущем дереве PHPUnit 65/65 и PHPStan 0 errors — evidence существования, не write-site closure. **Consequences:** Status Board всё ещё показывает 0 terminal items, но новый executor видит committed partial/implemented scope и не перезаписывает его; позднее потребуется честная реконсиляция каждого item'а.

## Почему

Gate B F16; git history; `research/22-p5-round2-observational-remediation-2026-08.md` §4. `RAG:— (repo-grounded)`
