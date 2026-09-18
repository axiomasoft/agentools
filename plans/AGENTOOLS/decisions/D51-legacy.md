---
id: D51
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**F3 закрывается item'ом P4.6, объединённым с триггером T-2 `DR-02` §7:** ре-замер round-trip must-ignore на ОТКРЫТЫХ схемах + подтверждение, что валидатор слоя 1 CI реально энфорсит `unevaluatedProperties`/`dependentSchemas`. Вердикты фазы P2 не пересматриваются

## Почему

находка F3 аудита фазы P2: 8 из 10 проб несут `additionalProperties: false`, поэтому колонка round-trip на них измеряла закрытость схемы, а не терпимость кандидата; реальное покрытие на открытой схеме — одна проба (p03). Влияние на вердикт нулевое — «DTO руками» в PHP стоит на `crash` p05 и `field-lost` p08. `RAG:— (repo-grounded: phases/P2.md блок `## Audit P2 — 2026-07-27`, находка F3; 10_decisions/DR-02_keywords-codegen-dart-transport.md §7 T-2)`
