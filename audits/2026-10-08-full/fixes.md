# Исправления по аудиту от 8 октября 2026

После поручения commit/push выполнена [интеграция с актуальным main](integration-main.md).
Она сохраняет принятый в main API с `IdempotencyKey`; произвольный `runId` в Dart
теперь передаётся через `runWithId`. Ниже сохранены результаты этапа до интеграции.

По поручению владельца в той же сессии исправлены **все 20 подтверждённых замечаний A01–A20**. Изменения находятся в рабочем дереве поверх `87dbb5500eaf48c13ef9160e4ee11afdf2942371`. Проверки SDK, conformance, безопасности, совместимости, собранных пакетов и приватного RC проходят. Публичная публикация по-прежнему требует решения владельца.

Исходные [выводы аудита](README.md), пробы и журналы сохранены как история состояния до исправлений. Настоящий документ описывает последующую работу. [Машинная сводка](evidence/fix-verification.json) связывает команды с журналами и фиксирует сохранность изменений пользователя. Каждый `fix-*.json` содержит команду, каталог, UTC-время, длительность и exit code; соседний `.log` — её вывод.

## Изменения и доказательства

### A01 — audit payload проверяется до записи и экспорта

В [PHP AuditRedaction](../../php/src/Ops/AuditRedaction.php) и [Dart AuditRedaction](../../dart/agentools/lib/src/ops/audit_redaction.dart) используется общая проверка для хранения и экспорта. Строковые поля больше не принимают произвольные объекты или списки. Проверяются обязательные поля известных видов событий, enum, формат валюты, связь `observed > limit_value` и обязательный шаг при `COMPENSATION_FAILED`.

В trace context разрешены только корректный `trace_id` и необязательный `span_id`; вложенные и посторонние поля отвергаются. Суммы и счётчики ограничены безопасным целочисленным диапазоном binary64; отрицательные счётчики запрещены. Опубликованная JSON Schema сохраняет предусмотренную протоколом расширяемость; storage/export применяют более строгую политику. Authoring schema пересобрана владельцем генерации.

Общие отрицательные примеры находятся в [audit-regressions-v1.json](../../fixtures/hardening/audit-regressions-v1.json). [PHP-тесты](../../php/tests/Adversarial/AuditRegressionTest.php) и [Dart-тесты](../../dart/agentools/test/adversarial/audit_regression_test.dart) проверяют отказ до persistence и export, а также допустимые данные.

### A02 — точные timestamps сохраняются в PostgreSQL

[PHP EventStore](../../php/src/Persistence/PdoPostgresEventStore.php) и [Dart EventStore](../../dart/agentools/lib/src/persistence/sql_event_store.dart) сохраняют исходный timestamp в `ts_text TEXT NOT NULL`. `ts TIMESTAMPTZ` остаётся проекцией для SQL-запросов. При загрузке используются исходные байты `ts_text`, поэтому timezone соединения и нормализация PostgreSQL не меняют hash chain.

PHP-регрессия использует разные реальные PDO-соединения и часовые пояса, пакетную и последовательную запись, дробные секунды и восстановление журнала. Dart проверяет точное сохранение/загрузку через контракт SQL executor; отдельно исполнены межъязыковые live PostgreSQL schedules. [Схема новых установок](../../fixtures/hardening/sql/event-store.v1.sql) обновлена. Для существующих баз добавлена [явная expand-миграция](../../fixtures/hardening/sql/event-store.timestamp-v2-expand.sql); порядок применения приведён ниже.

### A03 — JCS согласован с ECMAScript

[PHP Canonicalizer](../../php/src/Schema/Canonicalizer.php) и [Dart Canonicalizer](../../dart/agentools/lib/src/schema/canonicalizer.dart) переводят native integers в binary64 перед форматированием. Например, `9007199254740993` канонизируется как `9007199254740992`, как при ECMAScript JSON parsing/serialization. Shared corpus проверяет границы ±2^53, int64 и экспоненциальную запись; ожидаемые строки получены независимым Node oracle.

Большие точные значения необходимо передавать строками. Для audit amounts/counters выход за safe integer range отвергается до hashing. При чтении ранее сохранённых нестандартных JCS-данных следует учитывать изменение числового контракта; старые hashes автоматически не переписываются.

### A04 — сценарий совместим с IdempotencyCoordinator

В [PHP ScenarioRunner](../../php/src/Scenario/ScenarioRunner.php) и [Dart ScenarioRunner](../../dart/agentools/lib/src/scenario/scenario_runner.dart) добавлен необязательный `runId`. Ключ вызова: `at1:` + SHA-256 от JCS-массива `[runId, scenario_id, step_id, operation]`; выполнение и компенсация используют разные `operation`. Вызов без `runId` получает новую случайную идентичность, повтор с прежним `runId` воспроизводит прежний ключ.

Регрессия выполняет сценарий через настоящий IdempotencyCoordinator: повтор запуска не вызывает инструмент второй раз, независимый запуск получает другой ключ. Shared fixture фиксирует одинаковые ожидаемые ключи PHP/Dart. Для восстановления после перезапуска вызывающая сторона должна сохранить `runId` **до первого эффекта**; runner не обеспечивает это хранение самостоятельно.

### A05 — контекст и исход компенсации

Компенсация получает исходный `$ctx`/context. Ошибка вычисления аргументов или исключение инструмента приводят к `CompensationFailed` с идентификатором остановившегося шага; дальнейший rollback прекращается. Технический отказ основного шага проходит через обработку сценария. Сохранён обратный порядок компенсаций.

Общие регрессии покрывают контекст компенсации, отсутствующее поле контекста и исключение транспорта, проверяя наблюдаемый исход и реальные вызовы инструментов.

### A06 — недопустимый переход не попадает в EventStore

[PHP JournalAppender](../../php/src/Ops/JournalAppender.php) и [Dart JournalAppender](../../dart/agentools/lib/src/ops/journal_appender.dart) сначала строят кандидат события и проверяют его reducer-ом, затем выполняют CAS append. Отказ устаревшему writer остаётся обязанностью EventStore. Отрицательный тест подтверждает: запрещённый lifecycle transition оставляет хранилище пустым. Конкурентные schedules продолжают проходить.

### A07 — ревизия согласована между journal и effects

ActionReducer и JournalAppender по умолчанию используют `2026-09`. ActionJournal определяет ревизию по первому событию; для пустого журнала применяется текущая ревизия. EventLog adapters и conformance entrypoints передают согласованную ревизию. EffectCommitter обрабатывает candidate journal.

Явная `2026-08` поддерживается: legacy tests теперь указывают её явно, frozen reference regression проходит. Файлы `spec/revisions/2026-08` и `fixtures/2026-08` не изменены.

### A08 — неподходящий snapshot отбрасывается

В [PHP ActionSnapshot](../../php/src/Action/ActionSnapshot.php) и [Dart ActionSnapshot](../../dart/agentools/lib/src/journal/action_snapshot.dart) проверяются action, revision, seq, digest и точное соответствие полного tail авторитетному журналу. При несоответствии выполняется полный fold. Shared tests покрывают будущую ревизию и пустой/усечённый tail: неподтверждённое состояние snapshot не становится состоянием action.

### A09 — невозможные календарные даты токена отвергаются

[Dart TokenEnvelope](../../dart/agentools/lib/src/confirmation/token_envelope.dart) сверяет компоненты даты и времени после парсинга, исключая автоматический перенос невозможных дат в следующий месяц. Один набор malformed timestamps проверен PHP и Dart на выдаче и на authenticated parse подписанного токена. Допустимые значения продолжают проходить.

### A10 — Dart guards работают без assertions

В ActionEvent, UncommittedEvent, SessionStateProjection и EventStreamPage обязательные `assert` заменены runtime-проверками с `ArgumentError`. [Отдельная программа](../../dart/agentools/test/adversarial/runtime_guards_probe.dart), запускаемая тестом через `dart run` без assertions, подтверждает все четыре отказа. Публичные синхронные API остались синхронными.

### A11 — одинаковое истечение idempotency rows

[PHP PostgreSQL store](../../php/src/Idempotency/PdoPostgresIdempotencyStore.php) и [Dart SQL store](../../dart/agentools/lib/src/idempotency/sql_idempotency_store.dart) проверяют expiry завершённой строки до сравнения fingerprint. Истёкший completed key можно использовать заново согласно retention policy. Живые completed rows и in-progress leases защищены от key reuse с другими аргументами; lease не удаляется как expired result.

PHP-тест сравнивает memory store и настоящий PostgreSQL на границе срока; Dart проверяет последовательность SQL и оба защищённых состояния.

### A12 — объявлена production-зависимость mbstring

В [php/composer.json](../../php/composer.json) добавлено `ext-mbstring: *`. Требование проверяется Composer при установке, включая no-dev consumer. CI устанавливает расширение явно. Strict manifest validation и проверки собранного consumer проходят.

### A13 — исполняемые gates отделены от формата планов

Контракты, решения и свидетельства, необходимые инструментам, размещены в [verification/](../../verification/README.md). Из мигрированных планов извлечены 46 требуемых документов с проверкой provenance; источники и исходные digests записаны в [provenance.json](../../verification/provenance.json). Исторические baselines сохраняют прежний смысл; актуальные receipts пересозданы штатными owner commands.

Пути исправлены в Python tools, conformance test и workflows. Миграция `plans/` пользователя не откатывалась. Package, resource, conformance, private RC и Python gates исполняются без отсутствующих старых Markdown carriers.

При финальной сверке обнаружено дополнительное расхождение: повторный private-release capture менял время неизменившейся acceptance matrix и тем самым digest, на который уже ссылался docs receipt. Capture теперь сохраняет байты матрицы при неизменных входах, а при изменении входов обновляет её и finding. Регрессионный тест проверяет обе ветви; итоговая цепочка matrix → docs → consumers → dossier → acceptance сверена по SHA-256.

### A14 — lock устанавливается на PHP 8.3

В Composer зафиксирована платформа `php: 8.3.0`; несовместимые Symfony 8.x заменены допустимым 7.4-графом. Обновлён lock, platform checks не отключались. На настоящем `/usr/bin/php8.3` версии 8.3.35 выполнены locked install dry run и **261 тест с PostgreSQL без skips**. Основной локальный runtime — PHP 8.4.1.

### A15 — полный bootstrap extended/private RC

[ci-bootstrap.sh](../../scripts/ci-bootstrap.sh) устанавливает зависимости обоих Composer-проектов, обоих Dart-пакетов и официального MCP runner. [ci-postgres.sh](../../scripts/ci-postgres.sh) создаёт PostgreSQL cluster для отдельного CI job; workflows запускают live storage schedules. PHP setup включает mbstring и pdo_pgsql.

Bootstrap исполнен в [временной копии без установленных зависимостей](verify_clean_bootstrap.py): окружение восстановлено, source locks не изменены. Workflow YAML и supply-chain policy проходят проверку. Hosted GitHub Actions здесь не запускались; локальный прогон не подменяет полную hosted runtime matrix.

### A16 — уязвимая dev-зависимость MCP обновлена

В [npm lock](../../conformance/mcp/package-lock.json) `@modelcontextprotocol/sdk` обновлён до **1.32.1**, закрывающей выявленное advisory. OSV не обнаружил уязвимостей в проверенных locks. Повторно исполнен официальный MCP runner: **7/7 применимых сценариев** проходят. Это не заявление о прохождении всех 32 frozen required scenarios: остальные 25 не входят в применимую поверхность.

### A17 — Gitleaks проверяет историю и текущее дерево

Runner сканирует Git history и текущие tracked/non-ignored untracked файлы, включая незакоммиченные исправления. Dependencies/caches не подменяют source scan; symlink targets не копируются за пределы дерева. Исключения синтетических исторических samples привязаны к трём точным fingerprints. Для миграционных и аудиторских SHA-256 исключения ограничены одновременно путём и форматом, без разрешения целых каталогов.

Отрицательные секретоподобные samples генерируются во временном каталоге. Реальный scan истории и рабочего дерева проходит; журналы аудита также доступны для сканирования.

### A18 — совместимость проверяется исполнением полного layout

[isolated_workspace.py](../../tools/isolated_workspace.py) копирует core/MCP/PHP и shared fixtures в отдельное дерево. Composer vendor копируется без подключения исходного source; Dart resolution сохраняет соседний core package. Каждый lowest/latest lane выполняет resolution, analyze и tests, затем проверяет неизменность исходных locks.

Пройдены шесть комбинаций: PHP, Dart core, Dart MCP × lowest/latest. [Receipts](../../verification/artifacts/) содержат реальные resolved versions, lock digests, команды, exit codes и diagnostic output. PHP compatibility lanes без PostgreSQL пропускают DB-тесты; отдельно полный PHP-набор выполнен с настоящей БД.

Lowest Dart graph выявил два ограничения: `frontend_server_client >=4.0.0` нужен тестовому инструментарию для AOT snapshot, `quiver >=3.2.1` устраняет ссылку на удалённый Dart API. Добавлены явные constraints без dependency overrides. Все шесть lanes проходят на локальных PHP 8.4.1 / Dart 3.13.5; другие версии hosted matrix локально не воспроизводились.

### A19 — генератор сохраняет внешние managed blocks

[build-agent-docs.sh](../../scripts/build-agent-docs.sh) генерирует собственный префикс и побайтно сохраняет хвост начиная с первого блока swissknifeman/mAInd. Временный файл исключает чтение уже усечённого target. Проверены изменение source, сохранение suffix, повторная генерация и `--check`. Исходные пользовательские CLAUDE.md/AGENTS.md и настройки не переписывались.

### A20 — mutation kill требует assertion failure

[mutation_gate.py](../../tools/mutation_gate.py) работает в отдельной source copy и отказывается мутировать путь, разрешающийся в исходное дерево. Перед мутацией обязателен зелёный baseline. PHP JUnit и Dart JSON reports должны подтверждать структурированный assertion failure; timeout, infrastructure error и stale patch не считаются killed.

Для Dart package boundary добавлен настоящий тест экспортируемой поверхности. Redaction mutant нацелен на общий validator: прежний обход только factory больше не обходил защиту export. **26/26 мутаций обнаружены**, выживших нет, **1614 source files** проверены на неизменность. Отдельные инфраструктурные тесты проверяют классификацию failure.

## Итоговая проверка

| Проверка | Результат и свидетельство |
|---|---|
| PHP с PostgreSQL | 261 тест без skips; [PHP 8.3](evidence/fix-php83-live-final.log), [основной PHP](evidence/fix-php-final.log), [PHPStan](evidence/fix-phpstan-final.log) |
| Dart core | 226 тестов в окончательных compatibility executions; [latest receipt](../../verification/artifacts/P9-compatibility-dart_core-latest.json), [analyze](evidence/fix-dart-analyze-final.log), [format](evidence/fix-dart-format.log) |
| Dart MCP | [7 тестов](evidence/fix-mcp-tests-final.log), [analyze](evidence/fix-mcp-analyze-final.log) |
| Python tools | [132 теста, один ожидаемый live-test skip](evidence/fix-python-coherent.log); live gate выполнен отдельно |
| Полный conformance | [ci_gate --all](evidence/fix-conformance-final.json): frozen reference и candidate reference/PHP/Dart по 213/213; JCS/profile/MCP проходят |
| Adversarial / mutation / storage | [Корпус](evidence/fix-adversarial-final.log), [26/26 mutations](evidence/fix-mutation-final.log), [live PostgreSQL](evidence/fix-storage-final.log) |
| Resource / packages | [Resource aggregate](evidence/fix-resource-final.log), [package consumers](evidence/fix-packages-final.log) |
| Совместимость | Шесть `P9-compatibility-*.json` в [verification/artifacts](../../verification/artifacts/) |
| Bootstrap / policy | [Чистый bootstrap](evidence/fix-clean-bootstrap.log), [workflows](evidence/fix-policy-workflows.log), [supply-chain lock](evidence/fix-policy-lock.log), [CI matrix](evidence/fix-ci-matrix.log) |
| Security | [OSV](evidence/fix-osv-final.log), [Gitleaks](evidence/fix-gitleaks-complete.log) |
| RC / SBOM | [Воспроизводимая сборка](evidence/fix-release-candidate-final.log), [SBOM](evidence/fix-sbom-final.log), [private RC](evidence/fix-private-rc-final.log) |
| Финальная приёмка | [Docs](evidence/fix-docs-coherent.log), [archive consumers и PostgreSQL drill](evidence/fix-consumers-coherent.log), [private acceptance](evidence/fix-private-release-coherent.log), [сверка digests](evidence/fix-verification.json) |

Ранние неуспешные попытки также сохранены: исходные lowest Dart lanes, первая latest PHP/PHPStan попытка и consumer capture без `--allow-dirty`. Они заменены перечисленными успешными проверками. Для consumer capture использован штатный `--allow-dirty`, поскольку изменения ещё не закоммичены; совпадение архивов с пересобранным RC проверялось. Ошибки ранних попыток не удалены и не обозначены успешными.

Временный PostgreSQL 18 использовал отдельную базу `agentools_fix_test`; рабочие и внешние базы не мигрировались. Интеграционная проверка выполнена на PostgreSQL 18, а не на всех потенциально поддерживаемых server versions.

## Применение к существующей установке

1. Остановить старые writers и сохранить backup существующих данных.
2. Применить [expand SQL](../../fixtures/hardening/sql/event-store.timestamp-v2-expand.sql), добавляющий nullable `ts_text`.
3. Заполнить `ts_text` из доверенных **оригинальных event envelopes**, проверить каждый content hash и полную journal chain. Форматирование `TIMESTAMPTZ` не восстанавливает утраченные исходные байты.
4. Установить `ts_text NOT NULL`, затем развернуть обновлённые SDK. Если оригинальных envelopes нет, затронутые streams требуют отдельного восстановления; автоматическое переписывание hashes недопустимо.
5. Проверить старые данные с числами вне safe integer range. Зафиксировать прежние hashes и источник оригинальных данных до смены canonicalizer; большие точные значения новых payload передавать строками.
6. Для возобновляемых сценариев хранить `runId` до выполнения; для legacy journals явно выбирать ревизию `2026-08`. Установить PHP mbstring и обновить зависимости по новым locks.

Подробности API и миграции: [docs/audit-fixes-2026-10.md](../../docs/audit-fixes-2026-10.md). Автоматическая миграция пользовательских данных в этой сессии не выполнялась.

## Границы закрытия

Закрыты подтверждённые дефекты A01–A20 в проверенном рабочем дереве. Более широкие [архитектурные рекомендации](recommendations.md) — incremental journal fold с измерениями, постепенное разделение lint и расширение consumer/recovery сценариев — остаются направлениями развития, а не доказанными устранёнными дефектами.

Private readiness зелёная; `public_authorized=false` и ограничения публичного выпуска сохранены. Hosted CI, внешние публикации и миграция существующих установок не исполнены. Локальные receipts относятся к исправленному рабочему дереву; после новых изменений их следует пересоздать штатными owner commands.
