# P6 — фазовое исполнение behavior-корпуса без ложных эскалаций

Дата: 2026-08-05
Статус: repo-grounded synthesis для re-design P6.2/P6.3
Источник: `phases/P6.md`, `conformance/bin/run`,
`conformance/src/Conformance/Runner.php`, frozen `fixtures/2026-08/vectors/l[123]/*.json`.

## 1. Наблюдённый дефект плана

P6.2 разрешал адаптеру реализовать только документы `01`–`05`, `09`, но его Validation запускал
раннер без фильтра и требовал exit 0 на всём behavior-корпусе. На дереве `6b2d67f` раннер загружает
214 behavior-векторов; 92 из них принадлежат только rule families `SCN`/`LOP`/`AOB`/`SEC`, явно
отложенным до P6.3. Поэтому требование полного зелёного прогона в P6.2 противоречило фазовой
декомпозиции D66. Это дефект ТЗ, а не новый owner-gate. `RAG:— (repo-grounded)`.

## 2. Инвариант исправления

Harness, а не adapter, выбирает исполняемый срез по `spec_rule_ids`. Adapter продолжает получать
обычный wire payload и не вправе читать `id`, `expect` или `spec_rule_ids`. Отбор должен быть
публичным CLI-контрактом раннера, воспроизводимым в PHPUnit и отчёте; список vector IDs вручную не
ведётся. `RAG:— (repo-grounded: conformance harness owns corpus loading; P6.2 metadata ban)`.

CLI получает `--rules <selector-list>`, где selector-list — comma-separated exact rule IDs или
namespace patterns вида `EXT-*`. Вектор выбирается, если хотя бы один его `spec_rule_ids` совпал.
Некорректный selector даёт usage error, валидный selector с нулевым результатом — runner error;
нулевой прогон никогда не считается зелёным. Нормализованный selector и selected count попадают в
JSON report. `RAG:— (design decision D181)`.

## 3. Закрытое разбиение

| Стадия | Selector | Ожидаемый набор на `6b2d67f` | Acceptance |
|:--|:--|--:|:--|
| P6.2 core | `EXT-*,TLP-*,ALC-*,CNF-*,CLR-*,IDE-*,CFH-48,CFH-49` | 122 | selected run exit 0; все GREASE входят, включая `grease-003` через CFH-48/49 |
| P6.3 extension | `SCN-*,LOP-*,AOB-*,SEC-*` | 92 | selected run exit 0, затем full run exit 0 |
| P6.9 phase gate | без `--rules` | 214 | повторный полный gate плюс mutant/level report |

Проверка корпуса на `6b2d67f`: два набора непересекаются, их объединение содержит 214/214
behavior-векторов. PHPUnit обязан вычислять это из frozen corpus и краснеть при overlap, gap,
неожиданном count, invalid selector и zero-match selector. Counts закреплены именно потому, что
корпус frozen; D180 меняет stimulus одного существующего вектора, но не membership.
`RAG:— (repo-grounded: Python traversal of fixtures/2026-08/vectors/l[123]/*.json)`.

## 4. Почему это не новая эскалация

P6.1 уже владеет загрузкой корпуса и фильтром по vector ID; узкое расширение selector'а остаётся
внутри того же conformance harness. P6.2 получает в `Files` runner/README/tests для этой добавки.
Нормативная семантика и frozen fixtures не расширяются. Полный прогон переносится не «когда-нибудь»,
а в два названных acceptance-гейта P6.3 и P6.9. Следовательно, у исполнителя P6.2 есть конечное,
детерминированное ТЗ без повода обращаться к владельцу. `RAG:— (repo-grounded)`.

## 5. Pre-mortem и защиты

- Selector совпал с нулём и дал ложный green → zero-match является runner error, отдельный test.
- Между срезами появилась дыра/перекрытие → partition-test доказывает disjoint union = full corpus.
- Adapter начал ветвиться по metadata → прежний grep-ban и code review остаются acceptance P6.2.
- Full corpus снова потребовали раньше реализации P6.3 → D181 назначает full-run только P6.3/P6.9.
- GREASE потерялся из core → CFH-48/49 включены exact selectors; test требует все `grease-*` в core.

## 6. Coherence-sweep оставшейся P6

После исправления P6.2/P6.3 проверены контракты P6.4–P6.9. P6.4–P6.6 потребляют уже терминальные
результаты предыдущих items и имеют deterministic negative runs; `rules.json` фактически несёт
400 полей `not_testable`, из них 6 с нормативной причиной, а golden delivery уже нормирован
`CFH-38`…`CFH-44`/D117. P6.8 и P6.9 запускаются после полного adapter acceptance P6.3.
`RAG:— (repo-grounded)`.

Ещё один carrier drift найден у P6.7: phase order/roadmap называли Q-12 блокирующим owner-gate,
хотя D68 и сам item уже задают ветку (C) при отсутствии ответа и разрешают закрытие `🟢`. Поэтому
Q-12 — необязательный input, а не остановка: нет ответа → branch C; явный выбор (A) → branch A;
только явный выбор (B), несовместимый с frozen revision, создаёт настоящий blocker с названной
ценой. Это фиксируется D182 и убирает ещё одну будущую ложную остановку. `RAG:— (repo-grounded)`.
