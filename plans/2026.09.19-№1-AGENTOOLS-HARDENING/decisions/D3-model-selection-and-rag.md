---
id: D3
date: 2026-09-19
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7, P8, P9, P10]
supersedes: []
superseded_by: null
---
# D3 — Sol для синтеза, Grok 4.6 для adversarial distributed-system phases

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— owner ограничил выбор Sol/Grok 4.6; risk profile фаз в `plan.md` §3

## Решение

На `high` detail P1/P2/P6/P7/P9/P10 выполняет `gpt-5.6-sol`; P3/P4/P5/P8 — Grok 4.6.
Все проходы используют repository recon и широкий внешний retrieval. Perplexity предпочтителен,
когда connector доступен, но каждый load-bearing external claim подтверждается primary source.

## Почему

Sol назначен на сквозной synthesis public contracts, parity, packaging и release evidence.
Grok назначен независимой adversarial линзой на replay, leases/fencing, event concurrency и
false-green test gates. Альтернатива «одна модель на все фазы» теряет полезное разнообразие проверки.

## Consequences

Route отражён в `plan.md`, `roadmap.md` и handoff. Недоступность конкретного provider требует
нового route decision, а недоступность Perplexity — явной границы retrieval, не фиктивной ссылки.
