# R-00 — Внутренняя разведка vault: что уже спроектировано в экосистеме

> Research Sweep AgentKit #1 (2026-07-07). Три параллельных разведчика по vault. Это КЛЮЧЕВОЙ вход: AgentKit проектируется не в вакууме — PHP- и Dart-контуры уже имеют спроектированные agent-слои.

---

## Грань A — AxiomaErpSdk (Dart-контур: axioma_mcp / axioma_assistant / ADR)

## Summary
AxiomaErpSdk уже имеет спроектированный двухчастный AI-слой: axioma_mcp (серверная сторона — in-process MCP-сервер на mcp_dart 2.2.0, реестр типизированных AxiomaTool с tier/permission/argsSchema, ToolGuard, McpAuditLogger) и axioma_assistant (клиентская — InputChannel text/voice, SpeechProvider=YandexSpeechKit, LLMProvider=GigaChat/YandexGPT с нормализацией к OpenAI-подобным tools, IntentRouter, AssistantOrchestrator). Жёсткие ADR: AI только через MCP (ADR-004), write-tiers 0/1/2 с обязательным подтверждением человека на Tier 2 и запретом прямого постинга в финансы (ADR-010, ADR-005), runtime-LLM только российские провайдеры, Anthropic/Claude — dev-only (ADR-012), модули (в т.ч. tools через mcp_tools.dart в манифесте module.json) — только через официальный API ядра (ADR-006). ControLLM и LiveKit в доках AxiomaErpSdk не упоминаются. Стек запиннен 2026-06-13: Dart everywhere, melos 7.8 (pub workspaces, без melos.yaml), Riverpod 3.2.1, Drift 2.34, mcp_dart 2.2.0, Serverpod 3 за адаптером (решение до Этапа 3), Shelf+SQLite для локального контура. AgentKit для Dart-контура должен встраиваться как реализация/расширение контрактов axioma_assistant + axioma_mcp, а не параллельный слой; preview+confirmation уже канонизирован как Tier 2, интерактивные уточнения (AskUserQuestion) — новая точка расширения pipeline AssistantOrchestrator, не противоречащая инварианту «любой ввод → единый pipeline → intent → MCP-tool под tier-политикой».

## Constraints
- AI-доступ к данным в Dart-контуре ТОЛЬКО через MCP-tools axioma_mcp (ADR-004): AgentKit не может ходить в БД/доменные сервисы напрямую и не может вводить параллельный не-MCP tool-реестр
- Write-tier политика неприкосновенна (ADR-010): tier — свойство tool'а; Tier 2 требует явного подтверждения человека на устройстве; AI никогда не постит напрямую в финансовое ядро (максимум Tier 1 draft) — preview+confirmation AgentKit обязан реализовываться поверх этой модели, не вместо неё
- Runtime-LLM для AiFina/AiResto — только российские провайдеры (GigaChat/YandexGPT), ASR — Яндекс SpeechKit; Anthropic/OpenAI запрещены в продуктовом рантайме (ADR-012). Если AgentKit — Python-мост/LiveKit, он всё равно обязан работать через провайдер-агностичный LLMProvider-контракт с рос. моделями
- Dart Everywhere (ADR-001): в контуре Axioma нет Python/Node в проде; AgentKit как отдельный не-Dart сервис — прямое нарушение ADR, допустим только через пересмотр ADR или protocol-first интеграцию (MCP), где Dart-ядро остаётся владельцем tools/tier/аудита
- AgentKit должен встраиваться в существующие контракты: клиентская сторона — как реализация/расширение AssistantOrchestrator/IntentRouter (axioma_assistant), серверная — через AxiomaMcpServer; регистрация tools только через mcp_tools.dart + манифест module.json (ADR-006 Plugin Isolation)
- Фильтрация tools по правам ДО показа модели: недоступный tool не виден LLM вовсе; каждый вызов — в аудит (actor=AI+человек, tool, args, tier, результат, latency) — AgentKit обязан сохранить оба свойства
- Оффлайн-first AiResto (Shelf+SQLite, ADR-003/007): AgentKit не может предполагать постоянное облако; сценарии деградации без сети обязательны (локальная установка может ходить в облачный MCP, но это опция, не требование)
- Текущий контракт LLMProvider возвращает одиночный ToolCall — multi-tool цепочки, сценарии/схемы выполнения и интерактивные уточнения (AskUserQuestion) в SDK не спроектированы: это легальная зона для AgentKit, но расширение сигнатур = breaking change SDK (ADR-009) — проектировать как совместимое расширение контрактов Этапа 1
- SDK ещё не реализован (концепт, репо не создано): AgentKit может влиять на контракты Этапа 1 сейчас дёшево, но стек запиннен — mcp_dart 2.2.0, Riverpod 3.2.1, Drift 2.34, melos 7.8; ControLLM/LiveKit в каноне AxiomaErpSdk отсутствуют и потребуют отдельного ADR
- Ядро приватное: AgentKit как отдельный переиспользуемый пакет не должен требовать открытия внутренностей axioma_* (открытие любого пакета — только через осознанный ADR)

## Findings/Facts
### 
MCP задуман как модуль axioma_mcp: in-process AxiomaMcpServer на пакете mcp_dart 2.2.0 (выбран вместо экспериментального dart_mcp; prod, tool-calling, OAuth2/PKCE). Транспорт в доках явно не зафиксирован — сервер поднимается внутри процесса ядра (mcpServer.registerTool/registerResource/start). Tools типизированы: name, tier, requiredPermission, argsSchema (JsonSchema), execute(args, ctx); ошибка схемы возвращается модели как структурированный отказ.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/modules/axioma_mcp.md]

### 
ADR-004 AI via MCP Only (accepted): единственный канал AI-доступа — MCP-сервер ядра; никакой генерации SQL и прямых function calls в доменные сервисы. Один реестр tools переиспользуется ассистентом продукта, внешними агентами и автоматизациями.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/ADR/ADR-004 AI via MCP Only.md]

### 
ADR-010 AI Write Tiers (accepted): Tier 0 read-only / Tier 1 draft-write (черновики, постинг только человек) / Tier 2 operational-write (только с явным подтверждением пользователя на устройстве). Tier — свойство инструмента, не вызова; прямой постинг в финансовое ядро для AI запрещён всегда; каждый вызов любого tier — в audit trail (AI+инициатор-человек, tool, аргументы, tier, результат, latency).
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/ADR/ADR-010 AI Write Tiers.md]

### 
ADR-012 (accepted): runtime-LLM — только российские провайдеры (GigaChat function_call, YandexGPT toolCallList — function-calling подтверждён research'ем 2026-06); ASR — Яндекс SpeechKit (gRPC streaming STT v3); Anthropic/Claude — только инструмент разработки, не продуктовый runtime. Провайдер-агностичность через абстракции InputChannel/SpeechProvider/LLMProvider/IntentRouter/AssistantOrchestrator. ControLLM в доках SDK не фигурирует.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/ADR/ADR-012 AI Interaction and Provider Strategy.md]

### 
Модуль axioma_assistant (13-й модуль ядра, контракты — Этап 1, реализации — Этап 2 M2.4 на AiResto): pipeline req → (ASR если voice) → текст → LLMProvider.complete(messages, tools из ToolRegistry, отфильтрованных по правам) → нормализованный ToolCall → IntentRouter.route → axioma_mcp (схема→права→tier→исполнение). LlmResponse = {text?, toolCall?} — одиночный tool call, multi-tool цепочки/сценарии в контракте пока не заложены; собственный intent-классификатор — точка расширения/fallback.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/modules/axioma_assistant.md]

### 
Stack Lockfile (запиннен 2026-06-13): melos 7.8.0 (конфиг в корневом pubspec.yaml + pub workspaces, melos.yaml удалён), Drift 2.34 + sqlite3 v3, Riverpod 3.2.1, mcp_dart 2.2.0, Serverpod 3 'Industrial' — за адаптером, финальное решение Serverpod vs Shelf-cloud до Этапа 3 (ADR-003 Dual Backend: Serverpod cloud для AiFina, Shelf+SQLite local для AiResto). isar выкинут. Спайки: SP-1 mcp_dart PoC, SP-8 LLMProvider-адаптер.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/Master_Implementation_Plan (AxiomaErpSdk).md]

### 
Точка встраивания tools: модули/плагины декларируют AI-инструменты через файл mcp_tools.dart и поле "mcp_tools" + permissions (напр. "mcp:tier1") в манифесте module.json (axioma_plugins); незаявленный в манифесте tool отклоняется на регистрации. Права: tool, недоступный пользователю, не виден модели вовсе (не 'виден, но падает').
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/modules/axioma_plugins.md]

### 
Эпик MCP Этапа 1: MCP1 AxiomaMcpServer (mcp_dart), MCP2 AxiomaTool+ToolTier, MCP3 argsSchema, MCP4 ToolGuard, MCP5 McpAuditLogger, MCP6 базовые T0 tools; инвариант-тест #5 — tool без tier/схемы не регистрируется. Примеры tools: fina_revenue_summary (T0), fina_draft_budget (T1), resto_open_shift (T2), resto_open_table (T2).
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/modules/axioma_mcp.md]

### 
Финансовые инварианты: ADR-005 Finance Immutability (двойная запись, storno-only, иммутабельный JournalEntry); AI-проводки идут тем же Ledger.post() — инварианты не обходятся; AI-инициированные финансовые операции дополнительно попадают в AuditTrail axioma_finance с основанием mcp:<tool>.
[src: 05-Projects/03-Packages/AxiomaErpSdk/docs/03_Dev/ADR/ADR-005 Finance Immutability.md]

### 
Ядро приватное (главный защищаемый актив), концепт-стадия: репо ещё не создано (следующий шаг — Фаза 0, создание axioma_erp_sdk). Контракты assistant версионируются вместе с SDK; смена сигнатуры интерфейса = breaking change (SemVer ядра, ADR-009).
[src: 05-Projects/03-Packages/AxiomaErpSdk/AxiomaErpSdk.md]



---

## Грань B — FlexCRM + CoreX (PHP-контур: corex/ai, AiGateway, AzGuard)

## Summary
FlexCRM/CoreX уже имеет спроектированный (не написанный — greenfield, код с нуля) AI/agent-слой: пакет corex/ai с AgentRuntime + единым ToolRegistry (один реестр обслуживает и внешний MCP-сервер, и внутренний агентский loop), AiGateway (обязательная точка LLM-вызовов: ПДн-маска → бюджет → драйвер → ledger → трейс, ADR-020), stake-семантикой подтверждений (low/med/high, HITL через ai_tool_calls.approved_by, тред в статус waiting_approval), diff-карточками preview+confirm и полным аудитом. Агент — полноправный principal AzGuard (principal_type='agent'), эффективные права = intersection(права инициатора, роль агента), тулза не попадает в каталог без права, write re-check при execute. Стек: PHP 8.5 / Laravel 13 / Filament 5 / Inertia 3 / Reverb (realtime) / Horizon / PostgreSQL-first / laravel/ai+laravel/mcp за адаптером собственных контрактов. AgentKit для PHP-грани по сути должен либо стать corex/ai, либо лечь под его контракты (ToolProvider/ToolContext/ChatDriver) — параллельная независимая абстракция создаст конфликт с уже принятыми ADR-009/020 и спекой B-13.

## Constraints
- PHP-грань AgentKit не может обходить AiGateway (ADR-020, accepted): любой LLM/STT/embedding-вызов — только через фиксированный middleware-пайплайн маска ПДн → бюджет → драйвер → ledger → трейс; внешний прокси-сервис (LiteLLM-подобный, т.е. и Python-мост как сетевой прокси LLM-вызовов) уже явно отвергнут в этом ADR — лишний хоп, дублирование бюджет-состояния вне тенантской БД (БД-на-аккаунт, D1)
- Агент обязан быть principal AzGuard (principal_type='agent'); эффективные права = intersection(права инициатора, роль агента); тулза без права не попадает в каталог, scope накладывается ScopeResolver'ом в SQL, write-тулзы re-check при execute — permissions-слой tools AgentKit должен ложиться на эти контракты (AccessChecker/ScopeResolver, B-11), не изобретать свой
- Контракты уже зафиксированы и не ломаются без major (принцип CoreX Contract-First): ToolProvider {category, descriptors(ToolContext), execute(tool,args,ToolContext)}, ChatDriver/SttDriver, stake low/med/high + HITL через ai_tool_calls.approved_by, command envelope {intents:[{type,payload,source_text,confidence,needs_confirmation}]}, confidence<0.6 → уточняющий вопрос — AgentKit-PHP должен реализовать/адаптировать именно их
- Один ToolRegistry на MCP-сервер и внутренний AgentRuntime — тулза пишется один раз; AgentKit не должен вводить второй реестр tools
- Стек запиннен (D4): PHP 8.5 / Laravel 13 / Filament 5 / Inertia 3 / Reverb / Horizon / PostgreSQL-first; база LLM-слоя — laravel/ai + laravel/mcp за адаптером; boring tech без RFC; MIT для ядра, greenfield (D8)
- Realtime-доставка интерактива (уточняющие вопросы, HITL-approve, стрим) — Laravel Reverb private-каналы через BroadcastChannelPrefixBootstrapper (единые креды + префикс); per-tenant broadcast-конфиг запрещён (баг stancl #1448)
- Tenancy: физическая БД на аккаунт + поддомен; состояние агента/бюджетов/аудита живёт в тенантской БД — общий stateful-сервис оркестрации поверх всех тенантов конфликтует с D1
- Boxed-режим (on-prem) обязан работать: нет ключей LLM → ai.enabled=false с полной функциональностью CRM; Reverb в docker-compose; self-hosted embeddings для «данные не покидают контур»
- 152-ФЗ: ПДн-маскирование (HMAC-псевдонимизация) до любого внешнего LLM обязательно, но юридически недостаточно само по себе (нужна договорная база) — юр. sign-off открыт; поля no_ai исключаются из RAG/tool-ответов
- Новый функционал в пакеты corex/* — через package-contribution-протокол: вклад в corex/ai (где AgentKit-функциональность уже размещена спекой B-13/R-06), а не параллельный пакет с дублирующими абстракциями
- Аудит обязателен: каждый tool-вызов агента → ai_tool_calls + общий Audit Log; ACTOR-конвенция {source: agent,...} на всех порождённых записях; timeline-событие agent_action
- Бюджеты per-tenant/agent/run обязательны (ai_budgets, max_steps/max_tokens на прогон) — AgentKit-loop без enforcement лимитов нарушает ADR-020

## Findings/Facts
### 
CoreX = 17 Composer-пакетов (модулей) с contract-first принципом: corex/core (DI, Events, EntityRegistry, Settings, Audit Log, Feature Flags, Multi-Tenancy), corex/auth, corex/ai, corex/crm, corex/tasks, corex/workflow, corex/messenger, corex/filament, corex/api, corex/commerce, corex/blog(cms), corex/media, corex/1c-sync, corex/billing, corex/notifications, corex/search, corex/import-export; в модульных спеках уже де-факто 19 (добавлены corex-panels, corex-tenancy)
[src: 05-Projects/03-Packages/CoreX/CoreX.md]

### 
Нейминг (D17, финал): corex/* = ядро, PHP namespace `Corex\`; flex/* = продукты и OSS-выносы, namespace `Flex\` (flex/modules, flex/attributes, flex/workflow, flex/import-export). Дистрибуция — собственный composer-репозиторий, не публичный Packagist
[src: plans/flex/10_decisions/D-003_naming.md]

### 
Конвенция создания нового пакета: composer.json `type: corex-module` + `extra.corex.module` = класс-манифест; команда `make:module {vendor/name}` генерирует скелет (манифест-класс, ServiceProvider, testbench-приложение по паттерну Sylius plugin-skeleton, CI, README); модуль без тенантных фич обязан работать как обычный Laravel-пакет (package discovery). Расширения — декларативные Extend-классы (Entity, EntityField, Permissions, Api, Filament, Menu, Routes, Workflow), компилируются RegistryCompiler в детерминированном порядке; табличный префикс модуля ≤3 символов + '_'
[src: plans/flex/30_blueprints/B-10_core-module-loader.md]

### 
AI/agent-слой уже детально спроектирован: corex/ai содержит AgentRuntime (loop thread/turn/tool-call с max_steps/max_tokens из иммутабельной ai_agent_versions), единый ToolRegistry с контрактом ToolProvider {category(), descriptors(ToolContext, withSchemas), execute(tool, args, ToolContext): ToolResult}; corex/crm регистрирует свои ToolProvider'ы (инверсия: corex/ai не знает про CRM). Один ToolRegistry обслуживает и MCP-сервер, и внутренний AgentRuntime (twenty-паттерн: тулза пишется один раз)
[src: plans/flex/20_research/R-06_ai-agents.md]

### 
MCP-сервер FlexCRM v1: POST https://{account}.flexcrm.ru/mcp (Streamable HTTP, laravel/mcp); auth v1 = Sanctum PAT (abilities mcp:read/mcp:write), v1.1 = OAuth 2.1 + PKCE; правила ответов: response_format concise|detailed, пагинация next_cursor, лимит ≤50, human-readable ids, ошибки с примером валидного вызова. Тулзы — workflow-уровня и «объясняющие» (контекст-пакеты, дубликат-чеки), не 1:1 REST
[src: plans/flex/30_blueprints/B-13_crm.md]

### 
Preview+confirmation уже канонизирован как stake-семантика: low — без подтверждения; med — подтверждение отключаемо настройкой агента; high — всегда HITL (ai_tool_calls.approved_by, тред → waiting_approval; в workflow — approval-узел). Command envelope для интерактива: {intents: [{type, payload, source_text, confidence, needs_confirmation}]}; confidence < 0.6 → уточняющий вопрос, не действие; N совпадений resolver'а («какой из двух Ивановых?») → выбор в UI; цепочка TranscriptCreated → IntentExtracted → ActionDrafted → UserConfirmed → MutationApplied персистится в ai_messages + ai_tool_calls. Это ровно требования AskUserQuestion/preview из брифа AgentKit — уже решено на уровне спеки
[src: plans/flex/30_blueprints/B-13_crm.md]

### 
ADR-020 AiGateway (accepted): единственная точка любого LLM/STT/embedding-вызова, фиксированный порядок middleware маска ПДн → budget-check → драйвер → usage-ledger → трейс; прямой вызов драйверов запрещён. Иерархия бюджетов ai_budgets (глобальный → account → workspace/agent → сессия), soft 80% → downgrade модели, hard 100% → LLM_QUOTA_EXCEEDED (429). Внешний прокси (LiteLLM/Portkey) отвергнут явно — гейтвей строится внутри Laravel-пакета corex/ai. База — laravel/ai v0.8 + laravel/mcp за адаптером собственных контрактов ChatDriver/EmbeddingDriver/SttDriver/ToolProvider; драйверы YandexGPT (OpenAI-compat), GigaChat (свой), OpenAiCompat; декоратор RetryingStructuredOutput (валидация JSON-схемой + retry для слабых RU-моделей)
[src: 05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-020 AiGateway.md]

### 
RBAC: corex/auth = надстройка aut_* поверх AzGuard (ADR-009); permission keys {panel}.{entity}.{action}; матрица aut_role_matrix role×entity×action×scope (none/own/dept/dept_tree/workspace/account, max-scope-wins, контекстные строки для стадийных прав); principal-типы user/agent/api_key в aut_role_assignments — агент полноправный principal; права агента при запуске от пользователя = intersection(права инициатора, роль агента), автономные запуски — роль агента + run_as; enforcement пересечения — в corex/ai. AccessChecker/ScopeResolver ≤1мс; тулза без capability-права отсутствует в каталоге, ScopeResolver накладывает scope-предикат в SQL, write-тулзы re-check при execute (инвариант B-13 §7 п.5)
[src: plans/flex/30_blueprints/B-11_auth-tenancy.md]

### 
Стек FlexCRM (D4, зафиксирован): PHP 8.5 / Laravel 13 / Filament 5 (admin) / Inertia 3 SPA (JS-фреймворк за Inertia явно не переподтверждён, прежний Vue 3 снят из канона) / Pest 4; очереди Redis + Horizon; PostgreSQL-first (jsonb/GIN, ltree, pgvector); tenancy = физическая БД + поддомен на аккаунт (stancl/tenancy v4, FPM в v1 — Octane не поддержан). Репозиторий flexcrm пересоздаётся greenfield, старый код не мигрируется
[src: 05-Projects/01-Products/FlexCRM/docs/03_Dev/Architecture_FlexCRM.md]

### 
Канал доставки интерактива в UI: Laravel Reverb — единый realtime-транспорт (broadcast p95 ≤300мс от коммита, afterCommit listener + очередь redis); каналы private-cht.{channel_id} (Chatom-мессенджер), private-ntf.{user_id} (corex/notifications in-app); мультитенантный broadcast только через BroadcastChannelPrefixBootstrapper (единые Reverb-креды с префиксом, per-tenant конфиг отвергнут из-за бага stancl #1448). В boxed-режиме Reverb — обязательный компонент docker-compose. HITL-ожидание в DDL-пайплайне уже использует broadcast/polling. Совместное редактирование — НЕ Reverb+Yjs (отвергнуто: нет серверного Y.Doc для PHP), а Hocuspocus-sidecar (Vaulter)
[src: plans/flex/30_blueprints/B-16_satellite-contracts.md]

### 
Чат: ChatomIo/chatom/core — транспорт corex/messenger (ADR-007), схема cht_* (каналы discussion/comments/dm), паттерн «панель на сущности» (panelable_type/id + двухуровневый gate: AzGuard-право на родителя + participant-роль панели). AI-треды хранятся отдельно от чата: ai_threads (source: chat|voice|trigger|workflow, context_entity_type/id), ai_turns, ai_messages, ai_tool_calls — т.е. агентский диалог не является Chatom-каналом
[src: plans/flex/30_blueprints/B-16_satellite-contracts.md]

### 
Пресетные агенты FlexCRM v1 (проприетарные, промпты обновляются релизами, версии иммутабельны): summarizer звонков, planner («следующие шаги» по сделке, task_create=med → HITL confirm), reminder-agent (cron, tools-as-triggers, dedupe_key). Голос: voice → STT → массив типизированных команд (CreateTask, CreateNote, UpdateDeal...) с source_text/confidence → diff-карточка → подтверждение (в т.ч. голосовое «да, создавай»)
[src: plans/flex/30_blueprints/B-13_crm.md]

### 
Аудит: ai_tool_calls (tool_code, args, stake, approved_by, entity_type/entity_id, result_status, duration_ms) + событие в общий Audit Log corex/core; ACTOR-конвенция jsonb {source: user|agent|api|workflow|import|system, id, name} на всех записях; timeline crm_tml принимает тип agent_action. Полный DDL всех ai_*-таблиц — признанный гэп (B-99 п.2), отдельная task-card, спеки B-1X для AI-домена в программе нет
[src: plans/flex/30_blueprints/B-13_crm.md]

### 
Деградация: нет ключей LLM-провайдеров → ai.enabled=false: агентские разделы скрыты, MCP отдаёт только read-тулзы без RAG, CRM полностью функционален. ПДн: маскирование HMAC-токенами (ai_pii_map) до эмбеддинга и логов; псевдонимизация ≠ обезличивание по 152-ФЗ/233-ФЗ — правовое основание отправки в облачный LLM = договорная база (DPA), юридический sign-off не получен (открытый риск); флаг no_ai исключает поля из RAG и tool-ответов
[src: 05-Projects/03-Packages/CoreX/docs/03_Dev/ADR/ADR-020 AiGateway.md]

### 
Flex Design Program (plans/flex): этапы 0–8 полностью завершены и исполнены в vault (33+8+8 task-cards); реализация кода ведётся по 50_execution/00_EXECUTION_ROADMAP.md. Ключевые входы по AI: R-06_ai-agents.md (агентские платформы, stake-levels Dust, HITL Zapier, progressive tool discovery при >15 тулзах), R-16_ai-runtime.md (laravel/ai v0.8 за адаптером, self-hosted TEI + USER-bge-m3 embeddings)
[src: plans/flex/00_MASTER_PLAN.md]



---

## Грань C — Loqum (LiveKit) + ControLLM + offline-llm-mcp

## Summary
Loqum — приватный platform-сервис realtime-голосового диалога (host = Edufy), Phase 0: стек зафиксирован, ядро = Python на LiveKit Agents 1.x поверх self-hosted LiveKit (SFU/TURN/egress), RU-провайдеры (Yandex SpeechKit/SaluteSpeech, YandexGPT/GigaChat), event-протокол по таксономии OpenAI Realtime + собственные семейства interactive.*/tool.*. У Loqum уже спроектирована модель «интерактивная команда = client-executed tool» с каталогом CommandDefinition (JSON Schema args, ack/revoke, TTL, policy до dispatch) — это концептуально ровно тот же паттерн, что нужен AgentKit для preview/confirmation и уточнений. ControLLM — концепт (репо нет) OSS LLM-шлюза: OpenAI-совместимый прокси (смена BASE_URL), токенометрия, лимиты, биллинг, Go/Node ядро; идеально ложится как LLM-точка доступа AgentKit, но пока это чистое проектирование. Прочие наработки: идея AiFina offline-llm-mcp (локальная LLM + MCP-tools + STT/TTS в закрытом контуре) — прямой предок AiFina-стороны AgentKit; AxiomaErpSdk уже AI-native с MCP (mcp_dart). Вывод по грани: LiveKit-опыт есть только в форме глубоко проработанного ТЗ (не кода), заточен на sub-second voice, а не на текстовый agent+tools loop — LiveKit Agents как ядро AgentKit был бы overkill; но протокольные паттерны Loqum (typed events, closed-world tool catalog, lifecycle proposed→validated→dispatched→executed/revoked, идемпотентность, ack как аудит план/факт) стоит переиспользовать protocol-first.

## Constraints
- LiveKit-инфраструктура как код НЕ существует — Loqum на Phase 0 (только ТЗ/ADR, PoC-гейты не пройдены); AgentKit не может «переиспользовать инфраструктуру Loqum», только протокольные паттерны и будущий Python-стек. Планировать голосовой канал FlexCRM/AiFina как позднейшую интеграцию с Loqum, а не как зависимость AgentKit v1.
- LiveKit Agents заточен на realtime voice (VAD, barge-in, комнаты, sub-second latency) — для текстового intent→tools→preview→confirm цикла FlexCRM/AiFina это overkill; если брать Python-мост, брать его не ради LiveKit Agents. Зато Loqum задаёт готовый шов: голос позже подключается как отдельный media-канал, где Loqum-агент вызывает tools AgentKit.
- Переиспользовать protocol-first паттерн SPEC-05 Loqum для AskUserQuestion/preview+confirm AgentKit: команды-как-данные с id, ack, revoke, TTL, lifecycle, JSON Schema args, closed-world каталог tools per-session, policy-валидация на сервере ДО показа/исполнения (anti prompt-injection), идемпотентность, ack план/факт как основа аудита. Держать инвариант «ядро не знает семантики tools».
- ControLLM — правильный кандидат на LLM-шлюз AgentKit (единый BASE_URL, токен-учёт per-user, лимиты), но он concept без репо и без выбранного движка (Go vs Node) — AgentKit должен зависеть от него как от опционального OpenAI-совместимого endpoint (просто конфигурируемый base_url), не как от жёсткой зависимости; тогда ControLLM подключается позже без изменений AgentKit.
- В экосистеме уже три языка вокруг агентов: PHP (FlexCRM/CoreX, botkit), Dart (AxiomaErpSdk с mcp_dart, AI-native tools), Python (ядро Loqum). ADR-002 ControLLM даёт прецедент внутренней нормы: бизнес-логика один раз на одном движке, клиентские SDK — тонкая генерируемая «глазурь». Loqum решает полиглотность через codegen типов из JSON Schema (packages/js/protocol как источник) — тот же приём применим к AgentKit protocol-first.
- Для локальных установок AiResto/AiFina (закрытый контур, идея offline-llm-mcp) AgentKit должен допускать малые локальные LLM через Ollama/OpenAI-совместимый API и опору на подробные tool-descriptions — т.е. протокол не должен предполагать большие модели или облачные фичи (обязательный streaming usage и т.п.).

## Findings/Facts
### 
Loqum — приватный externalized-runtime platform-сервис для low-latency голосового диалога человек↔ИИ-агент; вырос из Edufy (ИИ-тьютор), подключается к host-продуктам через SDK/API, project=tenant. Стадия: Phase 0 (документация v3, план утверждён), кода Phase 1 ещё нет; репо /home/vostrikov/projects/packages/loqum.
[src: 05-Projects/03-Packages/Loqum/Loqum.md]

### 
LiveKit в Loqum используется двумя слоями: (1) media plane = self-hosted LiveKit server (SFU, TURN, track egress, комнаты); (2) оркестратор = Python agent worker на livekit-agents 1.x (ADR-017, принят 2026-07-03, superseded ADR-007). Пайплайн: кастомные STT/TTS-плагины Yandex/Sber с word_timings, Silero VAD v6 + smart-turn v3.2 in-process, server-driven barge-in (truncate audio_end_ms), typed-протокол поверх LiveKit data channels/text streams/perform_rpc. Node/TS — только protocol/sdk пакеты (codegen из JSON Schema), PHP — SDK host'а.
[src: 05-Projects/03-Packages/Loqum/docs-private/03_Dev/ADR/ADR-017_LiveKit_Agents_Foundation.md]

### 
Риски LiveKit Agents, зафиксированные Loqum: framework churn (rewrite 0.x→1.0, анонсированный 2.0, до 7 релизов/мес), дифференциаторы сидят на experimental/приватных API — поэтому жёсткий пин версии, 4 anti-corruption шва, контрактные тесты, escape hatch (свой оркестратор на livekit rtc SDK). Опыт пока документный: 2 PoC-гейта до Phase 2 ещё не пройдены.
[src: 05-Projects/03-Packages/Loqum/docs-private/03_Dev/ADR/ADR-017_LiveKit_Agents_Foundation.md]

### 
SPEC-05 Loqum — готовый спроектированный протокол «интерактивная команда = client-executed tool»: CommandDefinition в registry проекта (name@version, args_schema JSON Schema 2020-12, ack required/none, revocable, ttl, description_for_llm), события interactive.command/ack/revoke, lifecycle proposed→validated→scheduled→dispatched→executed/expired/revoked/failed, policy-слой (allowlist capabilities.commands сессии) ДО dispatch как защита от prompt injection, идемпотентность по command_id, ack с executed_at_ms как аудит план/факт. Граница tool.* (server-executed, backend) vs interactive.* (client-executed UI) — по исполнителю. Инвариант: ядро не знает семантики команд, новый host подключается без строки в ядре.
[src: 05-Projects/03-Packages/Loqum/docs-private/03_Dev/specs/SPEC-05_Interactive_Commands.md]

### 
ControLLM — стадия concept, репозитория нет. Замысел: OSS (MIT, Open-Core) stand-alone/sidecar LLM-прокси: клиент меняет только BASE_URL у официального OpenAI SDK; контур — проверка баланса/лимита до пересылки, форвард провайдеру, извлечение usage (для stream — принудительная инжекция stream_options.include_usage, ADR-001), списание токенов, 429 при исчерпании. Ядро строго на одном быстром языке (Go/Node — выбор не сделан, ADR-002); дистрибуция Docker/бинарь + тонкие SDK-обёртки (controllm-laravel, controllm-flutter).
[src: 05-Projects/03-Packages/ControLLM/docs/03_Dev/Architecture_Gateway.md]

### 
ControLLM уже прописан как потенциальный контур для будущих потребителей AgentKit: FlexCRM (месячный лимит токенов на пользователя CRM), CoreX corex/ai+corex/billing, AiFina/AxiomaErpSdk (учёт LLM-вызовов AI-runtime ERP), Tariffler как источник тарифов. У Loqum ControLLM указан как потенциальный LLM-gateway seam в provider abstraction.
[src: 05-Projects/03-Packages/ControLLM/ControLLM.md]

### 
AiFina имеет идею offline-llm-mcp: закрытый контур с локальной LLM (Qwen 4-8B, Ollama-совместимый API) + MCP-клиент + MCP-серверы с aifina-tools (отчёты, API, фискальные чеки) + подключаемые STT/TTS (облако или локальные Whisper/Kokoro). Тезис: подробные tool-descriptions позволяют обходиться малой моделью. Это прямой прототип локального режима AgentKit для AiResto/AiFina on-prem.
[src: 05-Projects/01-Products/AiFina/docs/00_Ideas/offline-llm-mcp.md]

### 
Loqum event protocol v1 построен по таксономии OpenAI Realtime (snake_case envelope, семейства interactive.*/timeline.*/recording.*), что даёт совместимость с Yandex Realtime S2S; auth — host-issued JWT с типизированными grants + одноразовые connection-тикеты (паттерн из AzGuard).
[src: 05-Projects/03-Packages/Loqum/Loqum.md]



