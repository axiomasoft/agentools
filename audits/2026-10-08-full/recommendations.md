# Порядок исправлений

Замечания A01–A20 из этого плана впоследствии исправлены в той же сессии:
см. [изменения, результаты проверок и порядок миграции](fixes.md).
Ниже сохранены исходная последовательность работ и рекомендации по дальнейшему
развитию; подробный статус выполненного объёма приведён в отчёте об исправлениях.

Работу целесообразно разделить на проверяемые изменения. Каждый semantic fix
должен пройти цепочку spec/fixtures → PHP/Dart → conformance. Не обновлять
ожидания ради GREEN; сначала добавить fixture, показывающий текущий отказ.

| Очерёдность | Состав | Результат | Размер |
|---|---|---|---|
| 1 | A13, A14, A15 | Исполняемая baseline: пути contracts/evidence, PHP 8.3, clean RC bootstrap | Средний |
| 2 | A01, A02, A03, A06 | Audit confidentiality, согласованные hash bytes, отсутствие persist при недопустимом transition | Крупный; для сохранённых данных нужна стратегия совместимости |
| 3 | A04, A05, A07, A08 | Сквозной сценарий с текущей revision, durable dedup, recovery и compensation | Крупный |
| 4 | A09, A10, A11, A12 | Одинаковая валидация на SDK/adapter/runtime boundaries | Средний |
| 5 | A16, A17, A18, A20 | Честные security, compatibility и mutation gates | Средний |
| 6 | A19 и эксплуатационная документация | Один владелец генерации и воспроизводимый developer bootstrap | Небольшой–средний |

Размер — относительная оценка, не обещание календарного срока. A16 и A17 можно
выполнять независимо от semantic fixes. Исправления A02/A03 требуют отдельного
решения о совместимости уже сохранённых hashes; остальные не должны автоматически
тянуть миграцию существующих durable данных.

## Архитектура и границы

**Дать execution context явный контракт.** Revision, tenant/action identity,
scenario run identity, clock и factories ключей сейчас передаются неодинаково.
Это уже проявилось в A04/A07. Малый неизменяемый context с явным жизненным циклом
предпочтительнее глобального service locator. Контекст должен формироваться на
доверенной стороне; caller-controlled поля не должны подменять actor/policy.

**Определить единый canonical event envelope.** SQL projections, JCS, hash chain,
content hash и loaded DTO должны иметь общий байтовый контракт, включая время,
empty object/array и числовой диапазон. Не пытаться получить эту гарантию только
совпадением PHP↔Dart: обе реализации могут повторять одну ошибку, как A03.

**Проверять composition, а не только компоненты.** Нужны consumer-level tests,
использующие public entrypoints и реальные durable adapters. Минимальная матрица:
подтвердить → вызвать через coordinator → сохранить → перезапустить → replay;
отказ второго шага → compensation; stale writer → отказ без нового эффекта;
snapshot mismatch → полный fold. Оставить unit tests для локализации отказа.

**Не смешивать failure channels.** Protocol violation, domain tool failure,
expression failure, storage conflict и infrastructure error должны иметь разные
наблюдаемые результаты. Особенно это касается compensation и mutation gate.
Проверять, что отказ не сопровождается частичной записью или внешним вызовом.

## Тесты и evidence

1. Расширить общий corpus отрицательными примерами из `reproduce.php`/`.dart`.
   Аудиторские пробы описывают наблюдаемый дефект и не являются будущими assertion
   tests: после исправления их вывод закономерно изменится.
2. Добавить независимый числовой oracle и round-trip checks через PostgreSQL.
   Типизированный scripted executor полезен, но не воспроизводит timezone,
   timestamp precision и реальные возвращаемые SQL-типы.
3. Проверять runtime без assertions и no-dev PHP consumer. Иначе development
   среда маскирует обязательные зависимости и проверки.
4. Рассматривать GREEN отдельных receipts как ограниченное утверждение.
   В audit есть green `release_candidate verify`/`sbom verify`, но red aggregate.
   Эти результаты нельзя сворачивать в «релиз готов».
5. Все producers должны сохранять stderr/exit code и достаточно информации для
   воспроизведения consumer failures. Текущий package receipt выбрасывает большую
   часть diagnostic output, поэтому потребовался отдельный diagnostic wrapper.
6. Вычислять exact fixture ID set из versioned manifest вместо жёсткого `213`
   в `p6-gate`; при расширении корпуса размер должен изменяться согласованно,
   без необходимости редактировать несколько независимых чисел.

## Производительность и сопровождаемость

`ActionJournal.append()` заново fold-ит весь массив, а `JournalAppender` повторяет
это для каждой записи. Последовательное построение длинного journal требует
квадратичного числа обработок событий. Это структурная оценка кода, не полученный
в аудите production latency percentile. Рассмотреть validated incremental fold и
checkpoint с equivalence test против полного replay; измерять именно public
append/recovery path на 1/10/100/1000+ events. Не ослаблять проверку seq/hash ради
ускорения.

`tools/` содержит примерно 17,4 тыс. строк Python, из них `spec_lint.py` — 4129.
В нём дважды определены `run_gate_b_remediation_check` и
`run_gate_b_remediation_write`; поздние определения скрывают ранние. Удалить
недостижимую реализацию и разделять lint по проверяемым контрактам постепенно,
с сохранением CLI. В первую очередь вынести общий набор paths, identity receipts
и обработку missing inputs: именно там уже есть реальные отказы.

API manifest содержит 386 entries, из них только 12 stable и 315 advanced.
Расширять stable поверхность следует по реальным consumer paths, а не объявлять
все доступные классы стабильными. Для PHP/Dart отдельно объяснить различия tier
у одноимённых фасадов, например IdempotencyCoordinator.

## Документация и эксплуатация

Обновить installation документ после bootstrap fixes: команды для чистого clone,
runtime extensions, размещение двух Dart packages, настройка SQL executor и
изолированной тестовой базы. Для нового canonical time/hash contract описать,
как читать старые records и когда требуется новая revision.

В operations добавить проверяемые процедуры restore backup, смены token keys,
очистки expired rows, реакции на ambiguous external effect и проверки integrity
после восстановления. Наличие runbook само по себе не является evidence rehearsal:
приёмка должна сохранять результаты изолированного исполнения.

Прежний аудит 2026-09-21 полезен как история, но не как текущий список задач.
Action↔SHA matching, trusted-ref guard, обязательный candidate corpus, восемь
semantic families, локальная установка MCP runner, расширенный Dependabot и
узкие secret allowlists уже реализованы. Эти исправления следует сохранить.
Отсутствие CODEOWNERS/публичного governance для private single-owner repository
не включено в список runtime/release blockers.

## Условия следующей приёмки

P1 закрыты исполняемыми отрицательными тестами. PHP/Dart fixtures совпадают по
результатам и отсутствию side effects на отказе. Чистый checkout проходит
обязательную runtime matrix, security scan, package consumers, live storage,
conformance и rebuild private RC evidence. Документация соответствует реально
выполненным командам. Изменения frozen `2026-08` не требуются. Публичная публикация
по-прежнему требует отдельного owner release gate.
