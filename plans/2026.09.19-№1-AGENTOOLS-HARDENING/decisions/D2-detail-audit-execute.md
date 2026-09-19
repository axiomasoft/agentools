---
id: D2
date: 2026-09-19
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7, P8, P9, P10]
supersedes: []
superseded_by: null
---
# D2 — Сначала детализировать все фазы, затем аудитировать, затем исполнять

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— phase dependencies и cross-cutting guarantees этого плана

## Решение

Lifecycle имеет три последовательные стадии: отдельный `task:plan-design` для каждой P1–P10;
после последней — независимый `task:plan-audit <ID> design`; только после GREEN design audit —
`task:plan-exec` item'ов. Наличие готового item до завершения стадии 2 не разрешает его исполнение.

## Почему

Security, durability, parity, package и release contracts пересекаются. Раннее исполнение
заставило бы поздние фазы принимать уже реализованный API как факт. Альтернатива «detail/execute
по одной фазе» отклонена из-за дорогих cross-language migrations и риска локально зелёного дизайна.

## Consequences

Текущий master-plan содержит только skeletons. Каждая detail-сессия добавляет item specs и
Routing; handoff последовательно ведёт P1→P10→whole-design-audit→execution.
