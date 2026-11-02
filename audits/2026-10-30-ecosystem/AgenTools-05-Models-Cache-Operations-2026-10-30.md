---
type: analysis
tags: [agentools, audit, ecosystem, planning-input]
created: 2026-10-30
status: planning-input
source_file: ../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md
source_lines: "2893–3263, 3333–3478, 3573–3778, 5254–5277, 7782–7879, 8163–8571"
---

# AgenTools: модели, кэш, безопасность и эксплуатация

Вход для будущего плана Opus. Выводы диалога сведены без повторов; это не план исполнения и не подтверждение реализации. Общие поправки и зависимости: [Edufy-Ecosystem-Audit-Map-2026-10-30](../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md).

## Model transport и каталог

Текущий Edufy `LlmPort::complete(string,array)` не выражает типизированные messages/parts/evidence/tools/output schema, streaming/cache/cancellation. Добавить typed ModelTransport/ModelRequest/Response через compatibility adapter; публичные старые вызовы не ломать одним изменением.

ModelResponse: display text, tool proposals/structured output, finish reason/refusal, provider request ID, requested/actual model, usage/cache/latency, adapter version, normalized error. Специфичные capabilities выражаются typed options, не наименьшим общим знаменателем. Opaque continuation state — защищённая provider-specific запись с TTL, не переносимая память и не chain of thought.

Каталог: Provider → Account/Project → Deployment → Model revision → CapabilitySnapshot. Поля: endpoint/region/credential ref, verified capabilities (stream/tools/structured/vision/cache/audio), context/output limits, data classes/terms review, quotas/prices, checked_at/expires/contract evidence. Поддержку и степень её проверки лучше хранить раздельно; значения supported/unsupported/unknown/verified из RFC требуют нормализации в схеме.

Router сначала фильтрует legal/data/region, host/subject permissions, capabilities, administrative state/quota и task-specific eval qualification; затем оптимизирует качество/latency/cost. Решение и причины отказов аудируются. Известные role/subject/screen route определять кодом без лишнего LLM.

Разные deployments для classification, book extraction/validation, tutoring, complex reasoning, summaries, methodology, reports и safety. Не вызывать дорогую модель или второе «голосование» на каждый ответ.

## Модельные кандидаты из диалога

**[UNVERIFIED]** Ниже сохранены именно предложения Perplexity, а не проверенные доступные версии или рекомендации к production. В этой работе внешние каталоги/тарифы не перепроверялись.

| Кандидат исходника | Предполагаемая роль | Что проверить до выбора |
|:--|:--|:--|
| YandexGPT Pro 5.1 | Основной RU tutoring/admin/material processing | Точный URI/version, context window, tools/structured/stream/cache, коммерческие условия |
| GigaChat-2-Pro | Независимый RU резерв, extraction/summary | API/account, tool continuation и cache, коммерческий доступ |
| Gemini 3.8 Flash | Разрешённый международный массовый/материальный контур | Существование/slug, доступность API/model, регион, цены/cache; не переносить утверждение о GA без проверки |
| Claude Sonnet 5.5 | Сложные объяснения, методическая проверка, отчёты | Актуальная версия/endpoint, русский eval, обработка данных/цены |
| Claude Sonnet 4.6 | Воспроизводимый сравнительный baseline | Legacy/retirement и доступность; дата 2027-02-17 из исходника не проверена |
| GigaChat-3-Ultra | Упомянутый дополнительный кандидат | Условия freemium физических лиц не доказывают применимость коммерческому backend |

Отдельно перепроверить утверждение о выводе YandexGPT Lite 5 2026-11-15 и о смене рекомендуемых Gemini generations. Список не пиннить в ADR навечно. Первичные locators из диалога: [Yandex models](https://yandex.cloud/ru/docs/ai-studio/concepts/generation/models), [GigaChat selection](https://developers.sber.ru/docs/ru/gigachat/guides/selecting-a-model), [Gemini changelog](https://ai.google.dev/gemini-api/docs/changelog), [Claude models](https://platform.claude.com/docs/en/models/overview). Их наличие в источнике не означает проверки здесь.

## Отключение и fallback

Administrative states: enabled / draining / disabled / quarantined / retired / incident_blocked. Operational health отдельно: healthy / degraded / unavailable / unknown.

Targets: provider, account, deployment, model, region, tenant, task type, STT/TTS/native realtime. Пример: запретить международный deployment для персонального диалога, оставив разрешённый неперсональный ingestion.

- Planned disable → draining; новые runs идут в разрешённый резерв.
- Outage до раскрытия ответа → reroute в пределах policy.
- Ответ уже раскрыт → interruption; не склеивать ответы моделей незаметно.
- Effect outcome unknown → сначала reconcile, потом продолжать.
- STT rollover/reconnect → overlap/dedup; TTS retry — только недоставленная часть.
- Data ban → fail closed, без обхода региона/endpoint. Все модели недоступны → честный non-AI/text режим по доступным функциям.

Технический fallback graph и педагогический qualification graph — разные фильтры. Смена deployment сохраняет bundle/goal/state; invocation identity новая, Actions не повторяются.

## Cache Manager

Разделить artifact/retrieval/context fragments, implicit prefix cache, explicit resource и stateful provider conversation. Provider state/cache не заменяет историю, corpus или переносимую память; cache miss — штатный случай.

Ключ: provider/deployment/model revision/API mode, credential/project scope, region, tenant/security domain, profile/tool/material hashes, serialization version; для персонального cache — subject/retention scope. Не включать случайный Run ID в стабильный prefix.

Хорошие кандидаты: проверенная policy, stable profile/skills/rubrics/tool schemas, разрешённый неперсональный lesson material. Плохие: весь дневник/история ребёнка, изменчивые permissions, случайный большой retrieval, материалы без provider-storage rights.

Adapter contract: supported cache modes, TTL/expiry, create/use/delete/refresh, usage и отказ. При miss пересобрать разрешённый контекст, заново проверить budget, сделать обычный вызов либо отказ по budget; не снимать data policy ради дешёвого fallback.

Окупаемость: `n × B × (input_price − cached_price) > extra_warm_cost + storage_cost`. Стоимость прогрева/summary тоже учитывается. Прогревать востребованные profiles по расписанию и лимиту, удалять неиспользуемые, продлевать полезные.

На revoke: запрет новых uses → local invalidation → provider delete при поддержке → receipt/result → учёт TTL/ограничений удаления. Мгновенное provider erase без API-гарантии не обещать.

**[UNVERIFIED]** Сохранённые нюансы для проверки адаптеров: Gemini implicit/explicit cache, TTL/expire_time и различие APIs (в исходнике Interactions без explicit objects); Yandex automatic prompt caching только у части моделей, не универсальный named cache; Claude prefix/tools-system-messages/breakpoints и TTL 5 минут/1 час; GigaChat managed persistent cache не подтверждён. Текущие документы: [Gemini](https://ai.google.dev/gemini-api/docs/caching/), [Claude](https://platform.claude.com/docs/en/build-with-claude/prompt-caching), [Yandex context](https://yandex.cloud/ru/blog/ai-studio-llm-agentic-api-context). Меньший payload/previous_response_id не доказывают меньший bill или бесплатную память.

## Usage и бюджет

Отдельные nullable поля: uncached input, cache read/write, output, reasoning при наличии, audio input/output units, provider-reported cost, estimate и pricing version. Неизвестное не заменять 0; не суммировать категории дважды. Раздельно anti-abuse request count и стоимость ресурсов.

Runtime хранит технический usage и admission reservation; BudgetPort вызывает host-владельца. Финансового кошелька/ledger в `agt_*` нет. ControLLM — metering, Tariffler — entitlement, Billium/Commerce — деньги. Мосты публикуют один canonical usage fact с dedup/corrections; детали: [ControLLM-AI-Usage-2026-10-30](related/ControLLM-AI-Usage-2026-10-30.md).

## Обязательная managed pipeline

Host authentication → tenant/session access → profile → data/source access → context → deployment policy → budget → model → tool authorization → confirmation/action → disclosure → delivery.

Mandatory guards (tenant/actor/purpose/routing/tool/confirmation/disclosure) нельзя выключить bot settings/plugins. Optional style/retrieval enhancements отделены; observability имеет собственную failure policy. Raw/build/stream/voice/background paths не обходят guards. HTTP routes opt-in, установка библиотеки не публикует admin API.

Проверки всех входов: user input, attachments, retrieved chunks, tool outputs, summary/memory, editable instructions, third-party skills. Injection classifier — дополнение к ACL/trust boundaries. Выход проверяет audience, secrets, fresh rights и запрещённое раскрытие.

DisclosurePolicy выбирает buffered, checked segments или live. Нельзя одновременно гарантировать полный review до раскрытия и немедленный выпуск каждого токена. Для sensitive reports — строгий режим; voice tutor может использовать segment checks.

Workers сбрасывают tenant/actor/credentials/policy/profile/retrieval/payload handles между jobs. Пользовательское состояние в singleton запрещено. Secrets — `secret_ref` через trusted resolver/broker; логи по умолчанию IDs/hashes/codes/durations/model refs/usage/decisions, без prompts, детского текста, книг, inputs/API keys/полных HTTP responses. Диагностический payload требует отдельного доступа, retention и audit.

## Наблюдаемость и эксплуатация

Trace: host request → Turn → Run → Snapshot → Invocation → Action → Effect → Delivery, без чтения ПД оператором. Метрики: runs/queue delay/compiler latency/tokens by section/cache hit+cost/model latency/tools/confirmations/reconciling/unresolved age/outbox lag/delivery failures/budget rejects/policy violations/voice interruption. Дополнительно retrieval precision, summary compression, лишний context, cost per completed goal. SLO после measured baseline.

Целевые команды (не существующие гарантированные API): `agentools:doctor`, `recover`, `reconcile`, `relay`, `inspect-run`, `warm-cache`, `prune`. Doctor проверяет SDK/DB/migrations/bindings/registry/prod fake/secrets/deployments/queue/retention. Recover различает unstarted Run, expired lease, pending Action, lost Delivery, unknown Invocation/Effect; это не retry everything.

Проверять SDK compatibility: generate/stream/structured/tools/continuation/cancel/errors/usage/cache/attachments при заявлении. Отдельно unit/contract/PostgreSQL/E2E/fault matrix и opt-in real provider budget. Деплой новых model/prompt/tool/profile versions через eval, canary, emergency disable и rollback. Отдельный EvalKit не включён в схему; проверки проектируются в AgenTools: [AgenTools-06-Evaluation-Options-2026-10-30](AgenTools-06-Evaluation-Options-2026-10-30.md).
