---
type: findings
plan: AGENTOOLS
phase: P9
item: P9
created: 2026-09-19
layer: 1
---

# P9 — отвязка от CoreX/Flex и ребазлайн среза (2026-09-19)

Слой 1: решение владельца и проектирование. Опора — D186, INT-002.

## Вердикт

**Принято:** фаза P9 исполняется **только** в репозитории `agentools`. CoreX и FlexCRM — независимые пакеты; прямая связь и ожидание чужих артефактов (AiGateway, `agentic-bridge`) **сняты** с плана.

## Что меняется

| Было (P9.1–P9.2, D184) | Стало (D186, INT-002) |
|:--|:--|
| Дом среза = flexcrm C2-мост | Дом среза = `php/reference/**` в agentools |
| P9.3 blocked на AiGateway | P9.3 = reference adapters, scripted LLM |
| INT-001 — исполняемый контракт | INT-001 deferred; INT-002 активен |
| UI Flex + Reverb | CLI demo consumer |
| БД = тенантские таблицы Flex | SQLite файл среза |
| Ожидание FLEX-2 P6.15 | Нет внешних блокеров P9 |

## Что сохраняется

- P9.1, P9.2 — 🟢 Done (исторические измерения и INT-001 как deferred-спека).
- Семантика восьми портов SDK (P7) — без изменений.
- Conformance corpus `fixtures/2026-08/**` — frozen.
- Цель фазы: доказать сквозной сценарий с записью и отрицательными прогонами — на **референсном** контуре.

## Структура кода (целевая)

```
php/
  reference/
    adapters/          # P9.3: Llm, Tool, Delivery, Clock
    persistence/       # P9.4: SQLite ports
    scenario/          # P9.5: domain tools + runner wiring
    bin/               # P9.6: reference-slice-demo
  tests/reference/
fixtures/product-slice/  # P9.5: product vectors (new)
```

## Следующий шаг исполнения

`run-items: task:plan-run AGENTOOLS P9.3` — reference behavior adapters по INT-002 §2.
