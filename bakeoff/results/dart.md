# P2.3 — Прогон Dart-кандидатов по корпусу проб bakeoff

Метод и шкала вердиктов — `bakeoff/README.md`. Вердикт ставится по наблюдаемому выводу
команды (`bakeoff/results/dart-*.log`), не по документации кандидата.

## Кандидаты

| Кандидат | Версия | Лицензия (license) | Установка | Комментарий |
|:--|:--|:--|:--|:--|
| `quicktype --lang dart` | 26.0.0 | license: Apache-2.0 (сам quicktype, CLI) | `npx quicktype` | `--src-lang schema`, вход — `schema.json` пробы |
| `json_schema_builder` | 0.1.6 | license: BSD-3-Clause | `dart pub add`, `pubspec.lock` | **проверено фактически (Scope п. "а"): экспорты пакета (`lib/json_schema_builder.dart`) несут ТОЛЬКО классы схемы и валидатор — ни одного generate-API. Это НЕ кодогенератор, а библиотека валидации** — подтверждает `R-11` §10 |
| `json_schema` (пин D15) | 5.2.2 | license: BSD (Workiva) | уже пинован | использован как валидатор в контрольной точке «DTO руками» (не как кандидат-кодогенератор) |

Dart: 3.12.2 (stable). Окружение — `findings/P2-bakeoff-recon-2026-07-27.md` §2.

## Матрица «проба × кандидат»

### `quicktype --lang dart`

| Проба | Вердикт | Round-trip (unknown-key.json) | Лог |
|:--|:--|:--|:--|
| p01-open-enum | `ignored-gracefully` — oneOf сведён к плоскому `String`, композиция/pattern не проверяются | field-lost — `fromJson`/`toJson` явно перечисляют только `kind` (проверено эмпирически) | `dart-quicktype-p01-open-enum.log`, `dart-quicktype-roundtrip.log` |
| p02-unknown-keyword | `ok` — вендорный ключ схемы проигнорирован, `note` сгенерировано корректно | field-lost (структурно, та же кодогенерирующая стратегия) | `dart-quicktype-p02-unknown-keyword.log` |
| p03-unevaluated | `ok` — allOf корректно слит (`id` required, `note` optional) | field-lost (структурно) | `dart-quicktype-p03-unevaluated.log` |
| p04-dynamic-ref | `ignored-gracefully` — `children` → `List<dynamic>` (генератор явно предупреждает: «cannot infer this type because there is no data about it in the input»), не падает | field-lost (структурно) | `dart-quicktype-p04-dynamic-ref.log` |
| p05-defs-allof | `ok` — `$defs`+allOf корректно слиты в `{id: String, title: String}` | field-lost (структурно) | `dart-quicktype-p05-defs-allof.log` |
| p06-if-then-else | `ignored-gracefully` — все условные поля стали nullable, ни if/then/else, ни dependentSchemas не проверяются (та же деградация, что и в PHP-кандидате) | field-lost (структурно) | `dart-quicktype-p06-if-then-else.log` |
| p07-prefix-items | `ignored-gracefully` — `hash_pair` → `List<String>` (элементный тип СОХРАНЁН, в отличие от PHP-кандидата, где стал нетипизированным `array`), но фиксированная длина/tuple-семантика `prefixItems` потеряна | field-lost (структурно) | `dart-quicktype-p07-prefix-items.log` |
| p08-nested-depth | **`field-lost`** — 6 уровней вложенности смоделированы классами, но `L6` (носитель `patternProperties`) сгенерирован ПУСТЫМ — все данные `l6` теряются при round-trip (эмпирически подтверждено, тот же паттерн, что PHP-кандидат) | field-lost (все данные `l6`, не только неизвестное свойство) | `dart-quicktype-p08-nested-depth.log`, `dart-quicktype-roundtrip.log` |
| p09-ref-local | `ignored-gracefully` — `$ref` резолвлен структурно (`id: String`), но `pattern` из `common.json` не перенесён | field-lost (структурно) | `dart-quicktype-p09-ref-local.log` |
| p10-numbers | `ok` — типы корректны (`int`/`int`/`double`), точность `big_integer`/`scientific` сохранена побитово (проверено эмпирически) | field-lost (структурно, но сами числа не искажены) | `dart-quicktype-p10-numbers.log`, `dart-quicktype-roundtrip.log` |

**Итог quicktype dart:** `ok`×4 (p02, p03, p05, p10) · `ignored-gracefully`×5 (p01, p04, p06, p07, p09) ·
`field-lost`×1 (p08). Заметно ЛУЧШЕ PHP-кандидата на p07 (элементный тип массива сохраняется), но
идентично на p08 (`patternProperties` теряет данные целиком) и на условной логике p06.

### `json_schema_builder` — проверка фактической способности (Scope п. "а")

Пакет **не кодогенератор**: `lib/json_schema_builder.dart` экспортирует только классы схемы
(`ObjectSchema`, `StringSchema`, …), `SchemaRegistry` и `SchemaValidation` — ни одного метода
генерации типов/DTO. Вердикт по шкале P2.1 для ВСЕХ 10 проб: **`not-supported`** (кандидат не
имеет таргета «сгенерировать Dart-тип», Scope Excluded этого item'а прямо предупреждает не
«чинить» кандидата до соответствия).

Однако Scope Included требует не просто зафиксировать `not-supported` и остановиться, а
**проверить фактическую способность** — сделано: пакет прогнан как ВАЛИДАТОР (`Schema.fromMap` +
`schema.validate(data)`) по всем 10 пробам:

| Проба | `valid.json` | `unknown-key.json` |
|:--|:--|:--|
| p01-open-enum | 0 ошибок | 2 ошибки: `additionalPropertyNotAllowed` + `notConditionViolated` |
| p02-unknown-keyword | 0 ошибок | 2 ошибки (аналогично) |
| p03-unevaluated | 0 ошибок | 1 ошибка: `custom` / «Schema is false» — **`unevaluatedProperties` ЗДЕСЬ РЕАЛЬНО ЭНФОРСИТСЯ** (в отличие от PHP wol-soft, который эту конструкцию просто не реализует) |
| p04-dynamic-ref | 0 ошибок | 2 ошибки (аналогично p01) |
| p05-defs-allof | 0 ошибок | **0 ошибок** — схема открытая (без `additionalProperties`/`unevaluatedProperties`), корректно разрешает лишнее свойство |
| p06-if-then-else | 0 ошибок | 2 ошибки (аналогично) |
| p07-prefix-items | 0 ошибок | 2 ошибки (аналогично) |
| p08-nested-depth | 0 ошибок | 2 ошибки (аналогично) |
| p09-ref-local | 0 ошибок | 2 ошибки (аналогично) |
| p10-numbers | 0 ошибок | 2 ошибки (аналогично) |

**Вывод:** `json_schema_builder` — полноценный и КОРРЕКТНЫЙ валидатор JSON Schema 2020-12 (все
10 конструкций корпуса, включая `$dynamicRef`, внешний `$ref`, `prefixItems`, `if/then/else`,
`unevaluatedProperties`, глубокую вложенность), но не участвует в вопросе «кодогенерация»
вообще — ответ на Q-9/`DR-02` по этому кандидату: не применим как генератор, валиден как
альтернатива-кандидат для роли ВАЛИДАТОРА (наравне с уже пинованным `json_schema` 5.2.2, D15;
замена пина этим item'ом НЕ производится — вне Scope). Логи —
`bakeoff/results/dart-jsb-*.log`.

## Контрольная точка «DTO руками + валидатор `json_schema` 5.2.2» (D32)

На тех же 3 пробах, что и в PHP (P2.2: p01, p06, p08), написаны вручную Dart-классы,
делегирующие ВСЮ валидацию пинованной библиотеке `json_schema` 5.2.2 (сама схема грузится и
передаётся вызывающим кодом, не встраивается в DTO): `bakeoff/dart/manual/p01_open_enum.dart`
(18 строк), `bakeoff/dart/manual/p06_if_then_else.dart` (41 строка),
`bakeoff/dart/manual/p08_nested_depth.dart` (28 строк) — **87 строк на 3 типа** (в среднем
~29 строк/тип, на **24% меньше** PHP-варианта — 111 строк, — поскольку `json_schema` 5.2.2
корректно валидирует и `if/then/else`, И `dependentSchemas` из коробки, тогда как ручной PHP-код
дублировал обе ветки условной логики руками).

Самопроверка (`dart run manual-test/main.dart`, лог `bakeoff/results/dart-manual-dto-selftest.log`) —
все позитивные и негативные кейсы зелёные, в т.ч. подтверждён факт: **`json_schema` 5.2.2
enforced `dependentSchemas`** (`FormatException: prop side_effect violated schema dependency`) —
конструкция, которую НИ ОДИН из 4 кодогенераторов (2 языка × 2 кандидата) не реализовал
самостоятельно. Это подкрепляет решение `DR-01` §J6 (валидация — не работа генератора, а
работа выделенной библиотеки) фактом, а не декларацией.

Ориентир цены отказа от кодогенерации в Dart: **не выше 45 строк на тип** при письме руками с
делегированием валидации `json_schema` 5.2.2 — дешевле, чем PHP-путь, именно из-за более
полной библиотеки валидации.
