# R-05 — LiveKit Agents / Python-мост: оценка

> Research Sweep AgentKit #1 (workflow-фанаут, 2026-07-07). RAG: perplexity-web + WebFetch, критичные факты прошли adversarial-verify (см. R-07_verification.md).
> Факты без источника помечать [UNVERIFIED].

## Summary
LiveKit Agents (Python) на июль 2026 — зрелый фреймворк v1.6.4 (24.06.2026) с архитектурой Worker→Job→AgentSession, нативным MCP, async tools и первоклассным text-only режимом (lk.chat/lk.transcription без STT/TTS). Интеграция с Laravel возможна через community PHP SDK (токены JWT + Server API + Agent Dispatch), с Flutter — через официальный зрелый livekit_client (text streams, performRpc). Но self-hosted эксплуатация — это «маленькая RTC-платформа»: SFU + Redis (обязателен для multi-node) + TURN + UDP-диапазоны + host networking в K8s + long-lived workers с graceful drain 10+ минут; воркеры 4c/8G держат 10–25 сессий. Консенсус экосистемы 2026: LiveKit/Pipecat — edge-слой для голоса, а не ядро текстовой бизнес-оркестрации; для back-office text-агентов признанный паттерн — text-центричное ядро (OpenAI Agents SDK, LangGraph, собственный оркестратор) с голосовыми фреймворками на периферии. Вердикт: (а) как ГОЛОСОВОЙ канал LiveKit — сильный кандидат, особенно с учётом опыта Loqum; (б) как ЕДИНОЕ agent-ядро для всех продуктов включая текстовые формы — overkill и антипаттерн: лишний runtime, сетевой хоп через WebRTC-room для request/response-чата, тяжёлый ops, и полная несовместимость с offline-first AiResto (локальный Shelf без облака). AskUserQuestion/preview+confirm LiveKit всё равно не даёт — это прикладной протокол поверх любого транспорта.

## Recommendation
Разделить два вопроса и ответить на них по-разному. (а) LiveKit как ГОЛОСОВОЙ канал — да, сильный кандидат: зрелый voice pipeline, официальный Flutter SDK с text streams, community PHP SDK для токенов/dispatch достаточен (Laravel — только auth/billing/token endpoint), плюс уже есть экспертиза из Loqum. Но включать его СЛЕДУЕТ только для продуктов, где голос реально в roadmap (диктовка команд в FlexCRM), и лучше стартовать с LiveKit Cloud, а не self-hosted — эксплуатация SFU+Redis+TURN+workers это отдельный инфраструктурный домен. (б) Python/LiveKit как ЕДИНОЕ agent-ядро AgentKit — НЕ рекомендую, это overkill и антипаттерн по консенсусу 2026: текстовая форма «сформируй отчёт» превращается в WebRTC-room + agent dispatch + stateful Python worker ради одного tool-loop; добавляется третий runtime и сетевой хоп; история/аудит всё равно на стороне бэкенда (streams не персистятся); ключевые фичи AgentKit (AskUserQuestion с вариантами, цепочки tools, сценарии, preview+confirm, аудит) LiveKit не даёт — их пришлось бы строить поверх в любом случае; и главное — offline-first AiResto (локальный Shelf на Linux без облака) физически несовместим с облачным Python-ядром, значит Dart-реализация agent-loop нужна в любом случае, и «единое ядро» иллюзорно. Рекомендация для AgentKit: protocol-first гибрид — (1) MCP как контракт tools (это же ложится на FlexCRM «фактически MCP-сервер» и облачный MCP для AiFina/AiResto); (2) спецификация AgentKit-протокола: tool registry, elicitation/AskUserQuestion (варианты ответа, подтверждение понимания), preview→confirm gate для write-tools, сценарии-цепочки, audit events — как язык-независимая схема; (3) две тонкие референс-реализации agent-loop: PHP (пакет CoreX для FlexCRM/FlexStore) и Dart (модуль AxiomaErpSdk для AiFina/AiResto) — agent-loop с современными LLM API это сотни строк, а не тысячи, дублирование дешевле кросс-языкового моста; (4) LiveKit Agents — опциональный voice-edge адаптер: голос → текст → тот же AgentKit-протокол, переиспользуя код Loqum. Так голосовой канал добавляется к любому продукту без того, чтобы Python стал обязательной зависимостью текстовых сценариев.

## Findings/Facts
### Архитектура и версия LiveKit Agents
Актуальная версия livekit-agents 1.6.4 (24 июня 2026). Модель: Python-процесс = agent server (Worker), регистрируется в LiveKit server по исходящему WebSocket, на dispatch запускает job-подпроцесс, который входит в room как участник. AgentSession — оркестратор пайплайна VAD→STT→LLM→TTS с turn detection. Realtime-модели (OpenAI Realtime, Gemini Live) — first-class. В 1.5–1.6: async tools с ctx.update(), filler phrases, Turn Detector v1.0, adaptive interruption, зрелая SIP-телефония, OpenTelemetry, встроенный test-фреймворк с LLM-judge.
[src: https://github.com/livekit/agents/releases; https://docs.livekit.io/agents/]

### Function tools, MCP, text-only режим
Tools — декоратор @function_tool, LLM видит локальные функции и tools внешних MCP-серверов единым набором; MCP-интеграция нативная, «одной строкой конфига». Text-only — первоклассный режим: официальный пример text_only.py, AgentSession только с llm= (без STT/TTS/VAD), room_options audio_input/output=False; вход по text stream топику lk.chat, выход по lk.transcription; testing-гайд по умолчанию использует text-only session.run(user_input=...). Ограничение: клиент общается через LiveKit text streams, а не plain HTTP — комнатная семантика навязывается даже чистому чату.
[src: https://raw.githubusercontent.com/livekit/agents/main/examples/other/text_only.py; https://docs.livekit.io/agents/start/testing/]

### Интеграция Laravel и Flutter
PHP server SDK — community-пакет agence104/livekit-server-sdk-php (не first-party): AccessToken/VideoGrant/toJwt + Server API. Паттерн: Laravel аутентифицирует пользователя, минтит scoped JWT, вызывает RoomService и Agent Dispatch API (рекомендован явный CreateAgentDispatchRequest, не implicit agents в CreateRoom). Flutter livekit_client — официальный, зрелый (Android/iOS/Web/macOS/Windows/Linux), с документированными sendText/streamText/registerTextStreamHandler и performRpc (request/response между агентом и клиентом). История чата НЕ персистится LiveKit — streams realtime-only, late joiners не получают открытые стримы, хранение — на бэкенде.
[src: https://github.com/agence104/livekit-server-sdk-php; https://docs.livekit.io/reference/client-sdk-flutter/index.html; https://docs.livekit.io/reference/other/roomservice-api/]

### Self-hosted эксплуатация
Два контура: LiveKit server (SFU, публичный TCP 443 + UDP-диапазон медиа, в K8s требует host networking → один pod на ноду) и agent workers (только исходящие соединения, приватная сеть). Redis: не обязателен для single-node, обязателен для multi-node кластера, ingress и autoscaling. Ресурсы worker: рекомендация 4 vCPU/8GB, 10–25 конкурентных сессий (load-test: 30 голосовых сессий ≈ 3.8 ядра / 2.8GB, т.е. ~0.1–0.15 ядра и 100–150MB на голосовую сессию; текстовая — заметно легче). Workers — stateful long-lived: terminationGracePeriod 600–900с, latency-aware autoscaling (scale-up при CPU>50%, load_threshold 0.7). Жалобы из прода 2025–2026: задержки dispatch 7–8с при мисконфиге Redis/routing, «инфраструктурный разрастание» (Redis+TURN+SFU+workers), E2E-латентность >1с без глубокого тюнинга стриминга и endpointing.
[src: https://docs.livekit.io/deploy/custom/deployments/; https://docs.livekit.io/transport/self-hosting/distributed/; https://docs.livekit.io/transport/self-hosting/kubernetes/]

### Альтернативы: Pipecat и другие
Pipecat (Daily): v0.0.99 (январь 2026), 40+ релизов за 2025, BSD-2, ~90 интеграций, frame-based pipeline, редизайн user turn strategies; Pipecat Cloud ≈$0.01/мин + Daily WebRTC $0.004/участник-мин. Сильнее в Python-ergonomics и кастомных пайплайнах, слабее в production-WebRTC/мультиязычных SDK. Vocode — телефонно-центричный, ниже velocity. OpenAI Agents SDK — RealtimeAgent/RealtimeSession, паритет Python/TypeScript, но привязка к OpenAI-моделям (проблема для рос. LLM-требований экосистемы). Ultravox — speech-to-speech модель, есть livekit-plugins-ultravox (только Python, не Node). Node.js LiveKit Agents SDK существует, но plugin-coverage отстаёт от Python.
[src: https://rywalker.com/research/pipecat; https://www.usagepricing.com/blueprint/daily; https://docs.livekit.io/agents/models/realtime/plugins/ultravox.md]

### Паттерн/антипаттерн: media-фреймворк как текстовое ядро
Консенсус 2026: LiveKit/Pipecat — «внешняя оболочка» realtime voice/video, делегирующая рассуждение внутреннему агенту; публичной практики использования их как ПЕРВИЧНОГО оркестратора back-office текстовых бизнес-агентов (CRM, учёт) нет — там доминируют OpenAI Agents SDK, LangGraph, кастомные оркестраторы. Комнатные абстракции (rooms, participants, tracks, VAD) и cost-модель per-minute оптимизированы под медиа. Для текстовых форм FlexCRM/AiFina это означает: WebRTC-room + dispatch + worker ради того, что решается одним HTTP/SSE-запросом к LLM с tool-loop.
[src: https://promtable.com/compare/pipecat-vs-livekit-agents; https://ai-engineering.academy/learn/14-agent-engineering/22-voice-agents-pipecat-livekit/]

## Sources
- https://github.com/livekit/agents/releases
- https://docs.livekit.io/agents/
- https://raw.githubusercontent.com/livekit/agents/main/examples/other/text_only.py
- https://docs.livekit.io/agents/start/testing/
- https://github.com/agence104/livekit-server-sdk-php
- https://docs.livekit.io/reference/client-sdk-flutter/index.html
- https://docs.livekit.io/reference/other/roomservice-api/
- https://docs.livekit.io/frontends/build/authentication/endpoint/
- https://docs.livekit.io/deploy/custom/deployments/
- https://docs.livekit.io/transport/self-hosting/distributed/
- https://docs.livekit.io/transport/self-hosting/kubernetes/
- https://community.livekit.io/t/slow-room-creation/1358
- https://livekit.com/blog/deploy-and-scale-agents-on-livekit-cloud
- https://promtable.com/compare/pipecat-vs-livekit-agents
- https://ai-engineering.academy/learn/14-agent-engineering/22-voice-agents-pipecat-livekit/
- https://rywalker.com/research/pipecat
- https://www.usagepricing.com/blueprint/daily
- https://docs.livekit.io/agents/models/realtime/plugins/ultravox.md
- https://github.com/fixie-ai/ultravox
- https://callsphere.ai/blog/vw9h-build-voice-agent-vocode-python-open-source-2026
