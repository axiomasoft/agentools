# AGENTOOLS — AgenTools: protocol-first agent+tools пакет (спека + PHP/Dart SDK)

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | AGENTOOLS |
| Title | AgenTools: спека (MCP-профиль + lifecycle/confirmation/scenario/audit/idempotency) + conformance + PHP/Dart SDK |
| Version | 0.1.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | fable (design), исполнение по Routing |
| Last Updated | 2026-07-07 |
| Repository | `~/projects/packages/agentools` (создаётся в P0.1; приватный) |
| Related Packages | corex/ai (Flex, потребитель PHP SDK) · axioma_assistant/axioma_mcp (Axioma, потребитель Dart SDK) · durable-workflow/workflow (опция corex) |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · haiku (LOW) · opus (design/audit/спека) |
| Approval Owner | Dmitry Vostrikov |

## 1. Context

AgenTools (бывш. рабочее имя AgentKit) — универсальный слой agent+tools+сценарии для двух
контуров экосистемы: FlexCRM (Laravel 13 / CoreX, PHP) и AiFina/AiResto (Dart, AxiomaErpSdk).
Сотрудник пишет/наговаривает команду → intent → доменные tools (MCP) → интерактивные
уточнения (варианты ответа) → preview → подтверждение → исполнение → аудит.

Архитектура утверждена пользователем 2026-07-07 (D1–D14 в `00_MASTER_PLAN.md` этой папки):
protocol-first гибрид — языконезависимая спека поверх MCP 2025-11-25 (stateless-ready под
ревизию 2026-07-28) + два тонких нативных SDK. Python/LiveKit-мост отклонён; голос = STT →
тот же текстовый протокол. AgenTools НЕ третий рантайм: corex/ai и axioma_assistant реализуют
его контракты. Имя AgenTools утверждено; всё приватно; PHP-first.

Этот план исполняет фазы P0–P5: спека v0.1-draft → conformance → PHP SDK → Dart SDK →
MVP FlexCRM → перенос AiFina. Research-база: `20_research/R-00..R-07` (проверено RAG
2026-07-07). Дизайн-обоснования: `00_MASTER_PLAN.md` (читать при любых сомнениях в «почему»).

## 2. Execution Rules

- Один item за раз; статус обновляется В ПЛАНЕ (файл фазы + Status Board здесь) до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (plan-protocol §5).
- План разошёлся с кодом/фактами → не импровизировать, эскалировать (plan-protocol §8).
- После закрытия item/фазы — перезаписать `plans/AGENTOOLS/handoff.md` целиком.
- Решения D1–D14 из `00_MASTER_PLAN.md` §4 НЕ пересматривать; расхождение спеки с ADR corex/axioma = дефект спеки → эскалация, не молчаливая правка ADR.
- Внешние факты (версии, API) — только через RAG с URL-источником; без источника — `[UNVERIFIED]`.
- Спека пишется на русском, нормативные ключевые слова — RFC 2119 англ. (MUST/SHOULD/MAY), идентификаторы/имена полей — англ.
- Код: комментарии/тесты-описания по `general:writing-style` (русский, объяснять «почему»).
- Vault-файлы этой папки (00_MASTER_PLAN, 20_research) — источники, НЕ артефакты исполнения; артефакты кода живут в `~/projects/packages/agentools`.

## 3. Routing

| Item(ы) | Отклонение | Почему |
|:--|:--|:--|
| P0.2 | исполнитель ДОЛЖЕН иметь perplexity-web/WebFetch (RAG) | research-item: пины библиотек проверяются по сети |
| P0.3–P0.8, P0.10 | opus/high | нормативный авторинг спеки — архитектурный текст, дешёвой моделью не пишется |
| P1–P4 | дефолт матрицы (sonnet/medium) | — |
| P5.1 | opus/high | сверка двух живых канонов (plans/erp 09 vs спека) + интеграционный контракт INT-003 — архитектурная работа |
| P5.2 | дефолт матрицы (sonnet/medium) | — |
| P5.3 | opus/high И исполнитель ДОЛЖЕН иметь perplexity-web/WebFetch (RAG) | гэп-анализ с внешними фактами (function-calling малых локальных LLM) — проверяются по сети |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Спека v0.1-draft: монорепо, 5+2 нормативных доков, JSON Schema, fixtures | 7/11 | 🟠 Done with deviations |
| P1 | Conformance: adapter-протокол, runner, CI | 0/4 | ⬜ Not started |
| P2 | PHP SDK v0.1 (`agentools/sdk`) — зелёный на fixtures | 0/8 | ⬜ Not started |
| P3 | Dart SDK v0.1 (`agentools`) — зелёный на fixtures | 0/8 | ⬜ Not started |
| P4 | MVP FlexCRM: сценарий «отчёт о проделанной работе» E2E | 0/5 | ⬜ Not started |
| P5 | Перенос AiFina + гэп-анализ AiResto offline | 0/3 | ⬜ Not started |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-07 | PLAN-ID `AGENTOOLS`; папка `plans/AgenTools` переименована в `plans/AGENTOOLS`; дизайн-артефакты (00_MASTER_PLAN.md, 20_research/, 90_inbox/) остаются внутри как источники | канон plan-протокола `plans/<ID>/`; один вход для resume |
| D2 | 2026-07-07 | Пакеты: composer `agentools/sdk` (PHP-namespace `AgenTools\`), pub `agentools`; репо `~/projects/packages/agentools`, приватный, лицензия proprietary («All rights reserved», файл LICENSE) | D13 мастер-плана (всё приватно); слитное написание — D2 мастер-плана |
| D3 | 2026-07-07 | Ревизии спеки датированные: `spec/revisions/2026-07-draft/`; статус draft до зелёного PHP SDK на уровне L1 Core, затем переименование в `2026-XX` (release) | паттерн MCP (R-06); draft-окно позволяет BC-правки без бюрократии |
| D4 | 2026-07-07 | Two-phase confirmation = ОДИН tool: вызов write-tool БЕЗ `confirmation_token` → prepare (preview в `structuredContent`; token + `expires_at` + `preview_digest` в `_meta["agentools/v1"]`); вызов С token → commit. Альтернативы (пара `X`/`X__commit`, generic commit-endpoint) отклонены. _(Запись реконсилирована 2026-07-11 под канон спеки — кросс-аудит M12: ранее ошибочно «token в structuredContent._agentools», чего в спеке нет нигде; канон — TCP-17/CP-9/fixtures)_ | совместимость с любым MCP-клиентом; не раздувает tools/list; токен несёт всю привязку |
| D5 | 2026-07-07 | Canonical JSON = RFC 8785 (JCS); idempotency key = `at1:` + sha256hex(JCS({tenant_id, session_id, step_id, tool, args})); голden-файлы аудита сериализуются JCS | детерминизм между PHP/Dart; версия-префикс `at1:` позволяет сменить формулу |
| D6 | 2026-07-07 | Conformance runner — PHP ≥8.3 CLI без фреймворка; adapter-протокол — JSONL по stdio (`init`/`run`/`event`/`result`) | PHP-first (D14 мастер-плана); нулевая Node-зависимость; JSONL тривиален в обоих SDK |
| D7 | 2026-07-07 | Уровни conformance: L1 Core (tool profile + lifecycle + confirmation + idempotency/errors), L2 Scenario (+сценарии), L3 Full (+elicitation url-mode, compensation, double_confirm). SDK может заявлять уровень (паттерн MCP SDK-тиров) | допускает асимметрию зрелости PHP/Dart без потери interop |
| D8 | 2026-07-07 | В fixtures LLM ТОЛЬКО заскриптован (`llm_stub` в векторе); реальные LLM в conformance запрещены | NFR «simulation без реального LLM»; детерминизм CI |
| D9 | 2026-07-07 | `plans/ACTIVE.md` переключён ASTRAKHAN-CLUSTER → AGENTOOLS | канон режима new; пользователь может вернуть одной правкой |
| D10 | 2026-07-07 | Expression-язык сценариев: только `$ctx.<path>`, `$steps.<id>.<path>`, литералы, `== != && \|\| !`, функция `missing(<path>)`; интерпретатор AST, никакого eval/compile | урок R-12 flex (SEL hardened); двуязычная реализация должна быть тривиальной |
| D11 | 2026-07-07 | Failure routes шага: `on_semantic_fail`/`on_technical_fail` ∈ {abort, retry(n), continue}; goto/циклы запрещены в v1 | D11 мастер-плана; предсказуемость исполнения |
| D12 | 2026-07-07 | Clarification в спеке транспорт-агностична: объект ClarificationRequest (schema/options/defaults/confirm) + биндинги: MCP elicitation (Dart, PHP-сервер mcp/sdk), Reverb-события (PHP UI v1) | R-07 #18: у laravel/mcp elicitation нет; семантика не должна зависеть от транспорта |
| D13 | 2026-07-07 | Хранение состояния сессии — контракт `SessionStore`/`EventLog` (интерфейсы в SDK), референс-реализации: in-memory (тесты/conformance) + SQL-скетч в спеке; продуктовые реализации (тенантские ai_*-таблицы Flex, Drift Axioma) — вне этого репо | D8/D10 мастер-плана: SDK тонкий, персистентность — дело потребителя |
| D14 | 2026-07-07 | Спек-линт P0 — python3 stdlib-only (`tools/spec_lint.py`): парс JSON, структурные проверки, сверка примеров с наличием схем; полная JSON-Schema-валидация — с P1 (PHP-валидатор) | на машинах исполнителей гарантирован только python3 stdlib; pip запрещён |
| D15 | 2026-07-10 | Пины библиотек (R-08): JCS — PHP `mmccook/php-json-canonicalization-scheme` (MIT), Dart — писать самим (нет пакета в экосистеме, не только лицензионно); JSON Schema 2020-12 — PHP `opis/json-schema` (Apache-2.0, полная поддержка драфта), Dart `json_schema` v5.2.2 (лицензия `unknown` на pub.dev — открытый вопрос до P3); ULID — PHP `symfony/uid` (не `robinvdvleuten/ulid`, застыл с 2020), Dart `ulid` 2.0.1 (лицензия не подтверждена RAG — открытый вопрос до P3) | R-08 library-pins: 6 RAG-запросов (perplexity-web), критерии — лицензия совместима с proprietary + поддержка драфта 2020-12/RFC 8785 |
| D16 | 2026-07-11 | P5 детализирована заранее (до закрытия P4) с защитой от устаревания: P5.1 = обязательная re-сверка живого состояния Axioma на момент исполнения (RAG:✅ 2026-07-11 recon: erp-sdk docs-only, кода нет; plans/erp v3 живёт — канон `09_AI_Orchestration_UX` с Tier 0–3/money-exclusion моложе R-00 и не упоминает agentools) + cross-plan gate-таблица в INT-003 (plan-protocol §11); P5.2 стартует только при зелёных preconditions, иначе Blocked. Сценарии P5.2 — из канона `MCP_Tools (AiFina)`; пример «отпуск» из миссии — open-questions Q-1 | скелет P5 предписывал детализацию «после P4»; заблаговременное ТЗ + gate дешевле повторного plan-design — реальность сверяет P5.1; альтернатива «ждать P4» отклонена |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-07 | plan-design/fable | План создан из утверждённого дизайна 00_MASTER_PLAN.md (D1–D14); фазы P0–P4 спроектированы, P5 скелет; ACTIVE переключён на AGENTOOLS (Decision D9) |
| 2026-07-10 | plan-exec/sonnet (RAG perplexity-web, 6 запросов) | P0.2 закрыт 🟢: `20_research/R-08_library-pins.md` — вердикты JCS (PHP mmccook, Dart — писать самим)/JSON Schema 2020-12 (PHP opis, Dart json_schema с открытым вопросом лицензии)/ULID (PHP symfony/uid, Dart ulid 2.0.1). D15 принят. Status Board P0 1/11→2/11 |
| 2026-07-10 | plan-close/sonnet | P0.7 закрыт 🟠: 05_audit-events.md (AU-1..21) + audit-event.json, ревью-фикс AU-4 — см. phases/P0.md P0.7 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.3 закрыт: профиль tool-контракта TCP-1..27 + 2 схемы (side-effect/tool-descriptor) — детали см. phases/P0.md P0.3 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.4 закрыт: жизненный цикл действия LC-1..LC-20 + таблица LC-T1..LC-T24 + 2 схемы — детали см. phases/P0.md P0.4 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.5 закрыт: confirmation-протокол CP-1..CP-31 + 2 схемы, ревью-фиксы CP-ссылок — детали см. phases/P0.md P0.5 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.6 закрыт: 04_scenario-schema.md (SC-1..SC-31) + scenario.json + expression.md (грамматика, пример crm.daily_report@1 валиден) — детали см. phases/P0.md P0.6 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.8 закрыт: 06_idempotency-errors.md (IE-1..IE-16) + error.json/idempotency.json, воспроизводимый ключ at1:99d8a4b3... — детали см. phases/P0.md P0.8 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.9 закрыт: fixture.json + 15 векторов + 4 golden-пары + spec_lint check_fixtures/check_golden_pairs — детали см. phases/P0.md P0.9 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.10 закрыт: 00_overview глоссарий/L1-L3/D7-маппинг, ревью-фикс CP-21 — детали см. phases/P0.md P0.10 Completion Notes |
| 2026-07-10 | plan-close/sonnet | P0.11 закрыт: vault-синк 00_MASTER_PLAN §9/§12 — детали см. phases/P0.md P0.11 Completion Notes. Фаза P0 закрыта 7/11 🟢, все 11 терминальны, 🟠 |
| 2026-07-11 | plan-fix/fable (ночной прогон fable-window, реконсиляция кросс-аудита M12) | Запись D4 приведена к канону спеки: token/expires_at/preview_digest — в `_meta["agentools/v1"]` (TCP-17, CP-9, fixtures), preview — в `structuredContent`; ранее в D4 ошибочно значилось «structuredContent._agentools». Спека не менялась |
| 2026-07-11 | plan-audit/opus (fresh, read-only) | Аудит закрытой фазы P0 → **GREEN** — детали в `phases/P0.md` Phase Handoff. Git-факты (14 коммитов) сходятся, дерево чисто; spec_lint зелёный в обоих режимах; ключ/JCS воспроизведены; Known Deviations честны, перенесены в P1; R-09 снял лицензии Dart. handoff.md ретро-синкнут на P1.1 |
| 2026-07-11 | plan-design/fable | P5 детализирована (D16): P5.1 INT-003 сверка Axioma + gate-таблица, P5.2 адаптеры/сценарии AiFina + векторы product-axioma, P5.3 INT-004 отчёт offline-гэпа AiResto; Routing §3 дополнен (P5.1/P5.3), open-questions.md создан (Q-1..Q-4) — детали в phases/P5.md |
