# P4 — итоговый гейт A3 (повторный)

**Дата:** 2026-08-04

**Якорь дерева:** item-коммит P4.13 `2ffff1c`.

**Команды гейта:**

```text
python3 tools/spec_lint.py --gate-b-remediation-check fixtures/2026-08
python3 tools/spec_lint.py --fixtures-check fixtures/2026-08
python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check
python3 tools/rules_index.py --check spec/revisions/2026-08
python3 tools/close_schema.py spec/revisions/2026-08 --check
git diff --check
```

## Условия гейта

| Условие | Факт на `2ffff1c` | Вердикт |
|:--|:--|:--|
| Evidence-map детерминирован | `--gate-b-remediation-write` пишет только `gate-b-remediation.json`; повторный `--gate-b-remediation-check` = 0 | PASS |
| Exact round-1 inventory | 27 fixture carriers и 116 FAIL rules (на `2ffff1c`; `--gate-b-remediation-check`) | PASS |
| Partition D156 | 65 behavior rules, retained `longop-002/102`, static `SEC-17`/`AOB-26` (на `2ffff1c`; manifest) | PASS |
| Behavior evidence различает пару | Одна relation `value`/`presence`/`length` на rule, ровно два отсортированных members и один input counterfactual (на `2ffff1c`; `--gate-b-remediation-check`) | PASS |
| Boundary pairs адресны | `ALC-35` и `SEC-11` = `presence`; `AOB-31`, `CNF-35`, `CNF-36` = `length` с общим list-container (на `2ffff1c`; manifest) | PASS |
| Mutation reject-path реален | Pure validator отверг F1–F6, CFH-55, ALC-35 presence, AOB-31 length и SEC-11 explicit-pointer mutants (на `2ffff1c`; `--gate-b-remediation-check`) | PASS |
| Формат корпуса | `--fixtures-check` = 0 (на `2ffff1c`) | PASS |
| Coverage и обратная ссылка | `--coverage --check` = 0; MUST/MUST NOT 383/383, пять SHOULD warnings опубликованы (на `2ffff1c`) | PASS с предупреждениями |
| Индекс и schema closure | `rules_index --check` = 0, 400 rules; `close_schema --check` = 0, 15 schemas (на `2ffff1c`) | PASS |
| Чистота diff | `git diff --check` = 0 (на `2ffff1c`) | PASS |

## Счёт покрытия

`fixtures/2026-08/coverage.md` свеж на `2ffff1c`: 400 rules всего, 394 testable,
384 testable with coverage и 383/383 covered MUST/MUST NOT. Корпус содержит 214 behavior,
68 artifact и 13 harness носителей; golden registrations — 10. Все числа получены командой
`python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check` на `2ffff1c`.

## Граница вердикта

**ГЕЙТ A3: PASS** означает только формальную и remediation-проверку корпуса: exact inventory,
traceability, pair evidence и reject-path mutations воспроизводимы. Это **не** semantic GREEN
Gate B: содержательный адверсариальный вердикт принадлежит исключительно новому cold-start
`P5.1` round 2.
