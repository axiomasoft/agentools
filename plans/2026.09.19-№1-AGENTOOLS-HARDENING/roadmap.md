# Roadmap исполнения — 2026.09.19-№1-AGENTOOLS-HARDENING

**Обновлён:** 2026-09-20 · **Состояние дизайна:** P1–P10 detailed; whole-plan design audit pending

## Правило текущей стадии

Это карта design-проходов, а не второй план. P1 детализирована в пять items, P2–P5 —
в четыре, P6 — в пять, P7 — в четыре, P8–P10 — в пять; каждая detailed фаза имеет recommended batches.
Детализация P1–P10 завершена. Следующий проход — независимый whole-plan design audit; только его GREEN receipt
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

## Карта стадии 1 — детализация (история, завершено)

| Фаза | Execution batch после D16 | Design запуск | Design model/effort | Owner gate | Состояние |
|:--|:--|:--|:--|:--|:--|
| P1 | foundation/contracts | plan-design | Codex gpt-5.6-sol/high | — | ✅ detailed: P1.1–P1.5 |
| P2 | confirmation | plan-design | Codex gpt-5.6-sol/high | — | ✅ detailed: P2.1–P2.4 |
| P3 | token-replay | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P3.1–P3.4 |
| P4 | lease-fencing | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P4.1–P4.4 |
| P5 | event-store | plan-design | Cursor Grok 4.6/high | —; SQL artefact, не live owner DB | ✅ detailed: P5.1–P5.4 |
| P6 | semantic-core/conformance-evidence | plan-design | Codex gpt-5.6-sol/high | —; candidate lock, не public tag | ✅ detailed: P6.1–P6.5 |
| P7 | api-boundaries/solo artifact gate | plan-design | Codex gpt-5.6-sol/high | stable removal без shim/deprecation требует новый finite gate | ✅ detailed: P7.1–P7.4 |
| P8 | adversarial-core/solo storage/solo resource | plan-design | Cursor Grok 4.6/high | —; только isolated test PostgreSQL | ✅ detailed: P8.1–P8.5 |
| P9 | ci-hardening/rc-evidence/solo aggregate | plan-design | Codex gpt-5.6-sol/high | —; только local/private RC, publish запрещён | ✅ detailed: P9.1–P9.5 |
| P10 | release-truth/acceptance-evidence/solo | plan-design | Codex gpt-5.6-sol/high | P10 только готовит request; G-RELEASE нужен отдельному public scope | ✅ detailed: P10.1–P10.5 |

После P10: `audit-design: task:plan-audit 2026.09.19-№1-AGENTOOLS-HARDENING design`
на Codex `gpt-5.6-sol/xhigh`; design handoff использует `cold-start-root: design-root-terminal`,
а сам audit сохраняет independent-review boundary.

## Карта будущего исполнения

P1–P10 execution batches уже объявлены в `plan.md`; они остаются неисполнимыми до GREEN
whole-plan design audit.
После объединения линейных P2–P5 карта содержит 19 запусков вместо 23. Внутри одного запуска
модель не меняется; команды перечисляют весь adjacent batch, а `plan-run` закрывает items
последовательно и сохраняет lifecycle каждого item.

| # | Batch | Модель запуска | Provider-neutral команда |
|--:|:--|:--|:--|
| 1 | foundation | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P1.1-P1.3` |
| 2 | contracts | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P1.4-P1.5` |
| 3 | confirmation | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P2.1-P2.4` |
| 4 | token-replay | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P3.1-P3.4` |
| 5 | lease-fencing | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P4.1-P4.4` |
| 6 | event-store | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P5.1-P5.4` |
| 7 | semantic-core | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P6.1-P6.3` |
| 8 | conformance-evidence | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P6.4-P6.5` |
| 9 | api-boundaries | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P7.1-P7.3` |
| 10 | solo:P7.4 | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P7.4` |
| 11 | adversarial-core | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P8.1-P8.3` |
| 12 | solo:P8.4 | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P8.4` |
| 13 | solo:P8.5 | `Grok 4.6/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P8.5` |
| 14 | ci-hardening | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P9.1-P9.2` |
| 15 | rc-evidence | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P9.3-P9.4` |
| 16 | solo:P9.5 | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P9.5` |
| 17 | release-truth | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P10.1-P10.2` |
| 18 | acceptance-evidence | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P10.3-P10.4` |
| 19 | solo:P10.5 | `Composer 2.5/high` | `task:plan-run 2026.09.19-№1-AGENTOOLS-HARDENING P10.5` |

Для `Composer 2.5` текущий Cursor Task mapping уже подтверждён. Для `Grok 4.6` здесь намеренно
не выдуман CLI selector: до запуска #4 нужен verified mapping точного selector в Task
route-catalog либо D16-compatible перевод batch на `Composer 2.5`; `auto` запрещён.

Все batches обязаны соблюдать зависимости:

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
