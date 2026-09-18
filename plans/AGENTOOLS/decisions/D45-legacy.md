---
id: D45
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Легаси-лексика: запрет с одним огороженным исключением.** Термины D20 запрещены во всех документах ревизии, КРОМЕ блока `<!-- legacy-lexicon-allowed:start/end -->` раздела «Out of scope by design» в `00_overview.md` (ровно один блок на ревизию); линтер вырезает блок перед грепом

## Почему

без исключения гейт фазы («ни одного legacy-термина») противоречил бы требованию D20 назвать запрещённое явно: `DR-01` §A3 — «раздел out of scope by design явно перечисляет … как то, на чём запрещено строить»; `research/02-…` §6. `RAG:— (repo-grounded: 10_decisions/DR-01_architecture-2026-08.md §A3 — «раздел out of scope by design явно перечисляет запрещённое»; research/02-p3-authoring-conventions-2026-08.md §6)`
