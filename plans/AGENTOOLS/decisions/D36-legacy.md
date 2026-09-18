---
id: D36
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Разделитель пунктов `Validation` — ` · ` (интерпункт), машинно-специфичные пути инструментов — в `Implementation Rules`, не в `Validation`.** Компилятор режет `Validation` по ` · ` в отдельные пункты публичной приёмки; пункт, содержащий внешний путь (абсолютный, `~`, `../`, `tmp/`, URL), помечается `internal`. Слитная `;`-строка с абсолютным путём к `plan-lint.py` давала ОДИН internal-пункт → `pre_final: block:ACCEPTANCE_NOT_PUBLIC` → item не закрывался ни одной командой. Затронуты и починены P1.1, P1.3, P2.5 (P1 — вне фазы захода, но дефект того же класса и делал готовую фазу незакрываемой)

## Почему

`RAG:— (repo-grounded: task_contract.py:385–396 — split по `_INPUTS_SPLIT_RE`, `scope = internal` при внешнем пути; :`pre_final` — `ACCEPTANCE_NOT_PUBLIC` при отсутствии публичного пункта; :49–51 `DETERMINISTIC_PROOF_CLASSES`)`; проверено вызовом `task_contract.evaluate` на всех 8 детализированных items — обе границы `allow`
