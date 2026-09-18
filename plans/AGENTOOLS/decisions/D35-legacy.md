---
id: D35
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Фаза P2 оркестрацию НЕ объявляет — подтверждено против критерия `plan-protocol` §7, а не по инерции D23.** Критерий «N≥3 items с независимым scope» на множестве items фазы не срабатывает: P2.2/P2.3 читают Deliverables P2.1, P2.4 ссылается на P2.3, P2.5 агрегирует всех. Альтернатива (оркестровать тройку P2.2–P2.4 после закрытия P2.1) рассмотрена и отклонена: три агента пишут в ОДНО рабочее дерево `agentools`, а топология закрытия (item-коммит на item) их сериализует; `isolation: worktree` развёл бы деревья, но потребовал бы трёх независимых установок toolchain и ручного сведения `bakeoff/results/*` обратно — цена выше выигрыша, узкое место фазы — суждение об отказах, а не пропускная способность

## Почему

`plan-protocol` §7 критерий оркестрации; `plan-protocol` §8 «Item close order»; `research/01-target-structure-2026-08.md` §5. `RAG:— (repo-grounded: phases/P2.md — Inputs items P2.2/P2.3 ссылаются на Deliverables P2.1, P2.4 — на P2.3, P2.5 агрегирует все четыре; research/01-target-structure-2026-08.md §5)`
