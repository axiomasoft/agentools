---
id: D26
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Механика traceability «правило ↔ вектор»:** производный индекс `spec/revisions/2026-08/rules.json` (генерируется из markdown, детерминированно; расхождение с закоммиченным — красный CI) + обязательные поля вектора `spec_rule_ids`/`spec_rev` + один линтер, считающий ОБА направления (MUST без вектора — ошибка; осиротевший вектор — ошибка; `Not testable` обязан нести причину). Оговорка честности: линтер видит ссылку, а не содержательность — содержательность проверяет гейт P5

## Почему

`DR-01` §J2, §J3 (`RAG:✅ 2026-07-26 R-11 §10: у modelcontextprotocol/conformance traceability YAML check-ID ↔ пункт SEP; тихий SKIP заменён статусом «Not testable»`)
