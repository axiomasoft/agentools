---
id: D139
date: 2026-08-03
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.4 разблокируется локальным authoring-профилем bounds: физическая глубина схемы ≤16, число физических подсхем в одном документе ≤256; точная семантика обхода — `research/03-p4-vector-corpus-2026-08.md` §11.** SEP-2106 требует reasonable bounds, но чисел не задаёт; значения AgenTools выбраны с измеренным запасом от максимумов текущей ревизии 4/49 и НЕ выдаются за норму MCP. P4.4 вводит `TLP-33`/`TLP-34`, регенерирует `rules.json` и пишет входные artifact-векторы depth=17/subschemas=257/network `$ref` с каноническим полем `diagnostic_substring`; P4.5 владеет реализацией и исполнением проверок. P4.4 выводится из W1, потому что пишет общий вход P4.7/P4.8. **Consequences:** supersedes D55 в части членства P4.4 в W1; волна остаётся на P4.2/P4.3/P4.7/P4.8, а её скрипт обязан ждать терминальности solo-P4.4 перед остатком. Платим двумя новыми нормативными authoring-правилами и обязанностью P4.5 реализовать их до `ГЕЙТ A3`; публичный провод и runtime-лимиты SDK этим решением не ужимаются.

## Почему

Альтернативы отклонены: implementation-specific порог делает единый artifact-вектор многозначным; только сетевой `$ref` теряет две DoS-пробы `DR-01` §E5/A3; число «по месту» нарушает запрет P4.4 на изобретение лимитов. `RAG:✅ 2026-08-03 (официальный MCP SEP-2106: MUST NOT auto-dereference network `$ref`; SHOULD reasonable bounds без чисел; findings/P4.4-schema-dos-bounds-2026-08-03.md)` · `RAG:— (repo-grounded: research/03-p4-vector-corpus-2026-08.md §11 — замер 4/49 и закрытая семантика обхода; fixtures/2026-08/schema/static-check.json — поле diagnostic_substring)`
