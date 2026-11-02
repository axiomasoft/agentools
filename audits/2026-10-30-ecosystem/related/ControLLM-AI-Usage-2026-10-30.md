---
type: analysis
tags: [controllm, audit, ecosystem, planning-input]
created: 2026-10-30
status: planning-input
source_file: ../../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md
source_lines: "2207–2224, 2905–2988, 3396–3478, 7259–7276, 7935–7965, 8486–8522"
---

# ControLLM: единый usage-контракт AI-платформы

Вход для будущего плана Opus. Выводы диалога сведены без повторов; это не план исполнения и не подтверждение реализации. Общие поправки и зависимости: [Edufy-Ecosystem-Audit-Map-2026-10-30](../../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md).

## Поправка к диалогу

ControLLM по control tower/D15 — **metering**, не LLM gateway, router, quota enforcement или billing. Фраза Perplexity «потенциальное место gateway» не принимается как граница. Model transport/routing — AgenTools + vendor adapters; entitlement — Tariffler/host, деньги — Commerce/Billium.

Новый `agt_usage_records` — не повод построить параллельный универсальный metering-продукт. Runtime может хранить свои invocation facts/checkpoints, а единый UsageEvent/export/corrections связывает их с ControLLM без двойного учёта.

## Контракт, необходимый новой платформе

Identity: tenant, actor/subject ref по политике, AssistantSession/Turn/Run/Invocation, deployment/model revision, source event/usage version, correlation/trace. Один canonical event имеет stable idempotency identity; повтор доставки не увеличивает расход, correction не переписывает старое событие.

Units/поля: request count, uncached input, cache read/write, output, reasoning если сообщён, audio input/output units, provider-reported amount/currency, estimate и pricing version. Фактически отсутствующее — NULL/unknown, не 0. Не делать двойной подсчёт input+cached подмножеств; mapping зависит от конкретного provider API.

Usage бывает preliminary/final/partial/unknown: streaming cancellation, provider response lost и cache accounting не должны теряться. Fallback — новая Invocation; retries delivery — тот же usage fact. Дубли Prism/laravel-ai hooks исключаются.

## Что проверить

Существующее решение о hook на Prism — исторический baseline, не доказательство того, что он покрывает точную будущую версию SDK и прямые RU transports. Сначала сверить реальные SDK execution paths, затем adapter coverage для PHP и Dart, потоков/ошибок/прерываний и финального usage.

Логи/metrics передают metadata без детского текста/prompts/secrets. Observability outage и невозможность mandatory financial accounting могут иметь разные политики; их выбирает host, не произвольный plugin.

## Вход Opus

Согласовать source of truth и evidence «provider field → normalized unit → canonical event → dedup/correction → consumer». Развести runtime technical record, metering projection, entitlement consumption и денежный settlement. Подготовить contract fixtures для cached input, absent reasoning, audio, cancelled stream, lost response и повторной доставки.
