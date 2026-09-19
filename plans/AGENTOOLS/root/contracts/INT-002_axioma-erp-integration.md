# INT-002 — интеграция AgenTools Dart SDK в контур ERP Axioma

**Статус:** draft (публикуется решением D189; исполняется P10.3–P10.5)  
**Версия:** 1.0.0  
**Дата:** 2026-09-19  
**План:** AGENTOOLS P10 · **Дом кода:** ветка **(B)** тонкий мост в `~/projects/packages/erp-sdk` (Q-20 → D188 accepted)  
**Норматив агентной поверхности:** `ADR-004` / `ADR-010` / `ADR-012` / фаза `09_AI_Orchestration_UX` (чужой SSOT; этот документ — **не** замена)  
**Норматив протокола:** ревизия `spec/revisions/2026-08/` (frozen)

> Минимальная поверхность переноса (`DR-01` §E6). Дельты канона Axioma — **предложения** с адресатом;
> правки `ADR-*`, `plans/erp/**`, `plans/ERP-IMPL/**`, `plans/AIRESTO/**` этим документом не производятся.

**Опора измерения:** `findings/P10-erp-state-2026-09-19.md` · `research/09-p10-erp-offline-2026-08.md` §4, §6, §7

**Разделение доноров (D112):** дизайн-вехи и состав ядра — борд `erp` §8 (`00_MASTER_PLAN_V3.md`);
факт кода и фазы исполнения — борд `ERP-IMPL` (`status.md` / `plan.md` §4). Статусы в §1 — **снимок 2026-09-19**;
закрытие строк — сверка с чужой доской напрямую; перенос чужих статусов в наш план построчно **запрещён**.

---

## 1. Cross-plan gate-таблица (plan-protocol §11)

| Предусловие | План-донор и **точная** строка борда | Что разблокирует у нас | Статус (снимок 2026-09-19) |
|:--|:--|:--|:--|
| Монорепо ERP с workspace и пакетами существует и собирается | `ERP-IMPL` · `plans/ERP-IMPL/status.md` · фаза **P1** «IT-0: Bootstrap монорепо» | P10.3–P10.5: адаптеры и сторы имеют дом `erp-sdk`, а не выдуманный API | 🟡 P1 **4/12** In progress (`findings/P10-erp-state-2026-09-19.md` §5) |
| Scaffold 16 пакетов `axioma_*` принят как дизайн-веха | `erp` · `plans/erp/00_MASTER_PLAN_V3.md` §8 · строка **0.3** «Scaffold `packages/axioma_*`» | Карта портов §2: имена пакетов-реализаторов не выдумываются | ☐ (борд); код scaffold **есть** — рассинхрон с бордом (P10.1 §4) |
| Контракт синка (идемпотентность доставки) зафиксирован | `erp` · `00_MASTER_PLAN_V3.md` §8 · **H9** «Sync Protocol Contract» | R7: слой доставки `SyncItem` (ADR-007) согласован с INT-002 §5 | ☐ |
| Ядро данных и авторизации для персистентности | `erp` §8 · эпики **U** `axioma_utils`, **EV** `axioma_events`, **C** `axioma_core`, **D** `axioma_data`, **AZ** `axioma_authz` | P10.4: Drift/SQLite + политики темпоральности (`0.6` custom-lint) | ☐ (все пять) |
| MCP-сервер и контракты ассистента | `erp` §8 · **MCP** `axioma_mcp`; **AS** `axioma_assistant` (контракты) | P10.3: `ToolPort` / `LlmPort` / доставка UI | ☐ (борд); `lib/` MCP/AS **есть**, analyze OK (P10.1 §1) |
| PoC транспорта MCP на пине контура | `ERP-IMPL` · `plans/ERP-IMPL/status.md` · item **P2.1** «SP-1 mcp_dart PoC» | P10.3: ветка Q-23 (1) или доказанная совместимость эры 2026-07-28 | ⬜ Not started |
| **Пин `mcp_dart` и эра протокола** (Q-23) | `erp` · `00_MASTER_PLAN_V3.md` §6 (стек: `mcp_dart 2.2.0`); `ERP-IMPL` **D10** / `RAG-P1-stack-melos.md` §1.1 (`^2.2.2`); ревизия `DR-01` §A3 (только MCP 2026-07-28) | Выбор владельца: **(1)** предложение поднять пин в каноне; **(2)** `agentools_mcp_dart` (D86) как носитель транспорта AgenTools; **(3)** перенос **без** `McpTransport` — in-process `IntentRouter` → `AxiomaMcpServer` (ADR-004). Исполнитель **не** выбирает молча | Конфликт эры **открыт**; в `packages/*/pubspec.yaml` зависимостей `mcp_dart` **0** (P10.1 §2) |
| Дом переноса выбран владельцем (Q-20) | AGENTOOLS · D188 proposed | INT-002 §2 пути кода; P10.3 `Files` | **proposed (B)** тонкий мост |

---

## 2. Карта девяти портов Dart SDK

Направление зависимости: `erp-sdk` (мост `packages/agentools_bridge/` — имя фиксирует P10.3) → `agentools` (pub). Обратной зависимости SDK на контур **нет**.

| Порт SDK | Реализующий класс / таблица (целевой) | Носитель | Статус / примечание |
|:--|:--|:--|:--|
| `EventLog` | `AgentoolsBridgePersistence::DriftEventLog` | Drift-таблица `at_action_events` (новая, append-only) | Источник истины §C1; `expected_prev_seq`, `prev_hash` (§C3, §C7); проверка P10.4 |
| `SessionStore` | `AgentoolsBridgePersistence::DriftSessionStore` | `at_action_snapshots` + fold | `ai_conversation` / `ai_turn` — **только** проекция свёртки (Q-21 ветка a) |
| `Clock` | `AgentoolsBridgeSupport::InjectableClock` | in-memory | Обязателен для детерминированного E2E |
| `TokenSigner` | `AgentoolsBridgeCrypto::OrgScopedTokenSigner` | ключевой материал org/tenant (не глобальный секрет) | HMAC/AEAD по §B2 |
| `IdempotencyStore` | `AgentoolsBridgePersistence::DriftIdempotencyStore` | `at_tool_dedupe` (или эквивалент одной таблицы) | Три состояния, два таймера §D1–§D3; **не** дублировать ключом `correlation_id` (§5) |
| `LlmPort` | `AgentoolsBridgeAdapters::ErpLlmPort` → `LLMProvider` (`axioma_assistant`) | runtime-провайдер (GigaChat/YandexGPT по ADR-012) | Без прямых HTTP из моста; квота → tool execution error |
| `ToolPort` | `AgentoolsBridgeAdapters::ErpToolPort` → `AxiomaMcpServer` / `AxiomaTool` | аудит-проекция trace | Двухуровневый гейт: каталог + invoke; субъект — человек (INV-ASSIST-1) |
| `AuditSink` | `AgentoolsBridgeAudit::GateDecisionAuditSink` | поля trace из события §I4 | **Единственный** путь записи решения пользователя |
| `McpTransport` | **Зависит от Q-23:** `AgentoolsMcpDartTransport` (`agentools_mcp_dart`, D86) **или** `InProcessMcpTransport` (no wire) **или** отсутствует при ветке (3) | pub / in-process | До выбора владельца — проектная строка в gate §1; P10.3 обязан явно зафиксировать ветку в Completion Notes |

**Дом пакета (гипотеза B):** `~/projects/packages/erp-sdk/packages/agentools_bridge/` (регистрация в workspace `pubspec.yaml`).

---

## 3. Реконсиляция R1–R10

| # | Вердикт | Обоснование |
|:--|:--|:--|
| **R1** | **Сходится** | Tier 0–3 контура (09 §3.1) — это Policy Layer ERP: tier как свойство ability, матрица в PDP, не в коде моста. Отображение на §B6 — таблица §8. |
| **R2** | **Сходится** (при трактовке Tier 3) | Tier 3 = Policy вернула «pre-commit гейт не требуется» (standing pre-auth), **не** отсутствие TTL-токена. Событие решения §I4 и post-hoc `ConfirmationPackage` **обязательны**; отрицательный прогон §11 п.3 (P10.5). |
| **R3** | **Сходится, enforcement на стороне контура** | `money-exclusion` / `ai_forbidden` (INV-ASSIST-3) исключает ability до протокола. Мост не вводит третий словарь эффекта. |
| **R4** | **Сходится** (один механизм + проекция) | Источник решения — prepare/preview/commit + событие §I4. `ConfirmationPackage.facts_hash` / `ai_action_trace` — **проекция** `args_fingerprint` / payload §I4, не параллельный anti-TOCTOU (симметрия INT-001 §2). |
| **R5** | **Сходится** | `dual control` §B5 = obligation `dualControl` (09 §7.5): proposer ≠ approver. Проверить поля при P10.5, не предполагать по имени (прецедент P9 `double_confirm`). |
| **R6** | **Дельта канона — предложение** (размещение) | Симметрия Q-21: выбрана ветка **(a)** — журнал `at_action_events`, канонические `ai_*` — проекция. Предложение **E6-001** (§9). |
| **R7** | **Дельта канона — предложение** (слои дедупа) | Три механизма разведены в §5; деривация idempotency от `correlation_id` без привязки к клиентскому ключу — **убрать** как третий носитель запроса. Предложение **E7-001**. |
| **R8** | **Пакет решения владельцу** (Q-22) | Два control-layer: Scenario Runner (§F1–§F4) vs `axioma_assistant/orchestration/` (ADR-068). Вердикт не выносится здесь — §7. |
| **R9** | **Сходится, контур строже** | ADR-073 / INV-ASSIST-5: tier/scope из сессии; write-класс заморожен на plan-time. Наш §I2 покрывается; усиление — на стороне PEP контура. |
| **R10** | **Сходится** (подтверждение решения) | §G1 синхронный путь без задач обязателен именно для offline-first (P8.11 Why). Терминология деградации — **только** `L0`/`L1`/`L2` (ADR-072). |

---

## 4. Носители факта о согласии пользователя

Вариант «протокол и command-bus считают независимо» **запрещён**.

| # | Носитель контура | Целевая роль | Как становится проекцией / что не участвует в commit | Проверка |
|:--|:--|:--|:--|:--|
| 1 | **Протокол AgenTools** (prepare → preview → commit, токен §B2, событие §I4) | **Источник истины** | — | P10.5: отрицательные прогоны (args mismatch, TTL, повтор commit) |
| 2 | `ConfirmationPackage` + `facts_hash` (09 §4) | **Проекция** | Заполняется **из** события §I4 после commit; command-bus не пересчитывает допуск | P10.5: сверка hash с `args_fingerprint` / payload §I4 |
| 3 | `ai_action_trace` / `sys_audit_record` | **Проекция аудита** | Trace-поля из §I4 + fold; не второй гейт | P10.5: счётчик «ровно одно» интерактивное подтверждение на пути |

---

## 5. Три механизма дедупликации

| Слой | Механизм | Ключ | Владелец |
|:--|:--|:--|:--|
| Запрос (протокол) | `IdempotencyStore` SDK | Клиентский ключ + `args_fingerprint` §D1 | Мост AgenTools (`at_tool_dedupe`) |
| Доставка (offline sync) | `SyncQueue` / `SyncItem` | Идемпотентный ключ элемента очереди ADR-007 | `axioma_sync` — **другой слой**, не подменяет клиентский ключ |
| Trace-корреляция | `correlation_id` в `ai_action_trace` | Корреляция tool-call в audit | **Только** корреляция и объяснимость; **не** третий dedupe запроса |

Третий носитель, дублирующий первый (отдельный idempotency-key только от `correlation_id` без клиентского ключа) — **снять** (предложение E7-001).

---

## 6. Размещение журнала (Q-21 → D189)

**Выбрана ветка (a):** журнал протокола — **новые** таблицы `at_action_events` / `at_action_snapshots` рядом с каноническими `ai_*` в tenant Drift DB; `ai_conversation`, `ai_turn`, `ai_action_trace` — **проекции свёртки**, не независимая запись состояния.

**Запрет:** два независимых пути записи состояния запрещены в коде моста; проверка P10.4 (parallel append → конфликт; запрет UPDATE/DELETE на записанных событиях — custom-lint контура).

### 6.1 Эскиз DDL (не финальная миграция — P10.4)

См. симметрию INT-001 §4.1: `action_id`, `seq`, `expected_prev_seq`, `event_type`, `payload`, `prev_hash`, ULID PK (ADR-023).

---

## 7. Владение движком сценариев (Q-22)

Оба движка существуют в замысле контура (ADR-068) и в SDK (§F1–§F4). **Вердикт владельца пакета не выбран** (Rule of Three).

| Альтернатива | Суть | Цена |
|:--|:--|:--|
| **A — Scenario Runner SDK** | Сценарии `SCN-*` / MRTR §B1 исполняются в мосте; `orchestration/` — только внутренние подшаги без второй грамматики | Дублирование с ADR-068; риск расхождения исключений через год |
| **B — `axioma_assistant/orchestration/`** | Planner/router/executor/verifier владеют control-flow; SDK даёт порты и гейт commit | Нужен явный адаптер сценариев → task-DAG; conformance по §F — на границе адаптера |

**Адресат:** владелец пакета AgenTools + строка борда `erp` §8 **AS** / `ERP-IMPL` фаза, владеющая `axioma_assistant`.

---

## 8. Tier 0–3 → Policy Layer (§B6)

| Tier ERP (09 §3.1) | Policy (§B6) | Confirm / token | Money-exclusion |
|:--|:--|:--|:--|
| 0 read/explain | `allow` | не требуется | abilities исполнения денег **вне** каталога (INV-ASSIST-3) |
| 1 draft | `allow` + draft-lock | не требуется | то же |
| 2 operational write | `ask` → prepare/preview/commit | обязателен §B2–§B8 | то же |
| 3 standing pre-auth | `allow` без pre-commit гейта | событие §I4 **всё равно**; post-hoc review | **Tier 3 не отменяет** `ai_forbidden` |

Термины offline: только **L0** / **L1** / **L2** (ADR-072) в отчётах и INT-002; третий словарь запрещён.

---

## 9. MRTR и продуктовый UI

**MRTR** (§B1) — единственный биндинг уточнений на MCP-границе.

Продуктовый UI контура (`axioma_ui`, Flutter) **не** MCP-клиент: объекты `ClarificationRequest`, preview, отложенное подтверждение **доставляются** в UI как есть; адаптер не интерпретирует и не подменяет elicitation вторым протоколом.

---

## 10. Реанкор `90_inbox/airesto-offline-gap.md` (ревизия 2026-08)

| # | Требование inbox (2026-07-draft) | Сохранилось в 2026-08 | Выражение сейчас |
|:--|:--|:--|:--|
| 1 | Scenario Runner на L0 без изменений | да | `06_scenarios.md` / §F1–§F4; L0 = connected path |
| 2 | L1 on-device — не блокер MVP, API допускает позже router | да | ADR-072 L1 deferred; §F не закрывает путь к локальному router |
| 3 | MVP-деградация = L2 «AI выключен, control-logic жива» | да | ADR-072 L2; матрица P10.6 |
| 4 | Митигации weak function calling (retry/clarify) — паттерн сценария | да | `CLR-*` / `when` на confidence; без доп. требований к SDK сверх ревизии |
| 5 | STT вне SDK-контракта, Yandex online / Vosk-Whisper offline — выбор AiResto | да | `LlmPort`/`SpeechProvider` инжектируемы; ADR-012 SpeechKit — продуктовый слой |

---

## 11. Предложения дельт канона Axioma

Оформлены по `architect:package-contribution-protocol`. Правки `ADR-*` этим item'ом **не** производятся.

### E2-001 — Tier 3 и событие §I4

| | |
|:--|:--|
| **Что** | Явно связать standing pre-authorization (Tier 3) с обязательной записью события решения §I4 и post-hoc review, без второго pre-commit гейта |
| **Почему нельзя на потребителе** | Иначе мост не отличит «политика разрешила без гейта» от «гейт обойдён» |
| **Минимальный контракт** | Таблица Tier 3 в 09 §3.1 + ссылка на §I4 |
| **Альтернативы** | (1) Требовать второй токен Tier 3 — ломает ADR-069; (2) Только в INT-002 — не масштабируется на AiFina/AiResto |
| **Адресат** | `erp` §8 фаза **09** / ADR-069 |

### E6-001 — Event-log-first рядом с `ai_*`

| | |
|:--|:--|
| **Что** | Нормировать `at_action_events` (или каноническое имя) как журнал протокола; `ai_turn` — проекция |
| **Почему нельзя на потребителе** | Без SSOT таблицы каждый интегратор изобретает audit trail |
| **Минимальный контракт** | §6.1 эскиз + инвариант один путь записи |
| **Альтернативы** | (1) Журнал только в `ai_action_trace` — нет §C3/§C7; (2) Журнал вне БД — split-transaction offline |
| **Адресат** | `ERP-IMPL` **P5** `axioma_data` + `erp` §8 **D** |

### E7-001 — Слои идемпотентности

| | |
|:--|:--|
| **Что** | Зафиксировать: клиентский ключ протокола ≠ ключ `SyncItem` ≠ dedupe по `correlation_id` |
| **Почему нельзя на потребителе** | Двойное исполнение после разрыва связи |
| **Минимальный контракт** | Таблица слоёв §5 в каноне синка |
| **Альтернативы** | (1) Один ключ на всё — ломает offline replay; (2) Только в коде моста — не переживает смену интегратора |
| **Адресат** | `erp` **H9** + ADR-007; `09_AI_Orchestration_UX.md` §6.3 |

### E23-001 — Пин `mcp_dart` и эра 2026-07-28

| | |
|:--|:--|
| **Что** | Поднять пин до версии с `McpProtocol.require2026` **или** официально разрешить in-process без wire-транспорта для same-process MCP (ADR-004) |
| **Почему нельзя на потребителе** | Ревизия AgenTools не понижается; stable 2.2.x не энфорсит эру (P8.12) |
| **Минимальный контракт** | D10 stack lock + матрица веток Q-23 |
| **Альтернативы** | (1) `agentools_mcp_dart` side-by-side (D86); (3) без `McpTransport` — не проверяет wire-слой эры |
| **Адресат** | `erp` §6 + `ERP-IMPL` **P2.1** / D10 |

### E8-001 — Единый владелец control-layer

| | |
|:--|:--|
| **Что** | ADR-068 vs Scenario Runner — назначить один SSOT для сценариев продукта |
| **Почему нельзя на потребителе** | Два движка разойдутся на исключениях (Rule of Three) |
| **Минимальный контракт** | Диаграмма владения + граница MRTR |
| **Альтернативы** | §7 A/B |
| **Адресат** | Владелец пакета AgenTools · `erp` §8 **AS** |

---

## 12. Границы и эскалации

| Условие | Исход |
|:--|:--|
| Канон ERP требует исполнения, запрещённого §B4/§B8 (например commit без повторной авторизации там, где ревизия требует) | `🔴 Blocked` — эскалация владельцу: правка канона vs новая датированная ревизия |
| Q-20 остаётся `proposed`, владелец выбирает (C1) waiting | Аналитическая часть INT-002 (§1, §3, gate) остаётся входом P10.6; карта §2 — **проект** до терминальности `ERP-IMPL` P1 |
| Публичная поверхность `axioma_mcp`/`axioma_assistant` несовместима с портами | Не блокер: строка в §2 с адресатом + предложение E* |

---

## 13. Ссылки

- `10_decisions/DR-01_architecture-2026-08.md` §B1–§B8, §C1–§C7, §D1–§D3, §E6, §F1–§F4, §G1, §I2, §I4  
- `root/contracts/INT-001_corex-ai-integration.md` (форма, не содержание)  
- `findings/P10-axioma-erp-recon-2026-07-27.md` §2–§5  
- Решения плана: D108, D111, D112, D186, D188 (proposed), **D189**
