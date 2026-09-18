---
id: D5
date: 2026-07-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Canonical JSON = RFC 8785 (JCS); idempotency key = `at1:` + sha256hex(JCS({tenant_id, session_id, step_id, tool, args})); голден-файлы аудита сериализуются JCS

## Почему

детерминизм между PHP/Dart; версия-префикс `at1:` позволяет сменить формулу
