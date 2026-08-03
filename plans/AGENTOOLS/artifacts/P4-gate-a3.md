# P4 — ГЕЙТ A3: BLOCKED — корпус не покрывает все MUST/MUST NOT правила

**Дата:** 2026-08-03

**Якорь дерева:** item-коммит `98aebcf`

**Команда гейта:** `python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check`

## Условия гейта

| Условие | Факт на `98aebcf` | Вердикт |
|:--|:--|:--|
| Индекс правил свежий | `python3 tools/rules_index.py --check spec/revisions/2026-08` → `rules_index: OK`, 399 правил (на `98aebcf`) | PASS |
| MUST/MUST NOT покрыты | 304 из 382 тестируемых обязательных правил покрыты; 78 без вектора (на `98aebcf`) | **BLOCKED** |
| Обратная ссылка «вектор → правило» | 0 осиротевших `spec_rule_ids` (на `98aebcf`) | PASS |
| Artifact-корпус исполняется | 44 artifact-вектора (на `98aebcf`) → `spec_lint: OK (--fixtures-check, fixtures/2026-08)` | PASS |
| `common/` каноничен | 10 копий дескрипторов расходятся с каноном при том же `name` (на `98aebcf`) | **BLOCKED** |
| SHOULD-сигнал опубликован | 5 непокрытых SHOULD-правил перечислены warning'ами (на `98aebcf`) | WARN |
| Отчёт покрытия свежий | `--coverage --check`: отдельной диагностики drift нет; exit 1 вызван material-пробелами выше (на `98aebcf`) | PASS |
| Историческая ревизия неизменна | `spec_lint: OK`, exit 0; SHA-256 вывода `ec567136e3fc626842410c2a8f4ff1b9d1fec4d534f1304c79b224f53ebe35c4` (на `98aebcf`) | PASS |

## Счёт покрытия

| Срез | Всего | Покрыто |
|:--|--:|--:|
| Все правила | 399 (на `98aebcf`) | 305 тестируемых (на `98aebcf`) |
| Тестируемые правила | 393 (на `98aebcf`) | 305 (на `98aebcf`) |
| MUST/MUST NOT всего | 388 (на `98aebcf`) | 304 (на `98aebcf`) |
| MUST/MUST NOT тестируемые | 382 (на `98aebcf`; ещё 6 имеют причину `Not testable`) | 304 (на `98aebcf`) |
| L1 | 274 (на `98aebcf`) | 201 (на `98aebcf`) |
| L2 | 77 (на `98aebcf`) | 74 (на `98aebcf`) |
| L3 | 42 (на `98aebcf`) | 30 (на `98aebcf`) |

Корпус трёх исполняемых классов содержит 140 векторов (на `98aebcf`): behavior — 85,
artifact — 44, harness — 11 (каждое число на `98aebcf`). Дополнительно покрытие читает
10 golden-meta регистраций (на `98aebcf`). Детализация по каждому правилу — в
`fixtures/2026-08/coverage.md`.

## Artifact-приговоры D139

Один общий physical-schema walker дал следующие результаты (на `98aebcf`):

- depth 16 → accept; depth 17 → reject: `глубина схемы 17 превышает максимум 16`;
- subschemas 256 → accept; subschemas 257 → reject: `число подсхем 257 превышает максимум 256`;
- network `$ref` → reject с подстрокой `сетевой $ref`.

## Что блокирует PASS

Линтер нашёл 88 ERROR (на `98aebcf`): 78 непокрытых MUST/MUST NOT правил и 10
расхождений копий `given.tools` с каноническими `common/` при совпадающем `name`.
Дополнительно опубликованы 5 SHOULD-warning (на `98aebcf`). По Scope Excluded P4.5 эти
дефекты исправляются векторами их исходных authoring-items, а не приписыванием маркеров
существующим нерелевантным векторам в гейт-item'е.

**ГЕЙТ A3: BLOCKED — 78 обязательных правил без вектора и 10 расхождений `common/`.**
