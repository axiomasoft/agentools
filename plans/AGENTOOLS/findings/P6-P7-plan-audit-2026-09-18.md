# P6/P7 — плановый аудит перед исполнением (Cursor)

> Снято 2026-09-18 на дереве артефактов `5cc5288` (`git -C ~/projects/packages/agentools rev-parse --short HEAD`).
> Это **находка плана**, не terminal closure. Исполнение — в новой сессии по runbook.

---

## 1. Порядок доверия (Layout v2)

| Приоритет | Источник | Когда использовать |
|:--|:--|:--|
| 1 | `git log` + живые прогоны на HEAD | Факт кода и зелёных проверок |
| 2 | `artifacts/migration-source-v1/journal.jsonl` | Хронология до migration (P6.5 `52637fc`, P6.9 escalation) |
| 3 | `artifacts/migration-source-v1/phases/P6.md` | Реальные Completion Notes P6.1–P6.8 |
| 4 | `roadmap.md` + `decisions/D163-legacy.md` | P7.1–P7.10 = reconciliation pending |
| 5 | `status.md`, `*.state.md` | **Только после** регенерации из journal; до audit — низкое доверие |

**Дефект migration:** `status.md` показывает P6.5 = `🟢 Done`, но Validation P6.5 не выполнен (нет negative adapters). Formal reopen — только через `task:plan-design AGENTOOLS P6.5` в сессии исполнения (преждевременный journal-reopen ломает bundle lint).
**Дефект migration:** `status.md` показывает P7 = `Not started`, хотя `roadmap.md` + D163 + anchors `b11cf41`…`e232fc5` в git означают reconciliation pending. Journal sync P7 → `in-progress` отложен до сессии исполнения (преждевременная активация ломает read-budget lint).

---

## 2. P6 — живые прогоны на `5cc5288`

| # | Условие | Команда (сокращ.) | Результат |
|:--|:--|:--|:--|
| 1 | Полный корпус | `conformance/bin/run` + reference adapter | **PASS** 213/213, exit 0 |
| 2 | Мутант | `--vectors conformance/tests/fixtures/mutants` | **PASS** exit 1 |
| 3 | Harness selftest | `harness-selftest --cases fixtures/2026-08/harness` | **PASS** 13/13 |
| 4 | Levels report | `levels --write --check` | **PASS** |
| 5 | CI | `python3 tools/ci_gate.py --all` | **PASS** |
| 6 | Frozen diff | `git diff fixtures spec` | **0** |
| 7 | Corpus digest | `spec_lint --behavior-executability-check` | `ff797647…` = P6.2 |
| 8 | Golden negatives | `byte-drift.php`, `struct-drift.php` | **FAIL** — файлов нет |

Уровни: L1=126, L2=56, L3=31. Golden в отчёте: 9/9 pass.

### 2.1 Шесть отрицательных прогонов (`research/05-p6-conformance-harness-2026-08.md` §7)

| Механизм | Статус |
|:--|:--|
| Матчер (P6.1) | PASS |
| Статусы (P6.4) | PASS |
| Baseline (P6.4) | PASS |
| **Golden (P6.5)** | **BLOCKED** |
| Lockstep (P6.8) | PASS |
| CI слои (P6.7) | PASS (на зелёном дереве) |

### 2.2 P6.5 — ложное закрытие

| Факт | Доказательство |
|:--|:--|
| Item закрыт `52637fc` | `migration-source-v1/journal.jsonl` `2026-08-05T07:01:21Z` |
| P6.9 эскалирован тем же днём | journal: `P6.5 golden-negative fixture adapters absent` |
| Validation требует оба адаптера | `phases/P6/P6.5.md` Validation |
| Механика сравнения есть | `GoldenComparator.php`, `Runner.php` L102–121 |
| PHPUnit на comparator | **нет** (отрицательные сигналы — через fixture-adapters) |

**Формальное действие:** `task:plan-design AGENTOOLS P6.5` (reopen), не неформальный side-task.

### 2.3 P6.9 — что осталось

1. Доставить adapters (шаг A runbook)
2. Перепрогнать Validation `phases/P6/P6.9.md`
3. Обновить `artifacts/P6-gate.md` → `ГЕЙТ P6: PASS`
4. Bookkeeping + handoff

---

## 3. P7 — reconciliation map (код есть, terminal closure нет)

**Глобально на HEAD:** PHPUnit **65/65**, PHPStan **0 errors** — evidence существования (D163), не write-site closure.

| Item | Anchor | Аудит | Ключевые gaps vs `phases/P7/P7.N.md` Validation |
|:--|:--|:--|:--|
| P7.1 | `b11cf41` | PARTIAL | Нет `extra.agentools.spec_rev` в `php/composer.json` (`lockstep` читает `extra.agentools.spec_rev`) |
| P7.2 | `c690a67` | PARTIAL | `Identity/` есть; не все тесты с rule-ID docblocks |
| P7.3 | `1f5eaf8` | **GREEN** | JCS, schema validation — ок |
| P7.4 | `35afb53` | **GREEN** | `ToolDescriptor`, digest — ок |
| P7.5 | `62e31c3` | PARTIAL | Код в `Action/`, не `Journal/`; нет портов `EventLog`/`SessionStore`/`Clock`; negative-тесты из Validation отсутствуют |
| P7.6 | `db8f6d1` | **GREEN** | `Effects/`, epoch — ок |
| P7.7 | `23be310` | PARTIAL | Нет TTL-on-prepare test; нет hash-mismatch-at-commit; edit→journal supersede не доказан |
| P7.8 | `f8419f2` | PARTIAL | Порт `DeduplicationStore` в `Idempotency/`, не `Contracts/IdempotencyStore`; нет explicit enum-count test |
| P7.9 | `6e1ec1d` | PARTIAL | `Error/` не `Errors/`; **нет GREASE-тестов**; нет lifecycle Active→Deprecated→Removed; `ToolErrorTest` без rule IDs |
| P7.10 | `e232fc5` | PARTIAL | AST/MRTR есть; **1/4 limit-red tests** (только `expression.max_depth`; нет length/collection/visited-nodes) |
| P7.11 | — | NOT STARTED | `php/src/Ops/` отсутствует |
| P7.12 | — | NOT STARTED | нет `php/conformance/adapter.php`; `conformance/sdks.json` = `[]`; нет `artifacts/P7-gate.md` |

### 3.1 Структурные расхождения с `research/06-p7-php-sdk-2026-08.md`

| План | Факт на дереве |
|:--|:--|
| `php/src/Journal/` | `php/src/Action/` |
| `php/src/Errors/` | `php/src/Error/` |
| `php/src/Ops/` | отсутствует |
| `Contracts/`: EventLog, SessionStore, Clock, IdempotencyStore | только TokenSigner, LlmPort, ToolPort, CommitAuthorizer |

**Правило reconciliation:** не массовый rename без D-record; закрывать gaps Validation, фиксируя Known Deviations где путь осознанно отличается.

### 3.2 Pre-P7 bootstrap

`ff09c44` — lifecycle/idempotency primitives до формального P7.1; не отменяет gaps P7.5/P7.8.

---

## 4. Цепочка исполнения (новая сессия)

```text
[A] task:plan-design AGENTOOLS P6.5  → byte-drift + struct-drift
[B] task:plan-run AGENTOOLS P6.9     → ГЕЙТ P6: PASS
[C] P7.1…P7.10 reconciliation        → per-item Validation (не blind close)
[D] task:plan-run AGENTOOLS P7.11
[E] task:plan-run AGENTOOLS P7.12    → ГЕЙТ P7
```

Runbook: `research/26-p6-gate-closure-cursor-runbook-2026-09.md`.

---

## 5. Связанные артефакты

| Артефакт | Роль |
|:--|:--|
| `artifacts/P6-gate.md` | Вердикт BLOCKED + audit supplement |
| `handoff.md` | Next → P6.5 reopen |
| `decisions/D163-legacy.md` | P7 reconciliation rule |
| `findings/P6-ext-tasks-observation.md` | Q-7 |
