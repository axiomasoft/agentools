# P2.2 — Прогон PHP-кандидатов по корпусу проб bakeoff

Метод и шкала вердиктов — `bakeoff/README.md`. Вердикт ставится по наблюдаемому выводу команды
(`bakeoff/results/php-*.log`), не по документации кандидата.

## Кандидаты

| Кандидат | Версия | Установка | Комментарий |
|:--|:--|:--|:--|
| `wol-soft/php-json-schema-model-generator` | 0.26.2 (+ `-production` 0.20.3) | composer, `bakeoff/php/composer.lock` | автодетект диалекта не заявлен в README, но фактически принимает `$schema: 2020-12` без отката — проверено ниже по конструкциям |
| `quicktype --lang php` | 26.0.0 | `npx quicktype` (без установки в песочницу — CLI одноразовый) | `--src-lang schema`, входной файл — `schema.json` пробы |
| `swaggest/php-json-schema` | — | не устанавливался | **рассмотрен, исключён**: документирует только Draft 7/6/4 (`findings/P2-bakeoff-recon-2026-07-27.md` §3), 2020-12 не таргет |

PHP: 8.4.1 (cli, NTS). Окружение — `findings/P2-bakeoff-recon-2026-07-27.md` §2.

## Матрица «проба × кандидат»

| Проба | Кандидат | Вердикт | Round-trip (unknown-key.json) | Лог |
|:--|:--|:--|:--|:--|
| p01-open-enum (oneOf const+pattern fallback) | wol-soft | `ok` — oneOf const-ветки и pattern-fallback валидируются корректно (проверено негативным тестом: значение вне ветвей → `ErrorRegistryException`) | REJECTED — `additionalProperties:false` на схеме отклоняет весь документ (`ErrorRegistryException`), round-trip не выполняется в принципе | `php-model-gen-p01-open-enum.log`, `php-roundtrip-p01-open-enum.log` |
| p01-open-enum | quicktype | `ignored-gracefully` — oneOf сведён к плоскому `string`, композиция/pattern не проверяются (`validate()` — только `is_string`); ожидаемо для роли «каркас типов» (`DR-01` §J6) | field-lost — `from()`/`to()` явно перечисляют только `kind`, неизвестное свойство документа отбрасывается молча (проверено эмпирически, не только структурно) | `php-quicktype-p01-open-enum.log`, `php-quicktype-p01-roundtrip.log` |
| p02-unknown-keyword (вендорный ключ схемы `x-agentools-note`) | wol-soft | `ok` — вендорный ключ проигнорирован на уровне свойства, свойство `note` сгенерировано и валидируется нормально | REJECTED — `additionalProperties:false` отклоняет документ с неизвестным свойством | `php-model-gen-p02-unknown-keyword.log`, `php-roundtrip-p02-unknown-keyword.log` |
| p02-unknown-keyword | quicktype | `ok` — вендорный ключ схемы проигнорирован, `note` сгенерировано как `string` | field-lost (структурно — та же схема from/to, не проверялось раздельно) | `php-quicktype-p02-unknown-keyword.log` |
| p03-unevaluated (`unevaluatedProperties:false` на allOf, закрытая схема авторинга) | wol-soft | `ignored-gracefully` — ключевое слово `unevaluatedProperties` НЕ реализовано генератором (не падает, но и не закрывает схему); поля не потеряны | SURVIVES — `x_unknown_probe_field` доходит до `getRawModelDataInput()` невредимым (единственная проба, где round-trip сохраняет неизвестное свойство) | `php-model-gen-p03-unevaluated.log`, `php-roundtrip-p03-unevaluated.log` |
| p03-unevaluated | quicktype | `ok` — allOf корректно слит в один тип (`id` required, `note` optional), семантика композиции сохранена на уровне формы | field-lost (структурно) | `php-quicktype-p03-unevaluated.log` |
| p04-dynamic-ref (`$dynamicRef`/`$dynamicAnchor`, рекурсия) | wol-soft | `ignored-gracefully` — генерация НЕ падает (вопреки ожиданию README), но `children` типизирован как нетипизированный `mixed[]` — рекурсивная валидация вложенных узлов потеряна | REJECTED (`additionalProperties:false`) | `php-model-gen-p04-dynamic-ref.log`, `php-roundtrip-p04-dynamic-ref.log` |
| p04-dynamic-ref | quicktype | `ignored-gracefully` — `children` тоже нетипизированный `array`, рекурсия не смоделирована, но не падает | field-lost (структурно) | `php-quicktype-p04-dynamic-ref.log` |
| p05-defs-allof (`$defs` + allOf композиция базового типа) | wol-soft | **`crash`** — `SchemaException`: «A referenced schema on base level must provide an object definition» (`BasereferenceProcessor.php:33`) | n/a (генерация не дошла до модели) | `php-model-gen-p05-defs-allof.log` |
| p05-defs-allof | quicktype | `ok` — `$defs`+allOf корректно слиты в плоский тип `{id: string, title: string}`, оба поля required | field-lost (структурно) | `php-quicktype-p05-defs-allof.log` |
| p06-if-then-else (if/then/else + dependentSchemas) | wol-soft | `ignored-gracefully` — if/then/else **enforced корректно** (`kind=llm` без `prompt_ref` → `ErrorRegistryException`, проверено позитивным и негативным тестом), НО `dependentSchemas` (side_effect=write → approval обязателен) **не валидируется вовсе** (документ без `approval` конструируется без ошибки) | REJECTED (`additionalProperties:false`) | `php-model-gen-p06-if-then-else.log`, `php-roundtrip-p06-if-then-else.log`, `../results/php-p06-semantics.log` |
| p06-if-then-else | quicktype | `ignored-gracefully` — все условные поля (`prompt_ref`/`tool`/`side_effect`/`approval`) стали просто optional, ни if/then/else, ни dependentSchemas не проверяются | field-lost (структурно) | `php-quicktype-p06-if-then-else.log` |
| p07-prefix-items (`prefixItems` + `items:false`) | wol-soft | **`crash`** — `TypeError`: `JsonSchema::withJson(): Argument #1 must be of type array, false given` (`ArrayProcessor.php:159` — код генератора не ожидает `items:false`) | n/a | `php-model-gen-p07-prefix-items.log` |
| p07-prefix-items | quicktype | `field-lost` — `hash_pair` сведён к нетипизированному `array`, фиксированная длина/порядок (tuple-семантика `prefixItems`) полностью потеряны | field-lost (структурно) | `php-quicktype-p07-prefix-items.log` |
| p08-nested-depth (вложенность 6 уровней + `patternProperties`) | wol-soft | `ok` — все 6 уровней и `patternProperties` на `l6` корректно смоделированы и провалидированы (негативный тест: неизвестный ключ вне `^attr_[a-z0-9]+$` → `ErrorRegistryException`) | REJECTED (`additionalProperties:false`) | `php-model-gen-p08-nested-depth.log`, `php-roundtrip-p08-nested-depth.log`, `../results/php-p08-pattern.log` |
| p08-nested-depth | quicktype | **`field-lost`** — все 6 уровней вложенности смоделированы классами `L1`…`L6`, но `L6` (носитель `patternProperties`) сгенерирован **пустым** (`to()`/`from()` без полей) — любые динамические ключи (`attr_*`) полностью теряются при round-trip | field-lost (все данные `l6` теряются, не только неизвестное свойство) | `php-quicktype-p08-nested-depth.log` |
| p09-ref-local (относительный `$ref` на соседний файл) | wol-soft | `ok` — `$ref` на `common.json#/$defs/Id` резолвлен по локальному файлу (без сети), `pattern` унаследован и валидируется | REJECTED (`additionalProperties:false`) | `php-model-gen-p09-ref-local.log`, `php-roundtrip-p09-ref-local.log` |
| p09-ref-local | quicktype | `ignored-gracefully` — `$ref` резолвлен структурно (`id: string`), но `pattern` из `common.json` не перенесён (нет валидации) | field-lost (структурно) | `php-quicktype-p09-ref-local.log` |
| p10-numbers (minor units / big integer / экспонента) | wol-soft | `ok` — точность сохранена побитово: `amount_minor`/`big_integer` — `int` (включая `PHP_INT_MAX`), `scientific` — `float` (`1.0E+21`) | REJECTED (`additionalProperties:false`) | `php-model-gen-p10-numbers.log`, `php-roundtrip-p10-numbers.log`, `../results/php-p10-numbers.log` |
| p10-numbers | quicktype | `ok` — те же типы (`int`/`int`/`float`), точность сохранена | field-lost (структурно) | `php-quicktype-p10-numbers.log` |

## Наблюдение: два разных отказа round-trip (важно для `DR-01` §E3, must-ignore)

**wol-soft** при `additionalProperties:false` (8 из 10 проб несут этот ключ) **отклоняет весь
документ** исключением при конструировании модели — новое неизвестное свойство ломает приём
целиком, а не только теряется. **quicktype** никогда не отклоняет документ — но `from()`/`to()`
всегда перечисляют только известные на момент генерации свойства явным списком, поэтому
неизвестное свойство **молча теряется** при каждом round-trip, независимо от `additionalProperties`
схемы. Это два разных режима отказа must-ignore (E3): fail-closed (wol-soft) против
silent-data-loss (quicktype) — оба нарушают открытость опубликованных схем, но по-разному; ни
один candidate не даёт «терпимость и сохранение поля» из коробки. Реализация E3 (при выборе роли
кодогенерации «каркас типов») остаётся на SDK, не на генераторе — ожидаемо по `DR-01` §J6.

Round-trip для p03 (единственный SURVIVES у wol-soft) — не результат корректной поддержки
must-ignore, а побочный эффект нереализованного `unevaluatedProperties`: генератор просто не
проверяет закрытость схемы вообще.

## Контрольная точка «DTO руками» (D32)

На тех же 3 пробах, что и в Dart (P2.3, для сравнимости выбраны: p01 — минимальная сложность,
p06 — условная логика с двумя видами ветвления, p08 — глубокая вложенность), написаны вручную
PHP-классы без кодогенерации: `bakeoff/php/manual/P01OpenEnum.php` (27 строк),
`bakeoff/php/manual/P06IfThenElse.php` (56 строк), `bakeoff/php/manual/P08NestedDepth.php`
(28 строк) — **111 строк на 3 типа** (в среднем ~37 строк/тип), включая конструктор с валидацией,
`fromArray`/`toArray` и все условные правила (в т.ч. `dependentSchemas`, которую НИ ОДИН
генератор не реализовал). Самопроверка (`test-manual-dto.php`) — позитивные и негативные кейсы
зелёные (лог не отдельный файл — вывод команды приведён в Completion Notes item'а).

Ориентир цены отказа от кодогенерации в PHP: **не выше 40 строк на тип** при письме руками с
полной валидацией, включая конструкции, которые оба кандидата теряют/не проверяют
(`dependentSchemas`, `patternProperties` на глубине, tuple-семантика).
