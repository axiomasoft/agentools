# Ревизия 2026-07-draft

Статус: **draft** — в разработке (фаза P0 плана `plans/AGENTOOLS` в vault `academici/brain`).

## Состав (по мере написания)

| Документ | Назначение | Статус |
|:--|:--|:--|
| `00_overview.md` | Обзор, глоссарий, conformance-уровни, маппинг side_effect↔stake↔Tier | не написан |
| `01_tool-contract-profile.md` | Профиль MCP tool-контракта + two-phase конвенция | не написан |
| `02_action-lifecycle.md` | FSM сессии, event log, чекпоинты, resume | не написан |
| `03_confirmation-protocol.md` | Two-phase prepare→preview→commit, clarification | не написан |
| `04_scenario-schema.md` | Декларативные сценарии, грамматика выражений | не написан |
| `05_audit-events.md` | Таксономия событий, канонизация JCS | не написан |
| `06_idempotency-errors.md` | Формула ключа, dedupe, модель ошибок | не написан |
| `07_conformance-adapter.md` | Протокол runner↔SDK-адаптер (пишется в фазе P1) | не написан |

Схемы — в `schema/*.json` (draft 2020-12, `$id` вида
`https://agentools.dev/schema/2026-07-draft/<file>`).

Валидация целостности ревизии: `python3 ../../../tools/spec_lint.py .` из этой
папки, либо `python3 tools/spec_lint.py spec/revisions/2026-07-draft` из корня
репозитория.
