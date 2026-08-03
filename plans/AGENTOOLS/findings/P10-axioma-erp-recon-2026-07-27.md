---
type: findings
plan: AGENTOOLS
phase: P10
created: 2026-07-27
layer: 1
---

# P10 — разведка живого канона и кодовой базы ERP-контура Axioma (2026-07-27)

Слой 1 (`plan-protocol` §16): сырьё, полученное прогоном команд и RAG в ходе детализации фазы P10.
Синтез и вердикты — `research/09-p10-erp-offline-2026-08.md`. Все числа сняты на деревьях:
vault `Brain` — `16e1ec6`, `~/projects/packages/erp-sdk` — `62e1153`,
`~/projects/packages/agentools` — `4344265`. Каталога `~/projects/packages/axioma_erp_sdk` не
существует (§1).

**Дисциплина повторного применения.** Фаза P10 исполняется ПОСЛЕ фаз P1–P9, то есть заведомо
позже даты этой разведки, и зависит от чужой цепочки вех, которая между делом двигается. Файл —
не источник истины на дату исполнения, а **опора сравнения**: item P10.1 обязан снять те же
измерения заново и назвать каждое расхождение отдельной строкой. Разведка, поданная как
«состояние на момент исполнения», — тот же класс лжи, из-за которого прежняя детализация фазы
(2026-07-11) строилась на допущениях о контуре, которого нет.

---

## 1. Монорепо `axioma_erp_sdk` НЕ СУЩЕСТВУЕТ; репозиторий `erp-sdk` — только документация

```console
$ ls -d ~/projects/packages/axioma_erp_sdk
ls: cannot access '/home/vostrikov/projects/packages/axioma_erp_sdk': No such file or directory

$ ls ~/projects/packages/erp-sdk
AGENTS.md  CLAUDE.md  docs  README.md

$ find ~/projects/packages/erp-sdk -name '*.dart' -not -path '*/.git/*' | wc -l
0
$ find ~/projects/packages/erp-sdk -name 'pubspec.yaml' -not -path '*/.git/*' | wc -l
0
$ git -C ~/projects/packages/erp-sdk rev-parse --short HEAD
62e1153
$ git -C ~/projects/packages/erp-sdk log -1 --format='%ad %s' --date=short
2026-06-13 docs: канон ТЗ, план реализации и регламент агентов (подготовка Фазы 0)
$ git -C ~/projects/packages/erp-sdk status --short | wc -l
17
```

Вывод: в контуре ERP **нет ни одной строки Dart-кода** — ни ядра, ни модулей, ни приложения.
Существующий репозиторий `erp-sdk` держит только канон (`docs/01_Business`, `docs/03_Dev` с 12 ADR
и 16 спеками модулей); 17 файлов в нём не закоммичены (правки канона от 2026-07).

**Сравнение с фазой P9 — разрыв качественно другой.** У контура Flex кодовая база ЖИВАЯ
(монорепо CoreX с шестью пакетами + приложение на Laravel 13), отсутствовал ОДИН пакет `corex/ai`
(`findings/P9-…` §1). У контура ERP отсутствует ВЕСЬ контур: монорепо создаёт item `P1.1` плана
`ERP-IMPL`, статус фазы — `⬜ Not started` (§4).

---

## 2. Реестр модулей ERP: 16 модулей, AI-слой — ДВА модуля, оба только спеки

```console
$ ls ~/projects/packages/erp-sdk/docs/03_Dev/modules/ | grep -v _index | sed 's/.md//'
axioma_assistant axioma_authz axioma_core axioma_data axioma_events axioma_finance
axioma_fiscal axioma_mcp axioma_plugins axioma_receipt axioma_regcontent axioma_router
axioma_sync axioma_tax axioma_ui axioma_utils
```

| Модуль | Роль в AI-слое | Статус реализации по канону |
|:--|:--|:--|
| `axioma_mcp` | серверная сторона: `AxiomaMcpServer`, реестр типизированных tools, `ToolTier`, права, аудит | спека `module-spec` от 2026-06-11; кода нет |
| `axioma_assistant` | клиентская сторона: `InputChannel` / `SpeechProvider` / `LLMProvider` / `IntentRouter` / `AssistantOrchestrator` + сублой `orchestration/` (Δ02-7) | спека прямо пишет: «контракты — Этап 1; реализации провайдеров и pipeline — Этап 2 (M2.4)»; кода нет |

Ключевые сигнатуры `axioma_assistant` (модуль-спека, §C–§E) — кандидаты на отображение наших
портов: `LLMProvider.complete(List<LlmMessage>, List<ToolDefinition>) → LlmResponse{text?, toolCall?}`,
`IntentRouter.route(ToolCall, AxiomaContext) → ToolResult`, `SpeechProvider.recognize(Stream<List<int>>) → Stream<String>`.

Ключевые сигнатуры `axioma_mcp` (§B): `AxiomaTool{name, tier: ToolTier, requiredPermission, argsSchema: JsonSchema, execute(ToolArgs, AxiomaContext)}`;
«нет права — tool **не виден модели вовсе** (не „виден, но падает“)» (§D) — тот же двухуровневый
permission-паттерн, что в контуре Flex (`ADR-022` п.5).

---

## 3. Живой канон AI-слоя ERP: ADR-004/010/012 + фаза 09 (ADR-068..073, INV-ASSIST-1..7)

Канон существует и он **зрелый и подробный** — это не пробел, который мы заполняем, а
самостоятельная замороженная дизайн-программа с собственными инвариантами.

| Документ | Дата | Что нормирует |
|:--|:--|:--|
| `ADR-004 AI via MCP Only` | 2026-06-11 | единственный канал AI — MCP-сервер ядра; типизированные tools; запрет прямого доступа к БД |
| `ADR-010 AI Write Tiers` | 2026-06-11 | Tier 0 read / Tier 1 draft-write / Tier 2 operational-write; «прямой постинг в финансовое ядро для AI запрещён всегда» |
| `ADR-012 AI Interaction and Provider Strategy` | 2026-06-13 | `axioma_assistant`; runtime-LLM только российский (GigaChat/YandexGPT); ASR — Yandex SpeechKit; провайдеры подключаемые |
| `ADR-007 Offline-First Sync` | 2026-06-11 | `SyncQueue`, **идемпотентный ключ у каждого `SyncItem`**, доставка до `markSynced`, LWW + уведомление админа; финансы — append-only, сторно вместо правок |
| `plans/erp/09_AI_Orchestration_UX.md` | фаза 09 (RV-9) | лестница автономии Tier 0–3, `ConfirmationPackage`, trace-grounded объяснимость, оркестрация как control-layer, **offline-лестница L0/L1/L2**, untrusted-boundary, `ai_*`-таблицы |

Новые ADR фазы 09 (проекты, зарегистрированы в `00_MASTER_PLAN_V3.md §3.3): **ADR-068**
Orchestration as Control-Layer · **ADR-069** Autonomy Ladder Tier 0–3 (per-ability, money-excluded)
· **ADR-070** Structured Confirmation Contract · **ADR-071** Trace-Grounded Explainability ·
**ADR-072** Offline AI Degradation Ladder · **ADR-073** AI Authority Boundary. Плюс амендмент
`ADR-010`. Семейство инвариантов — `INV-ASSIST-1..7` (09 §10.1).

### 3.1 Лестница автономии Tier 0–3 (09 §3.1) — дословные факты

| Tier | Что | Гейт по канону ERP |
|:--|:--|:--|
| 0 | read/explain/simulate | всегда доступен, ответ помечен «не проведено» |
| 1 | create-**draft** | без confirmation, **заперт `resource.state==draft`** |
| 2 | провести/мутировать финсостояние (кроме денег) | **obligation `confirm`** — интерактивный pre-confirm ДО исполнения |
| 3 | auto-act | obligation `confirm` удовлетворяется **standing pre-authorization**: org-opt-in `subject.org.ai_tier3_enabled` + потолок `ai_max_tier[ability]` + **обязательная post-hoc review-очередь** + обратимость |

- **Money-exclusion (INV-ASSIST-3, AP10):** abilities **исполнения** денег
  (`payment.execute` / `bank.transfer.*` / `payment.settle`) исключены из AI-набора **на всех
  tier, включая Tier 3**; draft платежа (`payment.create`, заперт `draft`) — Tier 1. Носитель —
  флаг `ai_forbidden` в `authz_ability` (06 §2.2) + SDK-guard `context.actor_kind != 'ai'`, **НЕ
  tier-значение**.
- **Tier не выбирает planner** (INV-ASSIST-5): per-action `context.ai_tier` — детерминированная
  функция {ability-class, `ai_max_tier[ability]`, `ai_tier3_enabled`}, вычисляется PEP/executor'ом
  ДО чтения какого-либо контента.
- **Tier-матрица выражена политиками PDP, не кодом** (06 §6.3).

### 3.2 `ConfirmationPackage` (09 §4.1) — состав по канону ERP

```
ConfirmationPackage { summary · scopes[] · diffs[] · warnings[] · tier(2|3) ·
                      facts_hash · obligations[] (confirm + dualControl) · commands[] }
```

- **`facts_hash`** — «hash фактов diff-сущностей (по образцу 01 §3.7) — анти-TOCTOU»;
  command-bus при dispatch **пересчитывает** `facts_hash`, расхождение → отклонение команды +
  **re-confirmation с новым package**; «устаревший signoff не исполняется» (09 §4.2).
- **`dualControl`** — «крупные суммы → второй approve **другим пользователем**» (06 §7.5,
  `authz_approval`).
- Signoff — immutable-артефакт в `sys_audit_record` + `ai_action_trace`.

### 3.3 Offline-лестница L0/L1/L2 (09 §7, ADR-072) — терминология-первоисточник

| Уровень | Условие | Что доступно |
|:--|:--|:--|
| **L0** full cloud | `connected` | полная оркестрация, все tools, retrieval, long-context (росс. LLM) |
| **L1** local model | `!connected` + on-device small-model | drafting/summarization/command-palette/cached workflows · **deferred (UV-5)** |
| **L2** deterministic (**MVP-degrade**) | `!connected`, модели нет | command-palette, шаблоны, поиск по локальным индексам, macro/replay, **queued-actions** |

- **Silent fallback запрещён** (INV-ASSIST-6): `ShellRuntimeState.connected` перевычисляется
  непрерывно, capability-banner отражает уровень явно.
- **LLM — инжектируемая capability, НЕ ядро цикла** (F-09.2/ADR-068): planner/router/executor/
  verifier — детерминированный control-layer, поэтому «L2-degrade выживает».
- **Queued-actions (прямая релевантность нашему §B4 TTL):** команды, сформированные но не
  исполненные из-за `!connected`, уходят в очередь синка (ADR-007), исполнение — по возврату
  связи, **с повторным confirmation**; «immutable-артефакт §4.2 **не переиспользуется через
  разрыв**».
- **UV-5** (`00_MASTER_PLAN_V3.md §12.3): «Зрелость on-device / локальной small-model 2026 для
  offline-L1 AI-degrade …; точность росс. STT/TTS для финкоманд (voice)» — помечено
  `[UNVERIFIED]` самим чужим каноном.

### 3.4 Оркестрация как control-layer (09 §2, §6; ADR-068)

`axioma_assistant` += сублой `orchestration/` (planner / router / executor / verifier / ux_state),
**НЕ новый модуль** (16 = 16, D54). Sequencing: `classify → decide(answer|retrieve|tool-call|
verify|stop) → tools` с явными stop-conditions; shallow planner строит компактный task-DAG;
executor — parallelizable tool-batch; verifier — constraint/redundancy.
**Idempotency (09 §6.3):** «idempotency key на каждый tool-call (повтор при обрыве не двоит
запись)»; `ai_action_trace.correlation_id` обязателен из envelope command-bus; **idempotency-key —
ОТДЕЛЬНЫЙ dedup-ключ, деривируется от `correlation_id` + tool-call**.

### 3.5 `ai_*`-семейство таблиц (Δ01-9) — второй кандидат-носитель факта

`ai_conversation` (org_id, initiator_user_id, ui_language) · `ai_turn` (role, uttered_text,
`tier_decisions` JSON) · `ai_action_trace` (tool_key, `args_hash`, plan_step, verdict,
`authz_trace_ref` FK→`sys_audit_record`, `explanation_ref`, **`correlation_id` NOT NULL**,
`override_reason`). Все — **append-only, audit-class**, ULID-ключи (ADR-023).
`ai_turn.uttered_text` — sensitivity `sensitive`, чтение через ability `ai.trace.read` +
obligation `auditRead`; retention 152-ФЗ — открытый держатель PF-1.

---

## 4. Кросс-план: контур ERP принадлежит ДВУМ чужим планам в ДРУГИХ корнях `plans/`

| План | Корень | Роль | Состояние на 2026-07-27 |
|:--|:--|:--|:--|
| `erp` (дизайн-программа) | `05-Projects/03-Packages/AxiomaErpSdk/plans/erp/` | SSOT дизайна: 14 доков, статус-борды §8 (строки `0.1`…`0.10`, `H1`…`H10`, `U`/`EV`/`C`/`D`/`AZ`/`T`/`F`/`R`/`UI`/**`MCP`**/**`AS`**/`RC`, вехи `M2.*`/`M3.*`) | дизайн закрыт (GATE-2 READY, 2026-07-08); ВСЕ строки статус-бордов `☐` |
| `ERP-IMPL` (кодовая волна) | `05-Projects/03-Packages/AxiomaErpSdk/plans/ERP-IMPL/` | исполнимый мастер-план IT-0+ | `🟡 In progress`, Version 0.1.1; **P1 (IT-0 bootstrap) — 0/10 `⬜`**; P4–P7 — скелеты |

`ERP-IMPL` Status Board (снимок 2026-07-27):

| Phase | Title | Items | Status |
|:--|:--|:--|:--|
| P1 | IT-0 — Bootstrap монорепо `axioma_erp_sdk` | 0/10 | ⬜ Not started |
| P2 | IT-1 — спайки риска SP-1..20 + SP-UI-1 + SP-27 | 0/22 | ⬜ Not started |
| P3 | Ранние сквозные design-контракты (H9 Sync / H10 Test-Perf) | 0/3 | ⬜ Not started |
| P4 | IT-2 — эпики U/EV/C | 0/3 | ⬜ Not started |
| P5 | IT-3 — эпики PL/D/AZ | 0/3 | ⬜ Not started |
| P6 | IT-4 — эпик T (`axioma_tax`) | 0/4 | ⬜ Not started |
| P7 | IT-5..IT-12 — остаток критпути (finance → продукты → RegCloud) | 0/8 | ⬜ Not started |

`ERP-IMPL` D1: «Home=brain — программный план: репо `axioma_erp_sdk` не существует, создаёт item
P1.1». `ERP-IMPL` D2: дизайн-программа `plans/erp/` заморожена — D1–D89 + ADR-001..096 +
I1–I12/INV-* **неприкосновенны**; конфликт → `open-questions.md` + эскалация, не правка канона.

**Ни `axioma_mcp`, ни `axioma_assistant` не входят в фазы P1–P6 плана `ERP-IMPL`.** По критпути
`utils → events → core → data → tax → finance` (`erp` §8, Этап 1) строки `MCP` и `AS` идут ПОСЛЕ
`AZ`; в `ERP-IMPL` они попадают в фазу **P7** — скелет-индекс `IT-5..IT-12`, 0/8, `⬜`.

### 4.1 Прецедент формы cross-plan gate-таблицы существует и работает

`05-Projects/01-Products/AiResto/plans/AIRESTO/10_decisions/ERP-GATE-01_dependency_gate.md`
(2026-07-08) — готовый образец `plan-protocol` §11 в этой же экосистеме:

- таблица `Предусловие | erp-источник (строка §8) | Слой AiResto, который разблокирует | Статус`;
- каждая строка ссылается на КОНКРЕТНУЮ строку статус-борда чужого плана (`Фаза 0, строка 0.1`;
  `Этап 1, строка MCP`; `Этап 1, строка AS`);
- governance-правило дословно: «Правка `plans/erp` из плана `AIRESTO` **запрещена** (только
  чтение-ссылка)»; «расхождение с каноном/статусом erp во время exec = не повод импровизировать
  заглушки против выдуманного API — эскалация»;
- статусы — снимок с датой, закрытие строк — акт будущего исполнителя, сверяющегося с чужим
  бордом НАПРЯМУЮ, а не переносящего его сюда построчно.

### 4.2 Канон ERP не знает про AgenTools вовсе

```console
$ grep -rniE 'agentools|agen tools' 05-Projects/03-Packages/AxiomaErpSdk/ ~/projects/packages/erp-sdk/docs
(нет совпадений)
```

Ноль упоминаний в дизайн-программе `erp`, в `ERP-IMPL` и в репозитории канона. То есть чужой
контур спроектировал **собственную** агентную программу (09 + ADR-068..073 + INV-ASSIST-1..7),
не ссылающуюся на нашу ревизию. Это сильнее ситуации фазы P9, где `B-32` §3.8 хотя бы разрешал
стык (пусть и против отменённой ревизии).

### 4.3 Обратный канал СУЩЕСТВУЕТ и уже направлен на эту фазу

`05-Projects/01-Products/AiResto/docs/03_Dev/AI_Offline_Strategy (AiResto).md` (2026-07-08) в
шапке пишет: «Синхронизирована передачей в
`05-Projects/03-Packages/AgenTools/plans/AGENTOOLS/90_inbox/airesto-offline-gap.md` (**их P5.3 —
пустой получатель гэп-анализа**)». Файл `90_inbox/airesto-offline-gap.md` (8364 байта) лежит в
этом плане с 2026-07-15 и содержит адресные требования к нашему отчёту применимости — то есть у
выхода фазы P10 есть НАЗВАННЫЙ чужой потребитель, ждущий вердикта. Прежний номер их адресата
(`P5.3`) читается по карте переименования `plan.md` §4 как **P10.3** скелета.

Что чужой план просит (дословный смысл, `90_inbox/airesto-offline-gap.md`):

1. Scenario Runner работает без изменений на L0 — доп. требований нет.
2. **L1 — не блокер MVP**; наш отчёт может не проектировать L1 детально, «важно, чтобы контракт
   Scenario Runner допускал расширение локальным router-веткой ПОЗЖЕ без слома текущего API».
3. **MVP-деградация = L2** — «AI выключен, control-logic жива»; можно принять как целевую
   MVP-семантику Dart-контура.
4. Митигации слабого function calling (RetryingStructuredOutput, confidence-порог → уточнение)
   применяются на уровне Scenario Schema; доп. требований к SDK нет.
5. STT вне нашего контракта; онлайн-провайдер — Yandex SpeechKit gRPC; offline-кандидаты
   (Vosk / Whisper-класс) выбирает AiResto сама.

Чего чужой план НЕ просит: завершать L1, выбирать offline-STT модель, менять Scenario Schema или
Confirmation Protocol.

**Оговорка о ревизии.** Заметка написана 2026-07-08 против `00_MASTER_PLAN.md` (§5.4 Scenario
Schema v1, §5.5, §8) и ревизии `2026-07-draft`, объявленной историей (D18–D20). Её требования
обязаны быть **реанкорены** на ревизию `2026-08` item'ом P10.2, а не приняты как есть.

---

## 5. Пин `mcp_dart` в стеке ERP — стабильная ветка, НЕ знающая эры 2026-07-28

```console
$ grep -rn 'mcp_dart' 05-Projects/03-Packages/AxiomaErpSdk/plans/erp/
00_MASTER_PLAN_V3.md:339: … mcp_dart 2.2.0 …            (стек-локфайл §6)
02_Codebase_Architecture.md:493: `axioma_mcp` → server/ (mcp_dart 2.2) …
```

`ERP-IMPL` D10 (2026-07-12) закрепил: «`mcp_dart` пиннить **`^2.2.2`** (pub.dev детерминированно,
adversarial ✅); „0.6.1“ из раннего RAG = путаница с официальным-но-экспериментальным `dart_mcp`
0.5.2 (labs.dart.dev) — не пиннить, радар миграции».

**Конфликт с нашей ревизией — измеренный, не предполагаемый.** Разведка фазы P8
(`findings/P8-dart-sdk-recon-2026-07-27.md` §5, тот же день) грепом по исходникам обеих версий
показала: в **stable `mcp_dart` 2.2.2 нет НИ `McpProtocol.require2026`, НИ энфорсмента
`resultType`** (0 совпадений), обе возможности есть только в prerelease `2.3.0-dev.3`
(`lib/src/types/json_rpc.dart:48,68,73,83,90`, `lib/src/shared/protocol.dart:790`). Ревизия
`2026-08` таргетирует **только** MCP 2026-07-28 без legacy-эры (`DR-01` §A3, D20).

Следствие: запиннутый стек чужого контура физически не говорит на эре, которую требует наша
ревизия. Разрешение — не действие исполнителя: либо дельта чужого канона (поднять пин),
либо пакет-спутник `agentools_mcp_dart` (D86) со своим констрейнтом как единственный носитель
транспорта, либо принятие того, что перенос идёт без MCP-транспорта вовсе (порты in-memory).
Ветку выбирает владелец — см. `research/09-…` §4 и вопрос Q-23.

---

## 6. Q-1 (HR-домен) — измерение, а не мнение

Сценарий миссии `00_MASTER_PLAN.md:16`: «главбух спрашивает **„кто ходил в отпуск в этом месяце“**,
„сформируй отчёт по параметрам“».

```console
$ grep -rnowiE 'HR|отпуск|отпуска|vacation|payroll|зарплата|кадры|кадровый' \
    05-Projects/03-Packages/AxiomaErpSdk/plans/erp/00_MASTER_PLAN_V3.md \
    ~/projects/packages/erp-sdk/docs/03_Dev/modules/_index.md \
    'MCP_Tools (AiResto).md' 'MCP_Tools (AiFina).md'
00_MASTER_PLAN_V3.md:162: | D36 | Payroll — отдельный продукт-плагин с отдельным решением
                                  (считать vs импортировать); наивный расчёт запрещён.
                                  Ядро в MVP держит только импорт проводок ЗП | ADR-021 |
00_MASTER_PLAN_V3.md:230: | 021 | Payroll Strategy | ☐ deferred (D36) | plugin_salary |
00_MASTER_PLAN_V3.md:373: | Зарплата-лайт | импорт проводок ЗП + зарплатные статьи | MVP (импорт) /
                                  расчёт — v2-плагин | D36/ADR-021 |
00_MASTER_PLAN_V3.md:523: | M3.4 AiFina app | … зарплата-импорт … |
```

Ни одного совпадения в реестре 16 модулей и ни одного в каталогах инструментов обоих продуктов
(`MCP_Tools (AiResto)` — 13 инструментов, `MCP_Tools (AiFina)` — 15; ни один не про кадры).
Ближайшее, что есть в каноне ERP, — **зарплата-лайт как ИМПОРТ проводок** и отдельный
deferred-плагин `plugin_salary` (D36 / ADR-021 `☐ deferred`); учёта отпусков, табеля и кадровых
событий в каноне нет вовсе.

Вывод факта (не решения): сценарий про отпуск **невыразим** ни одним существующим инструментом
контура; чтобы он стал выразимым, продукту нужен новый домен — а это решение владельца продукта
AiFina/AiResto, лежащее вне плана AGENTOOLS. Разбор — `research/09-…` §6.

---

## 7. RAG: внешние факты на 2026-07-27

Ре-верифицируются item'ом P10.6 на дату исполнения (лестница `context7 → perplexity → WebSearch`,
`plan-protocol` §7). Без источника — `[UNVERIFIED]`.

### 7.1 Надёжность function-calling малых локальных моделей

`RAG:✅ 2026-07-27 (perplexity-web: BFCL V4 / Gorilla leaderboard, последнее обновление
2026-04-12; тред r/LocalLLaMA «I tested 11 small LLMs on tool-calling judgment»)`:

| Класс модели | Вердикт источников | Практическое следствие |
|:--|:--|:--|
| топ фронтир (`Qwen3.7 Max`) | ≈75 % на BFCL V4 — верхняя планка публичного борда | «надёжного tool-calling» нет даже наверху: 25 % ошибок в агентных под-категориях |
| **< 4B** | «часто уверенно ошибаются в РЕШЕНИИ, звать ли инструмент, даже когда выдают синтаксически корректный JSON»; 1.5–3B пригодны для triage, не для маршрутизации | валидный JSON ≠ верный вызов — метрика приёмки обязана быть end-to-end |
| **7B** | «первый размер, где локальный tool-calling становится рабочим», особенно с constrained decoding (сообщается скачок до mid-80 % на структурированной генерации); всё ещё хрупок на многошаговых потоках | L1 без constrained decoding и repair-слоя нереалистичен |
| квантизация | Q3 и ниже «роняет надёжность tool-call РАНЬШЕ, чем видимое качество чата»; практический пол — `Q4_K_M` | деградация незаметна по чату — ловится только tool-метрикой |

Источник (первичный, приведён движком): `https://www.reddit.com/r/LocalLLaMA/comments/1qyg10z/i_tested_11_small_llms_on_toolcalling_judgment_on/`
— **социальный источник, не рецензируемый**; числа BFCL V4 приведены движком без прямой ссылки на
снимок борда. Поэтому конкретные проценты помечаются `[UNVERIFIED]` до ре-верификации по
первоисточнику `gorilla.cs.berkeley.edu/leaderboard.html` в item'е P10.6; **направление вывода**
(<4B непригодны для маршрутизации, 7B — порог, тяжёлая квантизация бьёт по tool-call первой)
подтверждено двумя независимыми свидетельствами и является достаточным основанием для матрицы.

### 7.2 On-device inference в Dart/Flutter — путь для L1 существует

`RAG:✅ 2026-07-27 (perplexity-web: pub.dev/packages/llama_cpp_dart,
pub.dev/documentation/mediapipe_genai/latest/, pub.dev/packages/flutter_mediapipe_chat)`:

- **`flutter_gemma`** — кросс-платформенный фреймворк on-device inference (Android/iOS/Web/macOS/
  Windows/Linux) с бэкендами `flutter_gemma_mediapipe` (`.task`/`.bin`) и `flutter_gemma_litertlm`
  (`.litertlm`); метаданные пакета **явно перечисляют function calling** и offline-исполнение как
  first-class возможности; есть opt-in `flutter_gemma_agent` для offline agent-skills поверх
  function-calling-цикла.
- **`llama_cpp_dart`** — FFI-биндинг llama.cpp (GGUF), managed-isolate режим; tool calling
  first-class **не заявлен** — реализуется самим потребителем на уровне промпта/протокола.
- Низкоуровневый мост MediaPipe — `mediapipe_genai`; Google публикует LLM Inference API как
  полностью on-device.

Следствие для отчёта: `[UNVERIFIED]` чужого канона (UV-5, «зрелость on-device small-model 2026»)
на 2026-07-27 **частично снимается по инфраструктуре** (runtime и биндинги в Dart существуют,
function calling у одного из стеков заявлен) и **НЕ снимается по надёжности** (§7.1: <4B не
маршрутизируют, 7B хрупки, квантизация бьёт по tool-call). Оба факта — вход матрицы P10.6, и оба
подлежат ре-верификации на дату исполнения.

### 7.3 Что RAG'ом НЕ проверялось этим ходом

- Русскоязычные малые модели с function calling (входящее от AIRESTO содержит RAG 2026-07-08 с
  тремя источниками и вердиктом «ни одна RU-модель не подтверждена как production-grade on-device
  tool-use движок»); ре-проверка — item P10.6, дублировать сейчас незачем.
- Offline-STT для русского (Vosk / Whisper-класс) — вне контракта нашего SDK
  (`90_inbox/…` п. 5), измеряет AiResto.
- Актуальность `mcp_dart` на дату исполнения — предмет фазы P8 (триггер T-1), фаза P10 читает её
  вердикт, а не перемеряет.

---

## 8. Команды, которыми снята эта разведка

```console
# репозитории и деревья
ls -d ~/projects/packages/axioma_erp_sdk
ls ~/projects/packages/erp-sdk
find ~/projects/packages/erp-sdk -name '*.dart' -not -path '*/.git/*' | wc -l
find ~/projects/packages/erp-sdk -name 'pubspec.yaml' -not -path '*/.git/*' | wc -l
git -C ~/projects/packages/erp-sdk rev-parse --short HEAD
git -C ~/projects/packages/erp-sdk log -1 --format='%ad %s' --date=short
git -C ~/projects/packages/erp-sdk status --short | wc -l
git -C ~/Vaults/Brain rev-parse --short HEAD
git -C ~/projects/packages/agentools rev-parse --short HEAD

# канон и модули
ls ~/projects/packages/erp-sdk/docs/03_Dev/modules/
cat ~/projects/packages/erp-sdk/docs/03_Dev/ADR/'ADR-004 AI via MCP Only.md'
cat ~/projects/packages/erp-sdk/docs/03_Dev/ADR/'ADR-010 AI Write Tiers.md'
cat ~/projects/packages/erp-sdk/docs/03_Dev/ADR/'ADR-012 AI Interaction and Provider Strategy.md'
cat ~/projects/packages/erp-sdk/docs/03_Dev/ADR/'ADR-007 Offline-First Sync.md'
cat ~/projects/packages/erp-sdk/docs/03_Dev/modules/axioma_assistant.md
cat ~/projects/packages/erp-sdk/docs/03_Dev/modules/axioma_mcp.md
sed -n '156,545p' 05-Projects/03-Packages/AxiomaErpSdk/plans/erp/09_AI_Orchestration_UX.md

# чужие планы
grep -n '^## 4\.' -A 20 05-Projects/03-Packages/AxiomaErpSdk/plans/ERP-IMPL/plan.md
grep -n '^| D10 ' 05-Projects/03-Packages/AxiomaErpSdk/plans/ERP-IMPL/plan.md
sed -n '/^## 8\./,/^## 9\./p' 05-Projects/03-Packages/AxiomaErpSdk/plans/erp/00_MASTER_PLAN_V3.md
head -70 05-Projects/01-Products/AiResto/plans/AIRESTO/10_decisions/ERP-GATE-01_dependency_gate.md
grep -n '^## 4\.' -A 20 05-Projects/01-Products/AiResto/plans/AIRESTO/plan.md

# осведомлённость чужого канона о нас + обратный канал
grep -rniE 'agentools|agen tools' 05-Projects/03-Packages/AxiomaErpSdk/ ~/projects/packages/erp-sdk/docs
head -60 '05-Projects/01-Products/AiResto/docs/03_Dev/AI_Offline_Strategy (AiResto).md'
cat 05-Projects/03-Packages/AgenTools/plans/AGENTOOLS/90_inbox/airesto-offline-gap.md

# Q-1
grep -rnowiE 'HR|отпуск|отпуска|vacation|payroll|зарплата|кадры|кадровый' <канон ERP и каталоги инструментов>
grep -n 'отпуск' 05-Projects/03-Packages/AgenTools/plans/AGENTOOLS/00_MASTER_PLAN.md

# пин транспорта
grep -rn 'mcp_dart' 05-Projects/03-Packages/AxiomaErpSdk/plans/erp/

# RAG (perplexity-web search, 2 запроса — §7.1, §7.2)
```

Команды над vault запускались из `~/Vaults/Brain`, над репозиториями — из их корней.
