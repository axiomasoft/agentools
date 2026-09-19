# Roadmap исполнения — 2026.09.19-№1-AGENTOOLS-HARDENING

**Обновлён:** 2026-09-19 · **Состояние дизайна:** P1–P6 detailed; P7–P10 skeletons

## Правило текущей стадии

Это карта design-проходов, а не второй план. P1 детализирована в пять items, P2–P5 —
в четыре, P6 — в пять; каждая detailed фаза имеет recommended batches. P7–P10 остаются скелетами. Каждая следующая сессия
детализирует ровно одну фазу, добавляет item-level Routing и обновляет handoff.
После P10 выполняется независимый whole-plan design audit; только его GREEN receipt
открывает execution roadmap (D2).

## Граф фаз

```text
P1 ─┬─> P2 ──> P3 ─┐
    ├─> P4 ─────────┤
    └─> P5 ─────────┴─> P6 ─> P7 ─> P8 ─> P9 ─> P10
```

P4 и P5 могут проектироваться после P1 параллельно с P2/P3, но канонический handoff идёт
P1→P10 по порядку для однозначной cold-start навигации. P6 ждёт финальных semantics P2–P5;
P10 принимает весь результат P1–P9.

## Карта стадии 1 — детализация

| Фаза | Batch | Запуск | Модель/effort | Owner gate | Состояние |
|:--|:--|:--|:--|:--|:--|
| P1 | foundation/contracts | plan-design | Codex gpt-5.6-sol/high | — | ✅ detailed: P1.1–P1.5 |
| P2 | confirmation-contract/confirmation-service | plan-design | Codex gpt-5.6-sol/high | — | ✅ detailed: P2.1–P2.4 |
| P3 | token-envelope/durable-replay | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P3.1–P3.4 |
| P4 | lease-contract/lease-durable | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P4.1–P4.4 |
| P5 | event-contract/event-durable | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P5.1–P5.4 |
| P6 | semantic-core/conformance-evidence | plan-design | Codex gpt-5.6-sol/high | —; candidate lock, не public tag | ✅ detailed: P6.1–P6.5 |
| P7 | n/a | plan-design | Codex gpt-5.6-sol/high | breaking API gates определяет detail | ⬜ скелет |
| P8 | n/a | plan-design | Cursor Grok 4.6/high | — | ⬜ скелет |
| P9 | n/a | plan-design | Codex gpt-5.6-sol/high | —; publish запрещён | ⬜ скелет |
| P10 | n/a | plan-design | Codex gpt-5.6-sol/high | G-RELEASE только для publish/tag | ⬜ скелет |

После P10: `audit-design: task:plan-audit 2026.09.19-№1-AGENTOOLS-HARDENING design`
на Codex `gpt-5.6-sol/high`, `cold-start-root: whole-plan-independent-audit`.

## Карта будущего исполнения

P1–P6 execution batches уже объявлены в `plan.md`; они остаются неисполнимыми до GREEN
whole-plan design audit. Батчи P7–P10 будут созданы их item-level детализацией.
Все они обязаны соблюдать зависимости:

| Producer | Consumers | Передаваемый контракт |
|:--|:--|:--|
| P1 | P2–P10 | threat model, guarantees, compatibility/support, resource limits |
| P2 | P3, P6, P8, P10 | safe confirmation and authorization semantics |
| P3 | P6, P8, P9, P10 | durable replay/token/key semantics |
| P4 | P6, P8, P9, P10 | lease/fencing and external-effect guarantee |
| P5 | P6, P8, P9, P10 | event/audit/observability contract |
| P6 | P7–P10 | parity/error/JCS/MCP manifests and receipts |
| P7 | P8–P10 | public API/package/archive boundary |
| P8 | P9–P10 | adversarial gates and resource receipts |
| P9 | P10 | verified candidate artifacts and supply-chain evidence |

## Гейты владельца

| Где | Что утверждает | Блокирует |
|:--|:--|:--|
| P10 / G-RELEASE | license/legal identity, exact registries, artifact digests, tag/version и credentials path | только public upload/tag/release |
| Детализируемая P5 | production DDL/data migration, если появятся необратимые live migrations | соответствующие migration items |
| Детализируемая P7 | intentional breaking API removal, если deprecation невозможно | соответствующие breaking items |
