---
id: D7
date: 2026-09-19
status: accepted
item: P2
items: [P2, P2.1, P2.2, P2.3, P2.4]
supersedes: []
superseded_by: null
---
# D7 — Additive safe confirmation facade with staged legacy retirement

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository recon in `research/P2-design.md`; RAG:✅ and Perplexity/web cross-check in `research/P2-design-rag.md`

## Решение

P2 вводит `ConfirmationService` как единственную поддерживаемую application boundary. На одном
вызове caller передаёт только intent/actual arguments либо token/actual arguments; actor, time,
descriptor, policy, proposal/approval evidence, authorization context и separation/quorum
получаются из доверенных constructor dependencies. `ConfirmationProtocol` сохраняется временно
как deprecated expert/legacy primitive для совместимости и focused tests, но reference consumers
не вправе вызывать его напрямую.

Полный binding tuple фиксируется логически в P2. P3 выбирает token/durable representation, P5 —
journal storage, P6 — новую shared spec/error revision, P7 — final exports/removal. Frozen
`spec/revisions/2026-08/**` в P2 не изменяется.

## Почему

Локальный patch `dualControl`/`proposerId` оставляет caller-controlled clock, principal,
descriptor и authorizer. Facade без ограничения legacy call graph оставляет обход. Немедленное
удаление low-level API смешивает security fix с package-breaking решением P7. Additive facade плюс
измеримая миграция закрывает runtime bypass и сохраняет отдельные representation/compatibility owners.

## Consequences

P2 должен доказать отсутствие security-critical per-call параметров, exact approval/policy binding,
deny-by-default context-rich authorization и PHP/Dart parity. P2 не заявляет durable replay safety,
production event persistence или final stable API. Если P7 решит сохранить expert API, он обязан
экспортировать его как явно unsafe/advanced surface, а не как равноправную альтернативу facade.
