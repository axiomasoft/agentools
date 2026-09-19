---
id: D185
date: 2026-09-19
status: accepted
item: P9.2
items: [P9.2]
supersedes: []
superseded_by: null
---
## Решение

**INT-001 опубликован: карта восьми портов C2-моста, развод трёх гейтов (источник §I4 / проекция Flex / `laravel/ai` отключён), реанкор B-32 П1–П7, журнал — ветка (a) `ai_action_events` + проекции `ai_*`.** `preview_digest` — поле витрины, не участвует в commit. Имя в токене — только `module.verb_noun`. Для `irreversible` — `dual_control` (§B5), не `double_confirm`. Пять предложений дельт канона (P2–P6-001) с адресатами FLEX-2. **Q-19 закрыт** веткой (a). **Q-11:** строка `Exported` на INT-001 заведена (D108).

## Почему

`RAG:— (repo-grounded: findings/P9-flex-state-2026-09-19.md; research/08-p9-crm-slice-2026-08.md §5–§7; D100/D101/D103/D184; root/contracts/INT-001_corex-ai-integration.md)`

## Consequences

- P9.3–P9.6 исполняют контракт; гейт владельца — утверждение INT-001 и предложений дельт перед кодом.
- Q-19: `Resolved → D185` (ветка a).
- Q-11: носитель уведомления exercised (`Exported` §7); `Consumed` в FLEX-2 не заводится (D63).
