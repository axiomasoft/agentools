---
id: D5
date: 2026-09-19
status: proposed
item: P10
items: [P10]
supersedes: []
superseded_by: null
---
# D5 — Канал публикации и legal identity

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— current proprietary license/private package metadata

## Решение

Предлагается сначала принять private/local release candidate, а public Packagist/pub.dev/GitHub
release рассматривать только после отдельного owner-решения о license, vendor/package identity,
support channel и exact registries.

## Почему

Рекомендованный вариант сохраняет обратимость и не блокирует технический hardening.
Альтернатива — сразу public release — требует legal/product решений, которых нет в аудите.

## Consequences

Вопрос не блокирует P1–P9 и private RC в P10. Он должен быть accepted/rejected/superseded
до выдачи G-RELEASE на публичную публикацию.
