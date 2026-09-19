---
id: D4
date: 2026-09-19
status: accepted
item: P10
items: [P9, P10]
supersedes: []
superseded_by: null
---
# D4 — Release candidate обязателен, external publication — owner-gated

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— current README/LICENSE/manifests; RAG:✅ 2026-09-19 Dart publishing immutability and dry-run guidance

## Решение

PLAN1 обязан собрать и принять production-grade local/private PHP/Dart release candidate.
Public registry upload, git tag/release, credential use и смена license/legal identity запрещены
до явного `[OWNER-GATE:G-RELEASE]` с утверждёнными artifact digests и publication channel.

## Почему

Техническую готовность archive/consumer/provenance можно доказать обратимо. Внешняя публикация
необратима для package versions и конфликтует с текущей private/proprietary identity без решения
владельца. Альтернатива «публикация как обычный CI шаг» расширяет полномочия плана.

## Consequences

P9 не получает publish credentials. P10 формирует decision packet; отсутствие G-RELEASE не
мешает закрыть private RC, но блокирует только публичный publish/tag и публичный release receipt.
