---
id: D188
date: 2026-09-19
status: accepted
item: P10.1
items: [P10.1]
supersedes: []
superseded_by: null
---
## Решение

**Q-20 закрыт веткой (B):** тонкий мост `agentools_bridge` в `~/projects/packages/erp-sdk` (без pub-зависимости AI-пакетов на `agentools` в scaffold на 2026-09-19). Основание: монорепо живёт в `erp-sdk` (45×`.dart`, MCP/AS analyze OK); путь `axioma_erp_sdk` отсутствует; `ERP-IMPL` P1 = 🟡 4/12. Альтернативы (A)/(C1)/(C2) остаются в `findings/P10-erp-state-2026-09-19.md` §7. **Q-17:** D187.

## Почему

Owner gate при старте P10.2 по рекомендации P10.1 §7 (D109). `RAG:— (repo-grounded: findings/P10-erp-state-2026-09-19.md §7; INT-002_axioma-erp-integration.md §2)`
