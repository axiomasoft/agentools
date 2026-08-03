# R-02 — Dart/Flutter agent-экосистема (июль 2026)

> Research Sweep AgentKit #1 (workflow-фанаут, 2026-07-07). RAG: perplexity-web + WebFetch, критичные факты прошли adversarial-verify (см. R-07_verification.md).
> Факты без источника помечать [UNVERIFIED].

## Summary
Dart-экосистема agent/tool-инструментов к июлю 2026 созрела достаточно, чтобы построить полноценный agent loop в чистом Dart без Python-моста. Есть два зрелых MCP SDK: официальный dart_mcp от Dart team (v0.5.2, спека 2025-11-25, elicitation form+URL через миксины) и коммьюнити mcp_dart от leehack (v2.2.2, спека 2025-11-25 + tasks, 132k загрузок) — оба server+client. Главное событие — Genkit Dart (genkit 0.14.1, verified genkit.dev): полноценный «AI Agent Toolkit» с flows, tools, typed-схемами, middleware (tool-approval), genkit_mcp/genkit_shelf/genkit_openai/genkit_anthropic и, критично для AgentKit, interrupts — нативный примитив pause/resume/restart для human-in-the-loop, ровно паттерн AskUserQuestion и preview+confirmation. Альтернатива — dartantic_ai (v3.4.2, Chris Sells): провайдер-агностичный agent loop с multi-step tool calling, MCP и custom baseUrl. langchain_dart жив, но высокоуровневый пакет замедлился (0.8.1, ~6 мес назад); при этом его низкоуровневые клиенты openai_dart 3.0.0 и anthropic_sdk_dart 6.0.0 обновляются активно и дают custom baseUrl для российских OpenAI-совместимых API. flutter_ai_toolkit + firebase_ai — только чат-UI и транспорт к Gemini, не агентный фреймворк. Serverpod нативной AI/MCP-поддержки не имеет — агентный runtime живёт рядом как обычный Dart-код. Вывод: Dart-runtime AgentKit реализуем на чистом Dart и на сервере (Serverpod/Shelf), и во Flutter; строить на Genkit Dart (или тонком собственном loop поверх dart_mcp + openai_dart), state machine сессии — своя.

## Recommendation
Полноценный Dart-runtime для AgentKit реализуем в чистом Dart — Python-мост со стороны Dart-грани НЕ нужен. Рекомендуемый стек: (1) протокольный слой tools — официальный dart_mcp (dart-lang/ai): официальная поддержка Dart team, elicitation form+URL закрывает стандартизованные уточняющие вопросы на уровне протокола, что важно для protocol-first гибрида (общий MCP-контракт с PHP-стороной FlexCRM); mcp_dart (leehack) — запасной вариант, если понадобятся MCP tasks. (2) Agent loop — два пути: (а) Genkit Dart как runtime-основа: interrupts дают готовые примитивы ровно под требования AgentKit (AskUserQuestion-уточнения, tool-approval / preview+confirmation через interruptRestart), плюс genkit_shelf для AiResto (local Shelf), совместимость с Serverpod как обычный Dart-код, genkit_openai для российских OpenAI-совместимых LLM, Dev UI с трейсами для отладки цепочек; риск — статус preview и привязка к темпу Google; (б) собственный тонкий loop (~500-1000 строк) поверх dart_mcp + openai_dart с custom baseUrl — полный контроль, нулевая vendor-зависимость, state machine сессии на своём statecraft; дороже в поддержке. Прагматично: спроектировать AgentKit protocol-first (интерфейсы AgentSession/ToolRegistry/ClarificationRequest/ConfirmationGate как свои абстракции), первую реализацию Dart-runtime сделать адаптером над Genkit Dart, сохранив возможность заменить на собственный loop без слома API. dartantic_ai держать как референс дизайна agent loop, langchain_dart как основу не брать (замедлился, лишняя абстракция), flutter_ai_toolkit использовать только как донор UI-паттернов чата во Flutter. State machine агент-сессии (idle→clarifying→preview→confirmed→executing→audit) ни один пакет не даёт — это и есть добавленная стоимость AgentKit, писать свою.

## Findings/Facts
### mcp_dart (leehack)
v2.2.2 (конец июня 2026), verified publisher leehack.com, 67 лайков, 132k загрузок, 160 pub points. Server + client SDK. Поддерживает MCP-спеку 2025-11-25 с обратной совместимостью до 2024-10-07. Заявлены все capabilities: Tools, Resources, Prompts, Sampling, Roots, Completions, Elicitation, Tasks. Dart 3, все платформы включая Web.
[src: https://pub.dev/packages/mcp_dart]

### dart_mcp (официальный, dart-lang/ai)
v0.5.2 (29 июня 2026), SDK ^3.7.0. Server и client через миксины (ToolsSupport, ResourcesSupport, SamplingSupport и т.д.). Elicitation: form-based с v0.3.0 (спека 2025-06-18), URL-based с v0.5.0 (спека 2025-11-25) — отдельные миксины ElicitationFormSupport и ElicitationUrlSupport. Автовалидация input-схем tools. В монорепо также dart_mcp_server — MCP-сервер Dart-тулинга, поставляется в составе Dart SDK (`dart mcp-server`). Пакета dart_agent / агентного фреймворка в dart-lang/ai НЕТ.
[src: https://pub.dev/packages/dart_mcp/changelog, https://github.com/dart-lang/ai]

### langchain_dart
Жив, но высокоуровневый пакет замедлился: langchain 0.8.1 опубликован ~6 месяцев назад (~январь 2026), открыт call for collaborators. Есть кросс-провайдерный ToolsAgent (OpenAI/Anthropic/Ollama), экспериментальный langgraph v0.0.1-dev. Низкоуровневые клиенты того же автора (davidmigloz) очень активны: openai_dart 3.0.0, anthropic_sdk_dart 6.0.0 (опубликован за 2 дня до 2026-07-07).
[src: https://pub.dev/packages/langchain, https://github.com/davidmigloz/langchain_dart/releases]

### Genkit Dart
genkit 0.14.1 (~июнь 2026), verified publisher genkit.dev, позиционируется как 'AI Agent Toolkit' для pure Dart client/server и Flutter. Flows, tools с typed-схемами (@Schema()/schemantic), streaming, Dev UI с трейсами. Экосистема плагинов: genkit_google_genai, genkit_openai, genkit_anthropic, genkit_mcp, genkit_middleware (tool-approval interrupts, retry), genkit_shelf (сервер), genkit_firebase_ai. Ключевое для AgentKit — interrupts: interrupt-tool ставит generation loop на паузу, приложение показывает вопрос/подтверждение пользователю, затем resume с payload ответа или interruptRestart для повторного запуска tool с обновлённым состоянием (approved=true). Это готовый примитив для AskUserQuestion-уточнений и preview+confirmation перед write-действиями. Статус — активный preview (репо genkit-ai/genkit-dart, коммиты до конца мая 2026), на pub.dev как experimental не помечен.
[src: https://pub.dev/packages/genkit, https://genkit.dev/docs/dart/interrupts/, https://github.com/genkit-ai/genkit-dart]

### flutter_ai_toolkit + firebase_ai
flutter_ai_toolkit (flutter/ai) — набор чат-виджетов и chat-state для Flutter, не агентный фреймворк: multi-tool оркестрация и подтверждения остаются на бэкенде. Пример мигрирован на firebase_ai (Firebase AI Logic) — предпочтительный транспорт к Gemini из Flutter-клиента (auth, лимиты). Годится как UI-слой поверх AgentKit-runtime, не как ядро.
[src: https://docs.flutter.dev/ai/ai-toolkit, https://firebase.google.com/docs/ai-logic/get-started]

### dartantic_ai
v3.4.2 (начало июля 2026), verified publisher sellsbrothers.com (Chris Sells), 58 лайков, 8.16k загрузок. Агентный фреймворк: multi-step tool calling (автоматическое чейнинг tools), MCP-клиент, streaming, typed outputs, провайдеры OpenAI/OpenAI Responses/Anthropic/Google/Mistral/Ollama + любые OpenAI-совместимые через custom baseUrl. Более лёгкая vendor-нейтральная альтернатива Genkit, но фактически one-man проект с меньшей базой пользователей.
[src: https://pub.dev/packages/dartantic_ai]

### LLM-клиенты для российских OpenAI-совместимых API
openai_dart 3.0.0 — самый полный: Chat Completions + Responses API, tool calling, streaming, structured outputs, custom baseUrl через OpenAIConfig (подходит для GigaChat/YandexGPT-совместимых прокси и gateway типа ControLLM). openai_core 0.10.1 — компактный клиент с baseUrl+headers, Realtime (WebSocket/WebRTC) и ResponsesSessionController с автоматическим прогоном tool-calls до финального ответа. anthropic_sdk_dart 6.0.0 — свежий, tool use, streaming, extended thinking, pure Dart без Flutter-зависимости.
[src: https://pub.dev/packages/openai_dart, https://pub.dev/packages/openai_core, https://pub.dev/packages/anthropic_sdk_dart]

### Serverpod и AI
В Serverpod 3.x (3.3.0) нет нативной поддержки AI-агентов, LLM или MCP ни в доках, ни в публичном roadmap. Агентный runtime для AiFina размещается как обычный Dart-код внутри endpoints/сервисов Serverpod; MCP-сервер — отдельный слой поверх его API.
[src: https://docs.serverpod.dev (roadmap 3.3.0)]

### Реализуемость agent loop в чистом Dart
Все компоненты закрыты: LLM tool calling (openai_dart/anthropic_sdk_dart/genkit-плагины), автоматический multi-turn loop (Genkit flows, dartantic_ai, openai_core ResponsesSessionController), уточняющие вопросы с вариантами (Genkit interrupts — прикладной уровень; MCP elicitation form/URL — протокольный уровень, оба Dart MCP SDK поддерживают), tool-сервер для доменных операций (dart_mcp или mcp_dart, работают в Serverpod/Shelf и во Flutter кроме stdio на Web). Готовой state machine агент-сессии нет ни в одном пакете — писать свою (кандидат — собственный statecraft).
[src: агрегировано из источников выше]

## Sources
- https://pub.dev/packages/dart_mcp/changelog
- https://github.com/dart-lang/ai
- https://pub.dev/packages/mcp_dart
- https://pub.dev/packages/langchain
- https://github.com/davidmigloz/langchain_dart/releases
- https://pub.dev/packages/genkit
- https://genkit.dev/docs/dart/interrupts/
- https://github.com/genkit-ai/genkit-dart
- https://developers.googleblog.com/en/build-agentic-full-stack-apps-with-genkit/
- https://pub.dev/packages/dartantic_ai
- https://pub.dev/packages/openai_dart
- https://pub.dev/packages/openai_core
- https://pub.dev/packages/anthropic_sdk_dart
- https://docs.flutter.dev/ai/ai-toolkit
- https://firebase.google.com/docs/ai-logic/get-started
- https://docs.serverpod.dev
