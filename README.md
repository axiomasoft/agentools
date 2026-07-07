# AgenTools

Protocol-first слой agent + tools + сценарии для бизнес-приложений: спецификация
поверх Model Context Protocol (MCP) плюс два нативных SDK (PHP, Dart), объединённые
общим набором языконезависимых тест-векторов (conformance).

Пакет проектируется как общий договор между двумя контурами продуктовой экосистемы:
Flex (Laravel/CoreX, потребитель PHP SDK через `corex/ai`) и Axioma (Dart/AxiomaErpSdk,
потребитель Dart SDK через `axioma_assistant`/`axioma_mcp`). AgenTools не заменяет
их agent-рантаймы — он даёт единую спеку semantics (side-effect/confirmation,
lifecycle, сценарии-как-данные, аудит, идемпотентность) и conformance-гейт,
которые ни один из контуров по отдельности не покрывает.

Полное обоснование архитектуры, исследование рынка (MCP, LangGraph, laravel/ai,
mcp_dart, LiveKit и др.) и утверждённые решения D1–D14 — в каноне vault:
`plans/AGENTOOLS/00_MASTER_PLAN.md` (репозиторий `academici/brain`). Исполняемый
план разработки (фазы P0–P5, критерии приёмки) — `plans/AGENTOOLS/plan.md` там же.

## Структура репозитория

| Раздел | Назначение |
|:--|:--|
| `spec/` | Нормативная спецификация: датированные ревизии, JSON Schema, глоссарий |
| `fixtures/` | Языконезависимые тест-векторы + golden-файлы — исполняемый договор между SDK |
| `conformance/` | Runner, который гоняет fixtures против CLI-адаптера любого SDK |
| `php/` | PHP SDK (`agentools/sdk`, namespace `AgenTools\`) |
| `dart/` | Dart SDK (`agentools`) |
| `tools/` | Вспомогательные скрипты (spec-линтер и т.п.), stdlib-only |

## Статус

Design-фаза завершена (2026-07-07). Идёт P0 — детализация спецификации.
Приватный репозиторий, лицензия — см. `LICENSE`.
