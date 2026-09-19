---
id: D186
date: 2026-09-19
status: accepted
item: P9
items: [P9, P9.3, P9.4, P9.5, P9.6, P9.7]
supersedes: [D184]
superseded_by: null
---
## Решение

**Agentools и CoreX/Flex — независимые пакеты на время исполнения фазы P9.** План AGENTOOLS **не** ждёт `corex/ai`, AiGateway, FLEX-2 P6.15, `flexcrm/agentic-bridge` и не пишет код в чужих репозиториях. P9.3–P9.7 **ребазлайнены** на автономный референсный вертикальный срез **целиком в** `~/projects/packages/agentools` по контракту `INT-002`. **INT-001** (Flex/CoreX CRM, C2-мост) переведён в статус **deferred** — исторический артефакт P9.2, не исполняемый контракт P9.3+. **D184** (ветка C2, мост в flexcrm) **снят** для исполнения P9; выбор владельца 2026-09-19 отменяет зависимость от чужого контура. Связь с CoreX/FlexCRM — **отдельное будущее решение**, вне текущего scope плана.

## Почему

Директива владельца 2026-09-19: «текущий проект Agent Tools с Core X напрямую никак не должен быть связан»; интеграционные связи проектируются позже. Блокер P9.3 (AiGateway) — следствие привязки к чужому контуру, а не дефект SDK. `RAG:— (owner decision 2026-09-19; repo-grounded: findings/P9.3-blocker-aigateway-2026-09-19.md; D184; INT-001)`

## Consequences

- P9.3: статус сброшен с 🔴 Blocked → ⬜ Not started; item-spec переписан (без AiGateway/Flex).
- P9.4–P9.7: item-specs переписаны под дом кода `agentools` (SQLite + reference adapters + CLI consumer).
- `root/contracts/INT-002_standalone-reference-slice.md` — активный контракт P9.3–P9.7; строка `Exported` в `plan.md` §7.
- INT-001: `deferred`; предложения дельт Flex (P2–P6-001) не блокируют P9.
- Попытки P9.3 attempt 1–3 (blocked) остаются в journal как история; исполнение продолжается по новым ТЗ.
- Интеграция с Flex/CoreX — новый item/план/решение владельца, не подразумевается P9–P10 без отдельного гейта.
