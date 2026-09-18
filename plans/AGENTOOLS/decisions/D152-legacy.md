---
id: D152
date: 2026-08-04
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Владелец выбрал fix-forward после RED/AUDIT-CAP P3: P3 сдаётся `🟠` с named debt F1/F2, а immediate P4.14 до P4.9 устраняет outer-shape fail-open.** Канон checker'а: exact registry 12 `(schema file, $defs name)`; definition keys ровно `description`/`oneOf`; structural gate получает полный definition, oracle валидирует его же без реконструкции; sibling `maxLength`, `oneOf→allOf` и исчезновение inventory — обязательные negative mutations. Отклонено: четвёртый P3-item (Task Contract даёт `LOOP_BUDGET_EXCEEDED`); эвристическое discovery только по текущему shape; принятие долга без кода. **Consequences:** P3 остаётся исторически `🟠`, P4 получает один solo-item и сдвигает P4.9/W2, зато fix исполним при зелёном Task Contract и не переписывает P3.1–P3.24.

## Почему

Ответ владельца после Audit P3 захода 3: «надо фиксить»; `phases/P3.md` Audit F1/F2; `research/15-p4-open-enum-outer-fix-2026-08.md`. `RAG:— (owner decision + repo-grounded)`
