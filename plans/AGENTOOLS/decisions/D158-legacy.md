---
id: D158
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.10 реопенится узко: CNF-35/CNF-36 pairs обязаны потребить terminal P4.15 confirmation snapshot, а SEC-11 evidence address исправляется с несуществующего `driver_state/tools` на фактический `given.tools`; ALC-35 presence/absence и AOB-31 length-boundary fixtures сохраняются.** Supersedes D156 только в части утверждения, что cold-start review доказал все 65 stimulus pointers, и уточняет слишком широкий диагноз P4.13: четыре пары имеют реальный input-diff, но pair-level checker ошибочно требует leaf существовать в обеих половинах. P4.13 отдельно чинит `present↔absent`/`length` extraction; добавлять self-asserted runtime payload ради зелёного checker'а запрещено. **Consequences:** платим повторным item-close P4.10 и последующим re-design/resume P4.13; зато не искажаем нормативные ALC/AOB fixtures под дефект проверки и не выдаём token/proposal за доказательство commit-time authorization.

## Почему

`research/19-p4.10-counterfactual-reopen-2026-08.md`; read-only pair diff и `tools/spec_lint.py` 2026-08-04. `RAG:— (repo-grounded)`
