---
id: D39
date: 2026-07-27
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Транспорт Dart SDK — `mcp_dart`** (`DR-02` §5; вердикт P2.4 переведён из выжимки в решение плана). `McpProtocol.require2026` наблюдаемо не шлёт `initialize`; per-request `_meta`, `resultType`-энфорсмент и `input_required` подтверждены эмпирически; тонкий клиент — в 2.3× больше кода на минимальном сценарии. Принятый риск: prerelease `2.3.0-dev.3` (MIT). **Q-9 закрыт этой записью.** Триггер T-1: после пина финала MCP (фаза P1) фаза P8 обязана подтвердить stable-релиз с той же поддержкой либо явно принять риск смены prerelease-API

## Почему

`RAG:— (repo-grounded: findings/P2-dart-transport.md — критерии заполнены наблюдаемым выводом прогона на item-коммите `4344265`; логи мока — 0 legacy-запросов)`; `RAG:✅ 2026-07-27 (pub.dev/packages/mcp_dart): stable 2.2.2 MIT, prerelease-линия 2.3.0-dev.x`
