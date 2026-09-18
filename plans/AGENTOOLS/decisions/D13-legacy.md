---
id: D13
date: 2026-07-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

Хранение состояния сессии — контракт `SessionStore`/`EventLog` (интерфейсы в SDK) + референс-реализации in-memory; продуктовые реализации вне репо

## Почему

D8/D10 мастер-плана: SDK тонкий, персистентность — дело потребителя
