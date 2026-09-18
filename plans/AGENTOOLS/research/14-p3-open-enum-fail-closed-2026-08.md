# 14 — Fail-closed open-enum gate после второго RED-аудита P3

**Статус:** design input для третьего реопена P3  
**Сырьё:** `../phases/P3.md` → второй блок `## Audit P3 — 2026-08-04`, F1–F5 ·
`../findings/P3-jsonschema-oracle-2026-08.md` · `13-p3-red-audit-remediation-2026-08.md` ·
`../../../tools/spec_lint.py` `_matches_open_enum_branch`/`_open_string_definitions`/
`run_schema_values_selftest`.

## 1. Диагноз

P3.20 исправил текущие схемы, но его общий gate не доказал собственную границу. Обходчик
сначала отбрасывает все ветви, кроме string `const` и string fallback, а мини-интерпретатор
понимает только `const`/`enum`/`type`/`minLength`/`pattern`/`not`. Поэтому стандартный keyword
`maxLength` и дополнительная integer-ветвь меняют исполняемую Draft 2020-12 семантику, но
дают пустой список ошибок. `RAG:— (repo-grounded: phases/P3.md Audit P3 заход 2 F1;
tools/spec_lint.py:600-703)`.

## 2. Решение

Gate делится на два слоя с разными обязанностями:

1. **Структурный fail-closed слой (обычный lint, stdlib-only).** Он учитывает каждую ветвь
   исходного `oneOf`, разрешает только канонические формы текущего open-string контракта и
   отвергает лишнюю/необъектную/non-string ветвь, неизвестный для канона keyword, неверный тип
   keyword либо второй fallback. Диагностика содержит файл + JSON pointer + имя ветви/keyword.
   Отфильтровать неподдержанную ветвь и продолжить запрещено.
2. **Семантический oracle (только адресный selftest).** Лениво импортированный
   `jsonschema.Draft202012Validator` проверяет текущие core/vendor/empty probes и две
   обязательные мутации `maxLength`/integer branch. Отсутствие библиотеки даёт явный exit 2;
   сетевой install из инструмента запрещён. `RAG:✅ 2026-08-04: findings/P3-jsonschema-oracle-2026-08.md`.

Это узко сужает D14: production/full lint остаётся stdlib-only, но его специальный
`--schema-values-selftest` больше не имеет права объявлять семантический PASS без настоящего
Draft 2020-12 oracle. Цена — отдельная локальная test-зависимость на машинах, где запускают
этот selftest; отсутствие зависимости видно как красный prerequisite, а не ложный GREEN.

## 3. Декомпозиция и границы

Нужен один item P3.24: checker и его evidence тесно связаны, нормативные source-схемы не
меняются. Отдельная orchestration не объявляется: один item, одна детерминированная Validation.
P4.9 остаётся заблокирован до исполнения P3.24, повторного close и третьего аудита P3.

Не меняются `CFH-55`, глоссарий, published/authoring schemas, P4/P6 fixtures, Gate B и
исторические Completion Notes P3.1–P3.22. Плановая бухгалтерия F2–F5 исправляется самим
design-заходом, а не подмешивается в item-коммит `tools/spec_lint.py`.

## 4. Pre-mortem

| Причина провала | Ранний сигнал | Mitigation | Owner |
|:--|:--|:--|:--|
| Новый keyword снова проигнорирован | branch keys шире allowlist, lint зелёный | точное множество разрешённых ключей + адресный reject | P3.24 / `tools/spec_lint.py` |
| Extra branch исчезает при классификации | число `7` проходит мутированную схему, gate молчит | считать все исходные ветви и отдельно отвергать non-string | P3.24 |
| Oracle недоступен, но selftest зелёный | import подавлен fallback-логикой | import failure = exit 2, без stdlib-замены | P3.24 |
| Evidence снова доказывает только текущий snapshot | в A2 нет двух мутаций и их oracle verdict | таблица maxLength/integer branch с командами и git-anchor | `artifacts/P3-gate-a2.md` |
