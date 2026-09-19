---
id: D190
date: 2026-09-19
status: accepted
item: P10.6
items: [P10.6]
supersedes: []
superseded_by: null
---
## Решение

**P10.6 закрыт отчётом `artifacts/P10-applicability-report.md` с `ГЕЙТ P10: PASS`.** Зафиксированы исходы открытых вопросов, обновлённых item'ом (фактический текст — в отчёте и ниже в «Следствия»).

## Почему

Item P10.6 Validation; опора эмпирики P10.3–P10.5 на дереве `fe0612e` / `43500e8`; RAG слой 1 — `findings/P10-offline-rag-2026-09-19.md`. `RAG:✅ 2026-09-19 (perplexity + BFCL primary pages); RAG:— (repo-grounded: research/09 §11; INT-002; airesto-offline-gap)`

## Следствия

- **Q-1:** остаётся **Decision pending (owner product)** — сценарий отпуска **невыразим** (нет HR-домена); P10.6 назвал это в «что НЕ проверялось» (D114 не переоткрывается).
- **Q-11:** остаётся **external coordination** — INT-002 `Exported` выполнен (D189); `Consumed` у AIRESTO/ERP не заводился; гейт P10 не блокируется.
- **Q-17:** **Resolved для фазы P10 → D187** — пересечение пусто; поднятие `declared_level` выше измеренного — owner (D94), в отчёте не заявлялось.
- **Q-20:** **Resolved → D188 (B)** — demo bridge `erp-sdk/packages/agentools_bridge`; отчёт называет демонстрацию, не production rollout.
- **Q-21:** **Resolved → D189 (a)** — `at_action_events` + проекции; P10.4 эмпирика на Drift demo.
- **Q-22:** **owner gate** — пакет INT-002 §7 опубликован; выбор Scenario Runner vs `orchestration/` **не сделан**; named in report R8.
- **Q-23:** **исполнение ветки (3) зафиксировано P10.3** — in-process, без wire MCP 2026-07-28; owner gate на поднятие пина **остаётся** (E23-001 без ответа).
- План готов к **`plan-design AGENTOOLS finish`** (reconciliation); исполнение продолжается **P11**; сквозной `plan-audit` — по протоколу в конце волны, не как обязательный `plan-audit … P10`.
