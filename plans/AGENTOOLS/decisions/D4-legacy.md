---
id: D4
date: 2026-07-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Two-phase confirmation = ОДИН tool: вызов write-tool БЕЗ `confirmation_token` → prepare (preview в `structuredContent`; token + `expires_at` + `preview_digest` в `_meta["agentools/v1"]`); вызов С token → commit. Альтернативы (пара `X`/`X__commit`, generic commit-endpoint) отклонены. _(Запись реконсилирована 2026-07-11 под канон спеки — кросс-аудит M12)_

## Почему

совместимость с любым MCP-клиентом; не раздувает tools/list; токен несёт всю привязку _(ключ `_meta` пересмотрен `DR-01` §A2: `dev.agentools/action`)_
