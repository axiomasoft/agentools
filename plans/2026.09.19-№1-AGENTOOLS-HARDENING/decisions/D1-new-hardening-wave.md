---
id: D1
date: 2026-09-19
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7, P8, P9, P10]
supersedes: []
superseded_by: null
---
# D1 — Новый hardening-план вместо переоткрытия AGENTOOLS

**Actor:** owner + plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— owner request; `plans/AGENTOOLS/`; `audits/2026-09-19-audit.md`

## Решение

Все новые security, durability, API/package и release-hardening работы принадлежат отдельному
PLAN1 `2026.09.19-№1-AGENTOOLS-HARDENING`. Существующий `AGENTOOLS` остаётся неизменным
историческим evidence source; его фазы и P11 не переоткрываются.

## Почему

Старый план фиксирует другой lifecycle и уже произведённые receipts. Переоткрытие смешало бы
исторический статус с новой нормой и создало двух владельцев release acceptance. Альтернатива
«дописать всё в P11» отклонена как слишком узкая и не отражающая новые P0 security boundaries.

## Consequences

PLAN1 владеет всеми новыми decisions, migrations, evidence и release gate. Старые contracts
потребляются только с явным pin и могут быть изменены лишь новым versioned decision PLAN1.
