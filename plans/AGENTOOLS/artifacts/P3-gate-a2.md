---
type: gate-artifact
plan: AGENTOOLS
item: P3.14
phase: P3
date: 2026-07-28
---

# ГЕЙТ A2 — выход фазы P3 (авторинг ревизии `2026-08`)

**Якорь-коммит (репозиторий `~/projects/packages/agentools`):** `bf121cf`
(`feat(spec): rules_index.py — генератор индекса правил + гейт покрытия решений (P3.14)`).
Все числа ниже посчитаны на этом дереве командами, приведёнными в каждой строке —
воспроизводимо, не на веру.

## Условия гейта (`DR-01` §K, `phases/P3.md` «Гейт выхода»)

| # | Условие | Команда | Результат | Статус |
|:--|:--|:--|:--|:--|
| 1 | Линтер ревизии зелёный **без** `--allow-missing` | `python3 tools/spec_lint.py spec/revisions/2026-08` | `spec_lint: OK` | 🟢 |
| 2 | `rules.json` сгенерирован детерминированно (повторный прогон — пустой diff) | `python3 tools/rules_index.py --write spec/revisions/2026-08 && python3 tools/rules_index.py --check spec/revisions/2026-08` | `rules_index: OK, расхождений нет (397 правил)`, exit 0 | 🟢 |
| 3 | Каждая запись индекса несёт ровно один уровень L1/L2/L3 | `python3 -c "import json;d=json.load(open('spec/revisions/2026-08/rules.json'));print(sum(1 for r in d['rules'] if r['level'] not in ('L1','L2','L3')))"` | `0` | 🟢 |
| 4 | Маркер уникален по всей ревизии | `python3 -c "import json;d=json.load(open('spec/revisions/2026-08/rules.json'));ids=[r['rule_id'] for r in d['rules']];print(len(ids)-len(set(ids)))"` | `0` | 🟢 |
| 5 | Покрытие ≥150 нормативных правил | `python3 -c "import json;d=json.load(open('spec/revisions/2026-08/rules.json'));print(len(d['rules']))"` | `397` | 🟢 |
| 6 | Легаси-лексика D20 не встречается вне огороженного блока | `python3 tools/spec_lint.py --legacy-check spec/revisions/2026-08` | `spec_lint: OK (--legacy-check, 2026-08)` | 🟢 |
| 7 | Закрытые схемы авторинга свежие (единственный автор `close_schema.py`) | `python3 tools/close_schema.py --check spec/revisions/2026-08` | `close_schema: OK (сверено схем: 15)` | 🟢 |
| 8 | **Сводный гейт покрытия**: объединение таблиц `## Покрытие решений DR-01` 12 документов покрывает матрицу `research/02-p3-authoring-conventions-2026-08.md` §7 целиком | `check_coverage_matrix` (`tools/spec_lint.py`, встроено в общий прогон п. 1) | 0 непокрытых решений (39 записей матрицы, все нашли строку в СВОЁМ документе) | 🟢 |
| 9 | Регрессия: профиль `2026-07-draft` не сломан переводом линтера на профили | `python3 tools/spec_lint.py spec/revisions/2026-07-draft` | `spec_lint: OK` | 🟢 |

**Все девять условий зелёные** — вердикт см. в конце документа.

## Числа ревизии (на якоре `bf121cf`)

| Метрика | Значение |
|:--|:--|
| Правил всего | 397 |
| Уровень L1 | 272 |
| Уровень L2 | 83 |
| Уровень L3 | 42 |
| `MUST` | 271 |
| `MUST NOT` | 115 |
| `SHOULD` | 5 |
| `MAY` | 6 |
| `Not testable` (с причиной) | 1 (`LOP-9`) |
| Документов ревизии | 12 (`00_overview.md` … `11_conformance-harness.md`) |

Правил по документам: `00_overview.md` 27 · `01_extension-identity.md` 17 ·
`02_tool-contract-profile.md` 32 · `03_action-lifecycle.md` 37 ·
`04_confirmation.md` 41 · `05_clarification-mrtr.md` 21 · `06_scenarios.md` 30 ·
`07_long-operations.md` 27 · `08_audit-observability.md` 44 ·
`09_idempotency-errors.md` 48 · `10_security.md` 19 ·
`11_conformance-harness.md` 54.

## Сводный гейт покрытия решений — метод

`tools/spec_lint.py` несёт константу `COVERAGE_MATRIX_2026_08` (копия матрицы
`research/02-p3-authoring-conventions-2026-08.md` §7 — решение видно диффом
файла, не эвристикой по прозе) и функцию `check_coverage_matrix`: для каждого
документа она вытаскивает labels строк его собственной таблицы `## Покрытие
решений DR-01` и проверяет, что КАЖДОЕ решение, приписанное документу матрицей,
присутствует СРЕДИ НИХ. Проверка per-документная (не «где-то в объединении по
всей ревизии»): приписка решения СВОЕМУ документу — тоже часть контракта, иначе
решение могло бы «незаметно» осесть в чужой таблице. На дереве `bf121cf`:
0 непокрытых решений из 39 записей матрицы (12 документов). Отрицательный
прогон (искусственно добавленное несуществующее решение `§Z99` в матрицу)
подтвердил, что проверка действительно ловит пропуск — см. Completion Notes
P3.14, `phases/P3.md`.

## Досыл из P3.13 (Pending Work) — рассмотрено, ОТКЛОНЕНО с причиной

Все четыре пункта, унаследованные из Pending Work P3.9–P3.13, требуют правки
нормативного текста/схем ВНЕ `Files` P3.14 (`tools/rules_index.py`,
`tools/spec_lint.py`, `spec/revisions/2026-08/rules.json`, `README.md`, этот
артефакт) — Scope Excluded P3.14 прямо запрещает точечную правку нормативного
текста по существу внутри этого item'а («чинится в своём документе, с записью
в Pending Work соответствующего item'а», `phases/P3.md` P3.14). Ни один из
четырёх не блокирует ГЕЙТ A2 (все — либо адрес фазы P4/P6, либо самостоятельная
находка, не входящая в матрицу §7 сводного гейта):

1. **Долг глоссария `00_overview.md`** (новые термины документа `11`:
   «контрольная пара», «слой гейта слияния», «отчёт прогона», «строгость
   отказа», «раннер»/«адаптер» без своих строк) — правка `00_overview.md` вне
   `Files`. **Отклонено**, рекомендация без изменений: отдельный fix-item (растёт
   двенадцатую итерацию, `handoff.md` Open risks).
2. **`IDE-27`**: обязанность раннера ждать ФАКТИЧЕСКИЕ числовые коды пина
   (`-32003`/`-32004`), а не коды из текста протокола, не закрыта правилом
   `CFH-*` — правка `11_conformance-harness.md` вне `Files`. **Отклонено**,
   рекомендация: `CFH-55` до фазы P6 (раннер это НОРМИРУЕТ, а фаза P6 его
   реализует).
3. **Латентный двойной матч `oneOf`** в `clarification.json`
   (`#/$defs/ResultType`) и `task-binding.json` (`#/$defs/StatusChannel`,
   `#/$defs/TaskStatus`): ветвь-расширение без `not`-исключения перекрывает
   каждое значение `const` — с P3.11 это нарушение НОРМЫ `IDE-36`/`IDE-37`, а
   не только дефект. Правка схем документов `05`/`07` вне `Files`.
   **Отклонено**, рекомендация: fix-item ДО фазы P4 (иначе линтер `IDE-36`/
   `IDE-37`, который фаза P4 обязана применить и к этим схемам, унаследует
   известный красный).
4. **`SEC-1`** без кода в `error.json#/$defs/ErrorCode`: единственный носитель
   перечисления кодов закрыт `IDE-30` (P3.11) и лежит вне `Files` P3.12/P3.14.
   **Отклонено**, рекомендация: добавить
   `agentools.permission.token_not_issued_for_us` — fix-item к P3.11 либо перед
   фазой P4.

Молчания нет: все четыре названы явно с причиной отказа и рекомендацией —
условие command'а `plan-run` выполнено.

## Вердикт

**ГЕЙТ A2 (исторический, P3.14): PASS**

## Амендмент P4.4 — 2026-08-03

Исходный якорь `bf121cf` выше не переписывается. После аддитивного нормативного амендмента
P4.4 повторный прогон выполнен на item-коммите `4d86009`
(`test(fixtures): complete P4.4 schema bounds vectors`):

| Условие | Команда | Результат | Статус |
|:--|:--|:--|:--|
| Линтер ревизии | `python3 tools/spec_lint.py spec/revisions/2026-08` | `spec_lint: OK`, exit 0 | 🟢 |
| Производный индекс | `python3 tools/rules_index.py --check spec/revisions/2026-08` | `rules_index: OK, расхождений нет (399 правил)`, exit 0 | 🟢 |
| Новые правила | выборка `TLP-33`/`TLP-34` из `rules.json` | обе записи `MUST`, `L1` | 🟢 |
| Целостность fixtures | `python3 tools/spec_lint.py --fixtures-check fixtures/2026-08` | `spec_lint: OK`, exit 0 | 🟢 |

Числа на `4d86009`: всего 399 правил; L1/L2/L3 = 274/83/42; `MUST*` = 388. Профиль
сохраняет исторический PASS P3.14; проверка и фактические приговоры новых artifact-векторов принадлежат
P4.5 и этим амендментом не заявляются.

## P3.19 — повторный терминальный прогон после D141 (2026-08-04)

P3 реопенена решением D141 после RED Gate B round 1. Этот раздел — актуальный A2: он
проверяет форму и свежесть нормативного snapshot после P3.16–P3.18, но **не** утверждает
содержательный GREEN Gate B; предметные remediation-векторы и новый A3 остаются за P4.9–P4.13.
Якорь производного snapshot: item-коммит `6d790fa`
(`chore(spec): regenerate P3 derived rules index (P3.19)`).

| # | Условие | Команда | Результат | Статус |
|:--|:--|:--|:--|:--|
| 1 | Полный линтер ревизии без ослабляющих флагов | `python3 tools/spec_lint.py spec/revisions/2026-08` | `spec_lint: OK` (5 известных предупреждений о SHOULD-векторах) | 🟢 |
| 2 | `rules.json` детерминирован и свеж | `python3 tools/rules_index.py --write spec/revisions/2026-08 && python3 tools/rules_index.py --check spec/revisions/2026-08` | `rules_index: OK, расхождений нет (399 правил)` | 🟢 |
| 3 | Закрытые authoring schemas заново выведены и свежи | `python3 tools/close_schema.py --write spec/revisions/2026-08 && python3 tools/close_schema.py --check spec/revisions/2026-08` | `close_schema: OK (сверено схем: 15)` | 🟢 |
| 4 | Уровни и идентификаторы индекса корректны | Python-проверка `level`/unique `rule_id` | 0 записей вне L1/L2/L3; 0 дубликатов | 🟢 |
| 5 | Легаси-лексика остаётся огороженной | `python3 tools/spec_lint.py --legacy-check spec/revisions/2026-08` | `spec_lint: OK (--legacy-check, 2026-08)` | 🟢 |
| 6 | Owner-ветка D144 воспроизводима | `python3 tools/spec_lint.py --namespace-inventory spec/revisions/2026-08` | `agentools.dev`: 32 файла; `dev.agentools`: 6; combined: 35, `matches_expected: true` | 🟢 |
| 7 | Граница Gate B не подменена формальным PASS | сверка P3.19 Scope Excluded с D141/D143 и отчётом Gate B F1/F7/F8 | Gate B round 2 не запускался; P4 remediation остаётся prerequisite | 🟢 |

Числа на `6d790fa`, полученные из `rules.json`: всего 399 правил; L1/L2/L3 =
274/83/42; `MUST`/`MUST NOT`/`SHOULD`/`MAY` = 273/115/5/6; 6 правил имеют
`not_testable` с причиной; документов — 12. Раскладка по документам: `00` 27 · `01` 17 ·
`02` 34 · `03` 37 · `04` 41 · `05` 21 · `06` 30 · `07` 27 · `08` 44 · `09` 48 ·
`10` 19 · `11` 54.

## Вердикт P3.19

**ГЕЙТ A2: PASS**

PASS означает только согласованность и проверяемую форму snapshot P3. Он не отменяет RED
Gate B round 1 и не разрешает P5.1 round 2 до завершения P4.9–P4.13.

## P3.23 — актуальный повторный A2 после RED-аудита (2026-08-04)

**Якорь source snapshot:** `fd4a52c`
(`feat(spec): address pinned MCP codes and glossary debt (P3.22)`). Перед всеми командами ниже
проверено `git diff --quiet fd4a52c -- spec/revisions/2026-08`: source-набор ревизии до `HEAD`
не менялся. Поэтому результаты воспроизводятся на одном source snapshot; сам этот раздел —
evidence P3.23, а не переписывание исторических verdicts P3.14/P3.19.

| Finding | Команда | Результат на `fd4a52c` | Статус |
|:--|:--|:--|:--|
| F1 — core/fallback `oneOf` | `python3 tools/spec_lint.py --schema-values-selftest spec/revisions/2026-08` | `ResultType`/`StatusChannel`/`TaskStatus`: каждый core принят ровно одной ветвью, vendor — только fallback, пустое и negative-probes отклонены | 🟢 |
| F2 — `EventKind` ↔ lifecycle | `python3 tools/spec_lint.py --schema-values-selftest spec/revisions/2026-08` | `EventKind` содержит полный markdown-набор; lifecycle markdown = schema; `crm.custom` принят только fallback, missing-core negative-probe отклонён | 🟢 |
| F3 — недостаточность прежней свежести A2 | `python3 tools/close_schema.py --write spec/revisions/2026-08 && python3 tools/close_schema.py --check spec/revisions/2026-08` · `python3 tools/rules_index.py --write spec/revisions/2026-08 && python3 tools/rules_index.py --check spec/revisions/2026-08` | authoring schemas: 15, свежи; `rules.json`: 400 правил, расхождений нет. Это дополняется F1/F2, а не подменяет их | 🟢 |
| F4 — адрес числовых кодов | exact-set: `python3 tools/spec_lint.py spec/revisions/2026-08` с проверкой return code и полного списка ошибок | exit 1; единственная ошибка: `MUST-правило CFH-55 не имеет покрывающего вектора` | 🟢 |
| F5 — долг `OVR-25` | Python-проверка шести точных терминов в `00_overview.md` | 6/6 определений присутствуют по одному разу: раннер, адаптер, контрольная пара, слой гейта, отчёт прогона, строгость отказа | 🟢 |

### Дополнительные authoring/selftest-гейты

- `python3 tools/spec_lint.py --legacy-check spec/revisions/2026-08` → `OK`.
- `python3 tools/spec_lint.py --namespace-inventory spec/revisions/2026-08` → 32 domain /
  6 vendor / 35 combined, `matches_expected: true`; `--namespace-inventory-selftest` → `OK`.
- `python3 tools/spec_lint.py --actionable-description-selftest spec/revisions/2026-08` →
  positive accepted, negative rejected.

## Вердикт P3.23

**ГЕЙТ A2: PASS**

PASS относится к согласованному authoring/selftest snapshot P3 и доказанным F1–F5. Он **не**
утверждает зелёный полный `spec_lint`: единственный coverage debt — `CFH-55 → P4.12`; P4.12
создаёт различающую exact-code positive/negative pair, а P4.13 возвращает полный coverage в
green. **Gate B round 1: RED.**

## P3.24 — fail-closed schema-values gate после RED-аудита захода 2 (2026-08-04)

**Якорь item-коммита:** `0279f59`
(`fix(spec): fail closed open-enum gate (P3.24)`). Source-схемы и `rules.json` не менялись:
`git diff --exit-code 86395cf..HEAD -- spec/revisions/2026-08/schema spec/revisions/2026-08/rules.json`
→ exit 0.

| Проверка | Команда | Результат | Статус |
|:--|:--|:--|:--|
| Полный структурный охват | inventory через `_open_string_definitions` | 12 extensible `$defs`: `AuditEventKind`, `ResultType`, `Decision`, `ErrorCode`, `ErrorCategory`, `ActorType`, `EventKind`, `SideEffect`, `Confirmation`, `StatusChannel`, `TaskStatus`, `ContentOrigin` | 🟢 |
| Draft 2020-12 probes | `python3 tools/spec_lint.py --schema-values-selftest spec/revisions/2026-08` | Для каждого из 12 definitions: core принят, vendor принят, пустая строка отклонена; lifecycle markdown = `EventKind` schema | 🟢 |
| Мутация `maxLength: 1` | тот же selftest, in-memory copy `ResultType` fallback | oracle: `vendor.extension` отклонён; structural gate: unknown keyword отклонён | 🟢 |
| Мутация extra integer branch | тот же selftest, in-memory copy `ResultType` `oneOf` | oracle: integer `7` принят; structural gate: non-string branch отклонён | 🟢 |
| Зависимость oracle fail-closed | `python3 -S tools/spec_lint.py --schema-values-selftest spec/revisions/2026-08` | exit 2, stderr содержит prerequisite `python-jsonschema`; fallback PASS отсутствует | 🟢 |
| Обычный lint без third-party | `python3 -S tools/spec_lint.py spec/revisions/2026-08` | выполнился stdlib-only; exit 1 ровно из-за известного coverage debt `CFH-55` | 🟢 |
| Exact-set полного lint | P3.22 exact-set Python-команда | exit 1; единственная ошибка — `MUST-правило CFH-55 не имеет покрывающего вектора` | 🟢 |

## Вердикт P3.24

**ГЕЙТ A2: PASS** для fail-closed authoring/selftest gate. Это не меняет source snapshot,
не объявляет полный coverage зелёным и не снимает **Gate B round 1: RED**. `CFH-55` остаётся
закреплён за P4.12; P3 требует штатного close и третьего независимого audit перед запуском P4.9.
