---
id: D32
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Контрольная точка «DTO руками» добавлена симметрично в PHP (P2.2)** — была только в Dart (P2.3). Без замера цены ручных DTO на стороне PHP пункт (г) решения `DR-02` («роль кодогенерации по каждому языку») по PHP был бы догадкой

## Почему

`RAG:✅ 2026-07-27 (github.com/wol-soft/php-json-schema-model-generator: документирован только Draft 7, автодетект откатывается на Draft 7; 2020-12 как таргет не заявлен)` + `RAG:✅ 2026-07-27 (github.com/swaggest/php-json-schema: Draft 7/6/4)` → исход «в обоих языках DTO руками» вероятен, а не экзотичен; выжимка — `findings/P2-bakeoff-recon-2026-07-27.md` §3
