---
id: D154
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**W2 и terminal evidence-map пересобраны по воспроизводимому контракту: exact 27 round-1 fixture-carriers + 116 FAIL rule ids; headline `32 carriers` сохраняется только как provenance отчёта. Параллелен лишь authoring P4.10–P4.12, после join review/common gates/close идут последовательно `P4.12 → P4.10 → P4.11` под `implementation/high`; P4.12 write-set включает `harness-case` и exact/wrong-code CFH-55 pair.** Supersedes D141/D143 только в части числового acceptance gate `32` и прежней границы workflow close; сама RED-находка, декомпозиция W2 и 116 sole-carrier rules сохраняются. **Consequences:** отказываемся от невосстановимого headline как машинного exact-set и платим отдельными полями schema/checker subjects в manifest; взамен исполнитель не изобретает пять неназванных carriers, low-route не закрывает high-items, а параллельная валидация не читает наполовину изменённый checker.

## Почему

`research/16-p4-w2-dor-reconcile-2026-08.md` §§1–4; `artifacts/P5-gate-b.md` matrix/F1–F6; фактический precheck на `26220f4`. `RAG:— (repo-grounded)`
