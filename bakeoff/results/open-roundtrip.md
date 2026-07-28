# P4.6 — Ре-замер round-trip must-ignore на ОТКРЫТЫХ схемах (F3 аудита фазы P2)

Метод и шкала — `bakeoff/README.md`. Корпус — `bakeoff/probes-open/**`: те же 10 конструкций
P2.1, но со снятым `"additionalProperties": false` на внешнем уровне документа (p03 переносится
как есть — она уже открытая; p05 не переносится — её `crash` не связан с `additionalProperties`,
переизмерение не добавляет сигнала). Кандидаты и версии — те же, что в P2.2/P2.3: `wol-soft`
0.26.2 (PHP), `quicktype` 26.0.0 (PHP и Dart). Вердикт — по наблюдаемому выводу команды
(`bakeoff/results/open-*.log`), не по документации кандидата.

## Матрица «проба × кандидат», колонка round-trip (unknown-key.json)

| Проба | Проверяемая конструкция | `wol-soft` (PHP) | `quicktype` (PHP) | `quicktype` (Dart) |
|:--|:--|:--|:--|:--|
| p01-open-enum | `oneOf` + `const` + `pattern` | **SURVIVES** | field-lost | field-lost |
| p02-unknown-keyword | вендорный ключ схемы `x-agentools-note` | **SURVIVES** | field-lost | field-lost |
| p03-unevaluated | `unevaluatedProperties: false` поверх `allOf` (уже открытая — перенесена как есть) | **SURVIVES** (то же значение, что в P2.2 — факт прежнего прогона не переписан) | field-lost | field-lost |
| p04-dynamic-ref | `$dynamicRef` / `$dynamicAnchor` | **SURVIVES** | field-lost | field-lost |
| p05-defs-allof | `$defs` + `$ref` + `allOf` | **не переизмерялась** — `crash` P2.2 не связан с `additionalProperties` (см. `bakeoff/README.md` §Метод; `Scope Excluded` item'а) | — | — |
| p06-if-then-else | `if`/`then`/`else` + `dependentSchemas` | **SURVIVES** | field-lost | field-lost |
| p07-prefix-items | `prefixItems` + `items: false` | **crash** (`TypeError` на `items:false`, `n/a` для round-trip — тот же дефект, что в P2.2, не связан со снятием `additionalProperties`) | field-lost | field-lost |
| p08-nested-depth | `patternProperties` на глубине 6 уровней | **SURVIVES** | field-lost | field-lost |
| p09-ref-local | относительный `$ref` на соседний файл (`common.json`) | **SURVIVES** | field-lost | field-lost |
| p10-numbers | minor units / big integer / экспонента | **SURVIVES** | field-lost | field-lost |

**Итог `wol-soft` на открытых схемах:** `SURVIVES`×8 (все, кроме p05 — не переизмерялась, и p07 —
`crash`, `n/a`). Инверсия закрытого прогона P2.2 (там: `REJECTED`×8 · `SURVIVES`×1 (p03) ·
`n/a`×2 (p05, p07)) — round-trip на закрытых пробах действительно измерял закрытость СХЕМЫ, не
терпимость `wol-soft`: сняв `additionalProperties: false`, генератор пропускает неизвестное
свойство насквозь во всех 8 пробах, где раньше отклонял документ. `wol-soft` вообще не реализует
проверку закрытости (ни `additionalProperties`, ни `unevaluatedProperties` не энфорсятся сборкой
модели) — прежний `REJECTED` был исключительно следствием формы схемы, ровно вывод F3.

**Итог `quicktype` (оба языка) на открытых схемах:** `field-lost`×9/9 без единого исключения —
идентично закрытому прогону P2.2/P2.3 (там же — molчаливая потеря на всех 10 пробах). Подтверждён
факт из `bakeoff/results/php.md` «Наблюдение: два разных отказа round-trip»: `quicktype` теряет
неизвестное свойство документа независимо от `additionalProperties` схемы, потому что
`from()`/`to()` перечисляют поля явным списком — открытие схемы этот режим отказа не меняет.

## Вывод по F3 (`DR-01` §E3, must-ignore)

Единственный кандидат, реально сохраняющий неизвестное свойство документа при открытой схеме —
`wol-soft`, и он делает это на ВСЕХ восьми переизмеренных пробах, не только на p03. Прежний вывод
фазы P2 «ни один кандидат не даёт терпимость + сохранение поля» **не опровергнут для `quicktype`**
(подтверждён на открытом корпусе — silent data loss не связан с закрытостью схемы), но был
**неполным для `wol-soft`**: закрытая проба P2.1 измеряла отказ схемы, а не отказ генератора;
реальная терпимость `wol-soft` к неизвестным свойствам — `ok` на открытой форме, что и предсказывала
находка F3 по единственной уже-открытой пробе (p03).

**Влияние на вердикт «DTO руками» (D51): нулевое**, как и предполагалось аудитом P2 — вердикт
стоит на независимых фактах (`crash` p05, `field-lost` p08 у `quicktype`), которые этим ре-замером
не пересматриваются.

## Энфорсмент валидатора слоя 1 CI (T-2, `DR-02` §7)

Логи — `bakeoff/results/layer1-php.log` (`opis/json-schema` 2.6.0, пин D15) и
`bakeoff/results/layer1-dart.log` (`json_schema` 5.2.2, пин D15).

| Валидатор | `unevaluatedProperties` (p03) | `dependentSchemas` (p06) |
|:--|:--|:--|
| `opis/json-schema` 2.6.0 (PHP) | **энфорсит** — `unknown-key.json` → `INVALID`, keyword `unevaluatedProperties`, `x_unknown_probe_field` назван явно | **энфорсит** — документ `side_effect=write` без `approval` → `INVALID`, keyword `dependentSchemas` |
| `json_schema` 5.2.2 (Dart, пин D15) | **энфорсит** — та же проба → `INVALID`, ошибка на пути `/unevaluatedProperties` | **энфорсит** — та же проба → `INVALID`, ошибка на пути `/dependentSchemas/side_effect` |

Дополнительно прогнан `opis/json-schema` против реальной закрытой схемы авторинга ревизии
(`spec/revisions/2026-08/schema/authoring/audit-event.json`, композиция `allOf` +
`$ref: lifecycle-event.json` + собственный `unevaluatedProperties: false`): документ с лишним
top-level ключом отклонён (`INVALID`). Наблюдение: сработавший лист ошибки —
`additionalProperties` схемы `lifecycle-event.json` (она замыкает документ раньше, чем
собственный `unevaluatedProperties` `audit-event.json` — композиция через `allOf` останавливается
на первой сработавшей ветке), а не `unevaluatedProperties` напрямую; закрытие документа тем не
менее подтверждено фактически. **Именованный остаток:** ни одна закрытая схема авторинга ревизии
`2026-08` не использует `dependentSchemas` (`grep -rl dependentSchemas spec/ fixtures/` — пусто) —
энфорсмент этого ключевого слова на реальном содержимом ревизии не измерялся за отсутствием
материала; единственное прямое измерение — корпус `bakeoff/probes/p06`.

**Вывод по T-2 и Списку B (`DR-02` §3):** подтверждён на ОБОИХ языках, не только на Dart-стороне,
как было до этого ре-замера. Строка Списка B «`unevaluatedProperties: false` на внешнем уровне
композиции — это и есть механизм закрытия» — эмпирически подтверждена и на PHP-валидаторе слоя 1
CI. Расхождений со Списками A/B не обнаружено, правка `DR-02` не требуется.
