# Протокол и SDK

Для воспроизводимых примеров используются только синтетические данные. Наблюдаемые
результаты сохранены в [PHP log](evidence/runtime-probes-php.log) и
[Dart log](evidence/runtime-probes-dart.log). Ссылки на исходники относятся к дереву
на дату аудита. `advanced` API не имеет обещания SemVer-стабильности, но остаётся
исполняемым кодом SDK: этот статус не устраняет описанные ошибки.

## A01 · P1 · Вложенные запрещённые данные проходят audit boundary

**Код:** [PHP AuditRedaction](../../php/src/Ops/AuditRedaction.php#L25),
[PHP EventStoreSupport](../../php/src/Persistence/EventStoreSupport.php),
[Dart AuditRedaction](../../dart/agentools/lib/src/ops/audit_redaction.dart),
[Dart EventStoreSupport](../../dart/agentools/lib/src/persistence/event_store_support.dart).
Контракт: [AOB-14/AOB-16](../../spec/revisions/2026-09/08_audit-observability.md), HRD-24.

Проверяется только имя верхнего поля. Значения `trace`, `*_id`, `*_digest` могут
быть произвольными вложенными объектами. Проба передаёт
`{"trace":{"token":"AUDIT_FAKE_SECRET","email":"audit@example.invalid"}}`.
Обе реализации возвращают эти значения после redaction; storage validation тоже
принимает payload. По тому же пути данные могут попасть в durable audit и экспорт.
Это не доказательство утечки существующих секретов, а воспроизведённый обход
обещанной проверки запрещённых полей.

**Исправление:** определить допустимые типы и значения для каждого audit kind.
Скалярные идентификаторы/дайджесты не должны принимать Map/List. Для `trace` нужен
закрытый вложенный объект с конкретными trace/span полями. До persist применять
единый валидатор; export должен строить документ из разрешённых полей.
Простого рекурсивного удаления слова `token` недостаточно: пользовательский текст
под разрешённым именем также нарушает смысл AOB-14.

**Приёмка:** одинаковые PHP/Dart fixtures для вложенного token/email/password,
массивов внутри `*_id`, неизвестных trace-полей и корректных trace/span IDs.
Отказ должен происходить до записи строки и до вызова AuditSink.

## A02 · P1 · PostgreSQL меняет данные, по которым вычисляется hash chain

**Код:** [insertAll/hydrate/timestamp](../../php/src/Persistence/PdoPostgresEventStore.php#L208),
[SQL schema](../../fixtures/hardening/sql/event-store.v1.sql),
[JournalHashChain](../../php/src/Action/JournalHashChain.php).

При batch append ссылка на предыдущий event вычисляется по исходной строке `ts`.
В БД `ts` имеет тип `TIMESTAMPTZ`; после load PHP снова форматирует его через
`DATE_ATOM`, теряя дробные секунды и используя offset возвращённого значения.
Проба сохраняет два события с `2026-10-08T10:00:00.123Z`. В тестовом PostgreSQL
возвращается `2026-10-08T13:00:00+03:00`. Проверка цепочки после load даёт false,
`ActionReducer` с hash chain выбрасывает `JournalIntegrityException`.

Вход был допустимым timestamp. Ошибка выглядит как повреждение журнала, хотя данные
записал сам SDK. На точный вид строки влияет timezone сессии, но потеря дробной
части остаётся отдельной причиной несовпадения. У Dart SQL adapter `ts` просто
принимается как String: форматирование остаётся дополнительным требованием к
интеграторскому executor. Полный аналогичный Dart DB round trip этой пробой не доказан.

**Исправление:** закрепить каноническую форму времени и точность до вычисления
content/hash-chain digest; сохранять и восстанавливать те же bytes. Вариант —
хранить канонический event envelope отдельно от SQL-проекций timestamp. Изменение
формата существующих цепочек требует версионирования и миграционного решения.

**Приёмка:** multi-event batch и sequential append/load/fold в UTC и non-UTC
сессиях, с `Z`, offset и fractional seconds. Хеши до/после persistence совпадают;
read/replay старых записей определяется явно.

## A03 · P1 · PHP/Dart согласны между собой, но расходятся с JCS на больших int

**Код:** [PHP serialize](../../php/src/Schema/Canonicalizer.php#L44),
[Dart serialize](../../dart/agentools/lib/src/schema/canonicalizer.dart).
Независимый результат: [Node number oracle](evidence/node-number-oracle.log).

В обеих реализациях ветка integer обходит сериализацию IEEE-754 numbers.
`canonicalizeJson('{"n":9007199254740993}')` возвращает исходное число в PHP и Dart.
Node `JSON.parse`/`JSON.stringify` для того же текста возвращает
`{"n":9007199254740992}`. Это скалярный числовой oracle, а не заявление о полном
соответствии `JSON.stringify` всем правилам сортировки JCS.

RFC 8785 ограничивает входные числа double precision и использует ECMAScript
serialization; для более точных величин рекомендует строки.
[Первичный источник: RFC 8785 §§3.1, 3.2.2.3 и Appendix D](https://www.rfc-editor.org/rfc/rfc8785.html).
Принять непредставимое целое и выдать его как обычный JCS number нельзя считать
проверенным interoperable поведением. Последствия — различающиеся fingerprint,
descriptor digest или подпись у JS-клиента и SDK.

**Исправление:** единая специфицированная политика числового входа: отклонение
непредставимых точно целых либо преобразование входного JSON в double по правилам
JCS. Для доменных сумм/идентификаторов за допустимой границей — явное строковое
представление; не вводить незаметное округление денежных величин.

**Приёмка:** независимые golden cases вокруг ±2^53, int64 extrema, exponent
boundaries и одинаковых чисел из native values/raw JSON. Проверять результат с
внешним числовым oracle, а не только PHP↔Dart.

## A04 · P1 · ScenarioRunner генерирует недопустимые idempotency keys

**Код:** [PHP ScenarioRunner:65](../../php/src/Scenario/ScenarioRunner.php#L65),
[Dart ScenarioRunner](../../dart/agentools/lib/src/scenario/scenario_runner.dart),
[IdempotencyKey](../../php/src/Idempotency/IdempotencyKey.php).
Контракт: [IDE-4](../../spec/revisions/2026-09/09_idempotency-errors.md).

Runner передаёт `scenarioId:stepId`, а компенсация — дополнительный суффикс
`:compensation`. `IdempotencyCoordinator` принимает только
`^at1:[A-Za-z0-9_-]{8,128}$`. Композиция двух публичных классов с valid capability
падает на первом шаге с `InvalidArgumentException`/`ArgumentError`. Проба в обоих
SDK использует обычный ToolPort wrapper и не вызывает внешний эффект.

Простая замена двоеточий недостаточна: scenario definition ID не различает два
независимых запуска одного сценария. После исправления грамматики остаётся риск
replay старого результата или key reuse между разными run instances.

**Исправление:** ввести явную identity запуска и сохраняемый mapping
run/step/operation → opaque valid key. Повтор шага и возобновление того же запуска
получают прежний ключ; новый запуск — новый. Для компенсации определить отдельную
стабильную identity и согласовать её с SCN-25.

**Приёмка:** ScenarioRunner → IdempotencyCoordinator → durable store → fake external
tool, включая retry, restart/resume, два запуска одного scenario и компенсацию.
Проверять число внешних вызовов, ключи и replay результатов.

## A05 · P1 · Компенсация теряет контекст и не возвращает нормативный исход

**Код:** [PHP handleFailure:100](../../php/src/Scenario/ScenarioRunner.php#L100),
[Dart _handleFailure](../../dart/agentools/lib/src/scenario/scenario_runner.dart).
Контракт: [SCN-26/SCN-27](../../spec/revisions/2026-09/06_scenarios.md).

При обработке `compensate.input_map` передаётся пустой context вместо context
исходного run. Сценарий reserve → fail с компенсацией
`{"resource":"$ctx.resource"}` сначала выполняет reserve, затем останавливается
на `Missing path $ctx.resource`. Undo не вызывается. Ошибка вычисления аргументов
компенсации выходит наружу, а `COMPENSATION_FAILED` не формируется.

**Исправление:** передать исходный контекст в разворот; заранее определить, какие
данные compensation binding замораживаются после успешного шага. Обернуть
вычисление аргументов и вызов компенсации в единый failure boundary, записать
stopped step и прекратить дальнейший разворот при сбое.

**Приёмка:** компенсация с `$ctx` и `$steps`, отсутствующее поле контекста, ошибка
вычислителя и ошибка ToolPort. Нужны проверка обратного порядка, сохранённый
`COMPENSATION_FAILED` и отсутствие вызова следующих компенсаций после отказа.

## A06 · P1 · JournalAppender сохраняет event раньше lifecycle validation

**Код:** [PHP JournalAppender:74](../../php/src/Ops/JournalAppender.php#L74),
[Dart JournalAppender](../../dart/agentools/lib/src/ops/journal_appender.dart).

Порядок операций — store.append, store.load, затем journal.append/reducer. Если
переход запрещён, исключение возникает после durable commit. Проба вызывает
`append('action_completed')` в пустом journal: наружу выходит lifecycle exception,
но в store уже находится **одно событие**. Исполнение доказано на in-memory
реализации того же интерфейса; у PHP PostgreSQL append самостоятельно commit-ит
транзакцию до возврата, поэтому внешний rollback appender здесь отсутствует.

Ошибка ввода превращается в записанное повреждение потока. Локальный journal
остаётся на старом seq, что также ломает последующий retry через тот же appender.

**Исправление:** предварительно применять reducer к предлагаемому event на
актуальном состоянии, затем persist с CAS expected sequence. После конфликта
перезагрузить состояние и повторить проверку. Если проверка требует назначенных
store полей, дать store атомарный validated append boundary. Недопустимо лечить
это удалением уже сохранённого append-only event.

**Приёмка:** недопустимый первый переход, повтор terminal transition, stale epoch,
revision mismatch. После каждого отказа число строк и текущий seq неизменны;
следующий допустимый event сохраняется без ручного ремонта потока.

## A07 · P2 · EffectCommitter жёстко зависит от reducer ревизии 2026-08

**Код:** [PHP EffectCommitter](../../php/src/Effects/EffectCommitter.php),
[ActionReducer:22](../../php/src/Action/ActionReducer.php#L22),
[Dart EffectCommitter](../../dart/agentools/lib/src/effects/effect_committer.dart).

`ActionJournal.state()` и `append()` без reducer используют `2026-08`.
EffectCommitter всегда вызывает именно их и не принимает reducer/revision.
На journal `2026-09` он падает ещё до applier с ошибкой другой ревизии.
Обе реализации воспроизводят отказ. JournalAppender также по умолчанию пишет
predecessor revision; передача ему `specRevision: 2026-09` без соответствующего
reducer добавляет риск A06.

**Исправление:** сделать revision частью явного execution context или journal и
передавать соответствующий reducer всем участникам. Сохранить явный отдельный
путь для frozen predecessor. Не ограничиваться заменой одной строки default:
revision должен быть согласован между appender, state, effects и restore.

**Приёмка:** полный effect flow для `2026-09`, явная regression-конфигурация для
`2026-08`, ранний отказ на смешанном потоке до persistence/side effect.

## A08 · P2 · Restore принимает snapshot другой ревизии

**Код:** [PHP ActionSnapshot:33](../../php/src/Action/ActionSnapshot.php#L33),
[Dart ActionSnapshot](../../dart/agentools/lib/src/journal/action_snapshot.dart).
Контракт: [HRD-25](../../spec/revisions/2026-09/12_hardening-semantics.md).

`restoreOrDiscard` проверяет только совпадение contentHash. Если tail пуст,
ActionJournal.restore возвращает snapshot.state без проверки revision reducer.
Проба с snapshot `2099-01`, expected revision `2026-09` и совпадающим hash возвращает
seq=1, хотя полный журнал содержит seq=2. Hash здесь — специально заданный одинаковый
синтетический marker: проба проверяет независимый revision gate.

**Исправление:** до использования snapshot проверять revision, action/tenant
binding и checkpoint. При несовпадении — fold полного журнала. Отдельно определить,
кто вычисляет expected hash из реально сохранённых snapshot bytes.

**Приёмка:** wrong revision при matching hash и пустом tail обязательно приводит
к восстановлению полного журнала; неверный hash, action и checkpoint дают тот же
безопасный fallback. Корректный snapshot с tail эквивалентен полному fold.

## A09 · P2 · Dart нормализует невозможную дату в подписанном токене

**Код:** [Dart _timeClaim:242](../../dart/agentools/lib/src/confirmation/token_envelope.dart#L242),
[PHP timeClaim](../../php/src/Confirmation/TokenEnvelope.php).

Подписанный синтетическим ключом token содержит `2026-02-30` в iat/nbf/expires_at.
PHP отвергает календарную дату. Dart проходит regex и нормализует её в марте через
DateTime.parse; parse возвращает accepted. Проба использует одинаковые canonical
claims/signature bytes и now `2026-03-02T11:00:00Z`.

Для эксплуатации этой конкретной разницы нужен подписанный malformed token;
подделка HMAC без ключа не продемонстрирована. Проблема — несовпадающая валидация
протокола и возможность выдачи несовместимого токена из Dart.

**Исправление:** после parsing проверять точное соответствие исходных календарных
компонентов; не принимать нормализацию overflow. Согласовать ограничения времени
в едином наборе fixtures для issue и parse.

**Приёмка:** February 30/29 в невисокосный год, month 00/13, hour 24, minute/second
overflow. Обе реализации возвращают одинаковый token-invalid code.

## A10 · P2 · Assertions используются как обязательная Dart-валидация

**Код:** [ActionEvent](../../dart/agentools/lib/src/journal/action_event.dart#L19),
[UncommittedEvent](../../dart/agentools/lib/src/persistence/uncommitted_event.dart),
[SessionStateProjection](../../dart/agentools/lib/src/journal/session_state_projection.dart),
[EventStreamPage](../../dart/agentools/lib/src/persistence/event_stream_page.dart).

Непустые identity и допустимые seq/epoch проверяются через assert. Обычный запуск
пробы без `--enable-asserts` создаёт event с пустым event_id, seq=0,
expected_prev_seq=-1; с assertions создание отклоняется. PHP-конструктор применяет
явные runtime checks. Успешный `dart test` не доказывает production-валидацию
этого пути.

**Исправление:** необходимые контракту проверки выполнять явным кодом и выбрасывать
типизированную ошибку. Assertions оставить для внутренних инвариантов, уже
обеспеченных public boundary.

**Приёмка:** одни и те же negative fixtures проходят в test runner и в subprocess
без assertions/скомпилированном executable; malformed objects не создаются.

## A11 · P2 · Expiry и fingerprint проверяются в разном порядке

**Код:** [PHP PostgreSQL:163](../../php/src/Idempotency/PdoPostgresIdempotencyStore.php#L163),
[PHP memory](../../php/testkit/InMemoryDeduplicationStore.php),
[Dart SQL](../../dart/agentools/lib/src/idempotency/sql_idempotency_store.dart),
[Dart memory](../../dart/agentools/lib/src/idempotency/in_memory_deduplication_store.dart).

SQL implementation проверяет fingerprint раньше удаления expired completed record.
Memory сначала удаляет истёкшую запись. Проба завершает key с arguments v=1,
ждёт логически за пределом retention и предъявляет тот же key с v=2:
memory → `new`, PostgreSQL → `idempotency_key_reuse`. Это наблюдаемое отличие
testkit от production; в Dart исходниках тот же порядок, но отдельный live Dart
прогон именно этого случая не выполнялся.

**Исправление:** определить семантику reuse после retention и синхронизировать
порядок checks. Если expired completed record считается отсутствующим, удалять
его через безопасный CAS и повторять acquire до fingerprint comparison.
In-progress записи нельзя удалять только по retention.

**Приёмка:** одинаковые/изменённые arguments до expiry, ровно на границе и после;
race cleanup/acquire/takeover; memory и обе durable implementations совпадают.

## A12 · P2 · Не объявлена обязательная PHP-зависимость mbstring

**Код:** [composer require](../../php/composer.json#L6),
[ExpressionEvaluator](../../php/src/Scenario/ExpressionEvaluator.php),
[Canonicalizer](../../php/src/Schema/Canonicalizer.php#L75).

Production-код вызывает `mb_strlen` и `mb_convert_encoding`; Composer require
не содержит `ext-mbstring` либо production polyfill. Polyfill в текущем lock
приходит через require-dev. Проба без php.ini и development autoload воспроизводит
undefined function в выражении и `CanonicalizationException` с такой причиной
при сортировке двух ключей. Это целевой dependency probe, а не полный прогон SDK
на минимальном PHP image.

**Исправление:** объявить `ext-mbstring: *` как обязательную runtime dependency
либо подключить совместимый polyfill в require и проверить его производительность.
Документировать extension requirements в private installation.

**Приёмка:** чистый no-dev consumer: Composer заранее отказывает неподходящей среде
либо polyfill обеспечивает корректную работу. Unicode canonicalization и evaluator
проверяются без случайных транзитивных dev dependencies.
