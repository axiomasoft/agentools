---
id: D187
date: 2026-09-19
status: accepted
item: P10.1
items: [P10.1]
supersedes: []
superseded_by: null
---
## Решение

**Q-17: измерение P10.1 зафиксировало L1 126/126, L2 56/56, L3 31/31 (fail=0) и пустое пересечение «правила переноса × красные векторы `ГЕЙТ P8`».** Достаточность зрелости Dart SDK для переноса по механике D109 закрыта для фазы P10; реопен P8 не рекомендуется. Поднятие `declared_level` выше измеренного остаётся owner-вопросом (D94), не блокером items P10.2–P10.6.

## Почему

Item P10.1 (`findings/P10-erp-state-2026-09-19.md` §6) воспроизвёл механику D107/Q-15 на `artifacts/P8-gate.md` и полном наборе правил переноса (SCN/CLR/CNF/ALC/IDE/LOP/SEC/AOB). `RAG:— (repo-grounded: artifacts/P8-gate.md; conformance/reports/dart-levels.md; findings/P10-erp-state-2026-09-19.md §6)`
