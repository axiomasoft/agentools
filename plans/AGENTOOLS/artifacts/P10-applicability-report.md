# P10 — отчёт применимости протокола в offline-контуре ERP Axioma

**Вердикт:** `ГЕЙТ P10: PASS`

**Якорь дерева (plan-protocol §8):**

| Дерево | Команда | Хеш |
|:--|:--|:--|
| agentools (план + conformance) | `git -C ~/projects/packages/agentools rev-parse --short HEAD` | `fe0612e` |
| erp-sdk (дом переноса B) | `git -C ~/projects/packages/erp-sdk rev-parse --short HEAD` | `43500e8` |

Дом переноса — **ветка (B)** тонкий мост `~/projects/packages/erp-sdk/packages/agentools_bridge/` (D188). Это **интеграционная демонстрация** на живом scaffold монорепо, **не** заявление о терминальности чужих вех `ERP-IMPL` P1–P7.

---

## Шесть условий гейта выхода (DR-01 §K)

| # | Условие | Статус |
|:--|:--|:--|
| 1 | Матрица «возможности протокола × `L0`/`L1`/`L2`» со **ссылкой на правило ревизии в каждой строке** | PASS — см. §Матрица |
| 2 | RAG-блок: URL + дата либо `[UNVERIFIED]` | PASS — см. §RAG; слой 1: `findings/P10-offline-rag-2026-09-19.md` |
| 3 | Вердикт «уровень X достижим при условиях Y», Y перечислим | PASS — см. §Вердикт достижимости (**7** условий) |
| 4 | Шесть отрицательных доказательств §11 — вывод либо явная неисполненность | PASS — см. §Отрицательные доказательства (1 строка **не исполнена** эмпирически, названа) |
| 5 | «На чём стоит вердикт» + «что НЕ проверялось» | PASS — см. ниже |
| 6 | Реконсиляция R1–R10 + состояние предложений дельт канона | PASS — см. §R1–R10 |

---

## Матрица: возможности протокола × уровни деградации (ADR-072)

Термины **только** `L0` / `L1` / `L2` (09 §7, ADR-072). Столбцы — уровни; строки — способности протокола.

| Возможность | Правило ревизии | L0 (connected) | L1 (on-device model) | L2 (AI off, control alive) |
|:--|:--|:--|:--|:--|
| Подтверждение, TTL, повторная авторизация | §B2–§B8, §I4 | **Работает** при human initiator + single gate (P10.5) | **Деградирует:** LLM-шаги сценария без локальной модели недоступны; **write** через tool остаётся (AiResto inbox п.2) | **Деградирует:** AI-шаги недоступны; доменный write+confirm вне LLM — зона продукта |
| Идempotency запроса | §D1–§D3 | **Работает** (`DriftIdempotencyStore`, P10.4) | То же локально | То же |
| Журнал append-only, свёртка | §C1–§C7 | **Работает** (`DriftEventLog`, P10.4) | То же offline | То же |
| Сценарии, лимиты | §F1–§F4 | **Работает** (VoiceOrder demo, P10.5) | **Недоступно** для LLM-driven steps (deferred UV-5 обе стороны) | **Недоступно** для AI-сценария; control-logic ERP жива |
| Уточнения MRTR | §B1, `05_clarification-mrtr.md` | **Работает** (vector `product-erp-001`, CLR-6) | **Недоступно** без LLM path | **Недоступно** |
| Длинные операции (sync + task paths) | §G1 | **Работает:** sync path declared (`LongOperationCoordinator`, P10.5 test) | Аналитически как L0 для non-LLM ops | Non-AI ops без task extension |
| Аудит решений | §H1–§H5, §I4 | **Работает** (`GateDecisionAuditSink`, P10.4) | То же | То же |
| Безопасность, initiator, tier freeze | §I1–§I4, INV-ASSIST-* | **Работает** (dual gate ToolPort, P10.3) | Tier/scope freeze сохраняется | AI tools off; PEP контура |

Проверка «каждая строка с правилом»:

```bash
grep -E '§B|§C|§D|§F|§G|§H|§I|05_clarification' plans/AGENTOOLS/artifacts/P10-applicability-report.md | wc -l
# → 8 строк матрицы (см. таблицу выше)
```

---

## RAG-блок (function-calling / on-device / MCP)

Краткая выжимка; полный слой 1 — `findings/P10-offline-rag-2026-09-19.md`.

| Тезис | URL / носитель | Дата |
|:--|:--|:--|
| BFCL V4 = agentic tool-use, не один JSON-call | https://gorilla.cs.berkeley.edu/blogs/15_bfcl_v4_web_search.html | 2026-09-19 |
| Leaderboard snapshot | https://gorilla.cs.berkeley.edu/leaderboard.html | 2026-04-12 (meta страницы) |
| Small models ~50–60% overall в agentic mix — не operational SLA | synthesis → BFCL | 2026-09-19 |
| `llama_cpp_dart` on-device stack существует | https://pub.dev/packages/llama_cpp_dart | 2026-09-19 |
| RU on-device production tool-use | — | **`[UNVERIFIED]`** (как в recon §7) |
| `mcp_dart` 2.2.x без `require2026` | `erp-sdk/.../evidence/mcp-era-stable-pin.log` | 2026-09-19 |

Направление recon 2026-07-27 (**<4B ненадёжны в решении «звать tool»; 7B — порог**) **не опровергнуто** ре-верификацией 2026-09-19.

---

## Стресс-анализ разрыва связи

| Тема | Правило | Сходимость с каноном ERP | Эмпирика |
|:--|:--|:--|:--|
| TTL + повторная авторизация | §B4, §B8 | Канон: re-confirmation, signoff не переиспользуется | **P10.5:** `dropPendingForConnectivityGap()` → старый approve отвергается; второй prepare → `confirmationRequests=2` |
| Истёкший TTL | §B2 | tool execution error, не protocol error | **P10.5:** `erp_confirmation_gates_test` expired |
| Три слоя dedupe | §D1 + INT-002 §5 + ADR-007 | Ключ запроса ≠ ключ `SyncItem` ≠ correlation-only | **P10.4:** idempotency store отдельно; `SyncDeliverySubstitute` — слой доставки, не подмена §D1 |
| Sync queue реальный | ADR-007 | — | **Substitute** (отклонение P10.4); не полный axioma_sync |

---

## Шесть обязательных отрицательных доказательств (`research/09-…` §11)

| # | Механизм | Прогон | Статус |
|:--|:--|:--|:--|
| 1 | **Эра протокола** (P10.3) | `dart test test/mcp_era_negotiation_test.dart` — `require2026` absent in 2.2.x | PASS (**красный факт назван**, не обход) |
| 2 | **Money-exclusion** (P10.3) | `erp_tool_port_test.dart` — `post_payment` absent from catalog + invoke fails | PASS |
| 3 | **Tier 3 + §I4** (P10.3) | Отдельный прогон «Tier 3 без pre-commit, событие §I4 записано» | **Не исполнен** — в demo нет Tier-3 tool; mapping в `tier_policy.dart` only. **Аналитический** вердикт R2; адресат: владелец + follow-up bridge |
| 4 | **Журнал на хранилище** (P10.4) | `persistence_invariants_test.dart` (parallel append conflict, immutability) | PASS |
| 5 | **Разрыв связи** (P10.5) | sync path + gap tests in `test/scenario/` | PASS |
| 6 | **Единственность гейта** (P10.5) | `confirmationRequests=1` happy path; L2 vector probe | PASS |

---

## Реконсиляция R1–R10 (фактические вердикты INT-002 + эмпирика)

| # | Вердикт INT-002 | Факт после P10.3–P10.5 |
|:--|:--|:--|
| R1 | Сходится | Tier → Policy via `tier_policy` + INT-002 §8 table |
| R2 | Сходится (Tier 3 трактовка) | §I4 enforcement Tier-3 — **эмпирика неполная** (см. отриц. #3) |
| R3 | Сходится, enforcement контура | `post_payment` / `aiForbidden` |
| R4 | Сходится | Protocol source; projections not second gate |
| R5 | Сходится | dualControl not exercised in product vector — **не противоречие** |
| R6 | Дельта **E6-001** | **Без ответа** адресата канона |
| R7 | Дельта **E7-001** | **Без ответа** |
| R8 | Q-22 пакет владельцу | **Без ответа** (Scenario Runner vs orchestration/) |
| R9 | Сходится, контур строже | INV-ASSIST-5 — PEP side |
| R10 | Сходится | L0/L1/L2 terminology only |

### Предложения дельт канона Axioma (INT-002 §11)

| ID | Состояние на 2026-09-19 |
|:--|:--|
| E2-001 | **Без ответа** |
| E6-001 | **Без ответа** |
| E7-001 | **Без ответа** |
| E23-001 | **Без ответа** (исполнение: Q-23 ветка 3 in-process) |
| E8-001 | **Без ответа** |

---

## Вердикт достижимости

**Формулировка:** уровень **`L0` достижим при условиях Y** (перечислимо, **7** условий):

1. Human initiator на каждом tool-call (INV-ASSIST-1) — проверено P10.3.
2. Единственный protocol gate на product path — P10.5 (`confirmationRequests=1`).
3. In-process bridge без wire MCP 2026-07-28 — Q-23(3), era probe red fact documented.
4. Persistence stores P10.4 на SQLite/Drift demo — не заявляет production ERP rollout.
5. Connected LLM provider для LLM-шагов сценария — вне SDK; offline LLM не требуется для L0.
6. Состав сценария только из канона AiResto (`get_menu`, `create_order`) — P10.5.
7. Чужие cross-plan вехи `ERP-IMPL` **не** интерпретируются как «готово» — только снимок INT-002 §1.

**L1:** **не достижим для MVP** обеих сторон (deferred ADR-072 / AiResto UV-5). Контракт SDK **не блокирует** будущий router (inbox п.2).

**L2:** **достижим как MVP-деградация** при условии: продукт отключает AI-шаги, control-logic и доменные write-пути (в т.ч. sync queue) остаются — согласовано с `90_inbox/airesto-offline-gap.md` п.3.

---

## На чём стоит вердикт

| Опора | Содержание |
|:--|:--|
| **Аналитика** | Frozen rev `2026-08`, `DR-01`, INT-002, ADR-072 L0/L1/L2, inbox AIRESTO |
| **Эмпирика переноса** | P10.3–P10.5 terminal on `erp-sdk` @ `43500e8` + agentools vectors @ `fe0612e` |
| **Эмпирика P9 (прецедент)** | `ГЕЙТ P9: PASS` — reference slice; ERP перенос **шире** (offline gap, §G1) |
| **Не смешивать** | Demo bridge ≠ полная интеграция в production ERP; SyncDeliverySubstitute ≠ ADR-007 queue |

---

## что НЕ проверялось

- **Remote CI / merge protection (Q-2)** — только локальные команды.
- **Wire MCP 2026-07-28** на пине контура — сознательно не участвует (Q-23 branch 3).
- **Реальный `axioma_sync` queue** — substitute P10.4.
- **Tier 3 standing pre-auth + §I4** — отдельный negative не снят.
- **L1 on-device** — не построен (deferred).
- **Сценарий «кто ходил в отпуск» / кадровый домен (Q-1)** — **невыразим**: в каталогах AiResto/AiFina нет HR tools; решение владельца продукта, вне AGENTOOLS.
- **Заявление уровня Dart SDK выше P8 gate (Q-17)** — не поднималось.
- **Выбор Q-22** (Scenario Runner vs `orchestration/`) — не сделан владельцем.

---

## Ответ потребителю AIRESTO (`90_inbox/airesto-offline-gap.md`, реанкор 2026-08)

| # | Требование inbox | Ответ (2026-08 ревизия) |
|:--|:--|:--|
| 1 | Scenario Runner на L0 без изменений | **Да** — §F + demo orchestrator; MRTR single binding в vector |
| 2 | L1 deferred, API допускает router позже | **Да** — L1 строки матрицы «deferred»; не закрываем router |
| 3 | MVP-деградация = L2 | **Принято** — вердикт §L2 |
| 4 | Митигации weak function calling (retry/clarify) | **Да** — CLR-* / scripted vectors; без доп. SDK требований |
| 5 | STT вне SDK, SpeechKit / Vosk / Whisper — выбор AiResto | **Зафиксировано** — injectable capability, не часть gate P10 |

---

## Предложения к СЛЕДУЮЩЕЙ ревизии (не в frozen 2026-08)

| ID | Предложение | Цена отсутствия |
|:--|:--|:--|
| REV-P10-01 | Явная normative связь Tier 3 ↔ обязательное §I4 в тексте §B6 | Риск «обхода» через имя tier |
| REV-P10-02 | Таблица dedupe-слоёв в normative spec (симметрия E7-001) | Двойное исполнение после offline replay |
| REV-P10-03 | Document sync-path §G1 as mandatory for offline-first profiles | Ложно-зелёный «только tasks» |

---

## Счёт эмпирических прогонов (перепроверка на дереве гейта)

| Прогон | Результат | Команда |
|:--|:--|:--|
| Bridge tests | 23/23 | `cd erp-sdk/packages/agentools_bridge && dart test` |
| Product ERP L2 | 1/0 | `php conformance/bin/run --adapter 'dart run ../erp-sdk/packages/agentools_bridge/conformance/product_erp_adapter.dart' --vectors fixtures/product-erp --spec-rev 2026-08 --level L2` |
| Bridge L1 (prior) | 126/126 | journal P10.4 |

---

## Исходы открытых вопросов (обновление P10.6 → D190)

| ID | Исход на 2026-09-19 |
|:--|:--|
| Q-1 | Pending owner product — HR/«отпуск» невыразим; не блокер гейта |
| Q-11 | Pending external — `Exported` INT-002; потребители сами ставят `Consumed` |
| Q-17 | Resolved для P10 (D187) — достаточность механики переноса; owner-only выше измеренного |
| Q-20 | Resolved D188 (B) — thin bridge в `erp-sdk` |
| Q-21 | Resolved D189 (a) — journal branch |
| Q-22 | Owner gate — альтернативы INT-002 §7, выбор не сделан |
| Q-23 | P10.3 исполнил ветку (3); поднятие пина — owner, E23-001 без ответа |

## Готовность плана

Фаза P10 (волна переноса ERP) закрыта отчётом; bookkeeping-план может пройти `plan-design AGENTOOLS finish` (reconciliation дизайна, не исполнение). Дальше по `plan.md` §3 — **P11** (release/hygiene). `task:plan-audit AGENTOOLS P10` в ТЗ P10.6 только **Scope Excluded** (опциональный phase-audit протокола), не обязательный следующий шаг; сквозной аудит плана — отдельно, в конце, не вместо P11.

**write-site:** pre_mutation: allow · pre_final: allow
