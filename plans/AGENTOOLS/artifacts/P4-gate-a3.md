# P4 — итоговый гейт A3

**Дата:** 2026-08-03

**Якорь дерева:** remediation-коммит `f5a740e`

**Команда гейта:** `python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --check`

## Условия гейта

| Условие | Факт на `f5a740e` | Вердикт |
|:--|:--|:--|
| Индекс правил свежий | `rules_index: OK`, 399 правил (на `f5a740e`) | PASS |
| MUST/MUST NOT покрыты | 382 из 382 тестируемых обязательных правил покрыты (на `f5a740e`) | PASS |
| Обратная ссылка «вектор → правило» | 0 осиротевших `spec_rule_ids` (на `f5a740e`) | PASS |
| Artifact-корпус исполняется | 50 artifact-векторов (на `f5a740e`) → `spec_lint: OK (--fixtures-check, fixtures/2026-08)` | PASS |
| `common/` каноничен | 0 расхождений копий с каноном при совпадающем `name` (на `f5a740e`) | PASS |
| SHOULD-сигнал опубликован | 5 непокрытых SHOULD-правил перечислены warning'ами (на `f5a740e`) | PASS с предупреждениями |
| Отчёт покрытия свежий | `--coverage --write` и `--coverage --check` → exit 0 (на `f5a740e`) | PASS |
| Новые fixtures структурно валидны | 14 remediation-кейсов прошли Draft 2020-12 офлайн через локальный registry (на `f5a740e`) | PASS |
| Историческая ревизия неизменна | `spec_lint: OK`, exit 0; SHA-256 вывода `ec567136e3fc626842410c2a8f4ff1b9d1fec4d534f1304c79b224f53ebe35c4` (на `f5a740e`) | PASS |

## Счёт покрытия

| Срез | Всего | Покрыто |
|:--|--:|--:|
| Все правила | 399 (на `f5a740e`) | 383 тестируемых (на `f5a740e`) |
| Тестируемые правила | 393 (на `f5a740e`) | 383 (на `f5a740e`) |
| MUST/MUST NOT всего | 388 (на `f5a740e`) | 382 (на `f5a740e`) |
| MUST/MUST NOT тестируемые | 382 (на `f5a740e`; ещё 6 имеют причину `Not testable`) | 382 (на `f5a740e`) |
| L1 | 274 (на `f5a740e`) | 269 (на `f5a740e`) |
| L2 | 77 (на `f5a740e`) | 76 (на `f5a740e`) |
| L3 | 42 (на `f5a740e`) | 38 (на `f5a740e`) |

Корпус трёх исполняемых классов содержит 154 вектора (на `f5a740e`): behavior — 93,
artifact — 50, harness — 11 (каждое число на `f5a740e`). Дополнительно покрытие читает
10 golden-meta регистраций (на `f5a740e`). Детализация по каждому правилу — в
`fixtures/2026-08/coverage.md`.

## Remediation

Добавлены 14 различающих кейсов (на `f5a740e`): 6 исполняемых artifact-кейсов для
identity/negotiation/tool-profile/digest/authoring/lifecycle-index и 8 behavior-кейсов для
untrusted input, journal fold/concurrency/integrity, policy/token/dual-control и MRTR state.
Десять прежних копий `given.tools` приведены к `common/`; два GREASE-кейса с намеренно
расширенным дескриптором получили отдельные имена инструментов, поэтому их различающая
семантика сохранена, а канонический fragment не подменяется.

Adversarial self-review (на `f5a740e`): удаление единственного носителя `EXT-1` возвращает
exit 1 и адресную ошибку; инверсия ключевых полей каждого нового artifact-kind даёт reject,
а положительные subjects — accept. Храповик `min_vectors = 154` равен фактическому корпусу.

**ГЕЙТ A3: PASS**
