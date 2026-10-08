# CI, зависимости и управление репозиторием

## A13 · P1 · Миграция планов не завершена на границах исполняемых gates

**Код:** [ci_matrix.py:26](../../tools/ci_matrix.py#L26),
[package_gate.py](../../tools/package_gate.py), [resource_gate.py](../../tools/resource_gate.py),
[release_candidate.py](../../tools/release_candidate.py),
[docs_gate.py](../../tools/docs_gate.py).
**Evidence:** [Python tests](evidence/python-tests.log), [CI matrix](evidence/ci-matrix.log),
[package gate](evidence/package-gate.log), [private RC](evidence/private-rc-verify.log).

Текущее дерево переводит планы в новую структуру. Часть прежних JSON artifacts
сохранилась, но множество `root/contracts/*.md` и `findings/*.md` отсутствует.
Tools и workflow продолжают обращаться к ним через hardcoded
`plans/2026.09.19-№1-AGENTOOLS-HARDENING/...`.

`unittest discover -s tests/tools` даёт **65 tests / 17 errors**; `ci_matrix validate`
падает на `compatibility-support-v1.md`; private RC verify — на
`release-candidate-v1.md`. Package/resource/docs commands падают при записи
findings. Диагностический вызов самого package computation отдельно прошёл,
поэтому проблема упаковки здесь не подменяется отсутствием каталога отчёта.

**Исправление:** составить карту всех входов и выходов после миграции. Долгоживущие
исполняемые contracts хранить в версионируемом месте, независимом от названия
исторического плана, либо получать через единый manifest/resolver. Обновить tools,
workflow upload paths, docs и fixtures одним изменением. Создание пустого findings
каталога устранит только часть симптомов. Missing required input должен давать
короткий диагностический RED, а не неперехваченный traceback.

**Приёмка:** все 65 Python tests, matrix validation, package/resource/docs gates,
новые RC/SBOM/aggregate receipts в чистой копии актуального дерева. Никаких
неявных требований к удалённым Markdown-файлам; existing receipts не заменяют
повторную проверку нового snapshot.

## A14 · P1 · PHP 8.3 lane несовместим с текущим lockfile

**Код:** [php/composer.lock](../../php/composer.lock),
[ci.yml:62](../../.github/workflows/ci.yml#L62), [SUPPORT.md](../../SUPPORT.md).
**Evidence:** [Composer на PHP 8.3.35](evidence/php83-lock-install.log).

Lock содержит `symfony/console`, `filesystem`, `finder`, `process`, `string`
версий 8.1.x, требующих PHP >=8.4.1. Workflow объявляет PHP 8.3 и выполняет обычный
`composer install` с dev dependencies. Реальный dry run на установленном PHP
8.3.35 завершается кодом **2** с пятью несовместимыми Symfony packages.

Это доказанный дефект dev/CI tuple. Из него не следует, что production SDK с
`--no-dev` обязательно несовместим с PHP 8.3: проблемные Symfony packages находятся
в require-dev dependency graph.

**Исправление:** поддержать dev lock на минимальном заявленном PHP через
`config.platform.php`, совместимые ограничения и контролируемое обновление lock.
Альтернатива — separate resolution для минимального PHP, но такой lane должен
полноценно тестировать SDK, а не только разрешать зависимости. Не обходить
проверку `--ignore-platform-reqs`.

**Приёмка:** чистый install/test/analyse на PHP 8.3 и 8.4 из описанной матрицы;
no-dev consumer отдельно. Сохранить разрешённые версии в support evidence.

## A15 · P1 · Private RC workflow пропускает bootstrap PHP-зависимостей

**Код:** [private-rc.yml:60](../../.github/workflows/private-rc.yml#L60),
[ci_gate.py layer_three](../../tools/ci_gate.py),
[conformance/phpunit.xml](../../conformance/phpunit.xml).

После checkout и установки runtimes workflow делает только `npm ci`, затем
`python3 tools/ci_gate.py --all`. Ни `composer install --working-dir=php`, ни
install для `conformance` до этого нет. Layer 3 запускает
`conformance/vendor/bin/phpunit`, bootstrap которого также требует vendor.
Vendor directories не входят в Git. У свежего hosted runner этот путь не готов.

Статический вывод не основан на наличии локального vendor: локальный зелёный
`ci_gate --all` был выполнен в копии с уже установленными зависимостями. Hosted job
в рамках аудита не запускался.

**Исправление:** единый bootstrap для bounded, extended и private RC: PHP и
conformance Composer roots, оба Dart packages, MCP npm. Явно ставить необходимые
PHP extensions и pinned test runtime. Добавить отдельный PostgreSQL service и
доказательство изоляции базы там, где заявлено live storage evidence: сейчас
workflow не задают PG service/DSN, а часть PHP tests допускает skip.

**Приёмка:** полный private RC run из checkout без vendor/node_modules/.dart_tool,
с mandatory live storage checks и нулём неожиданных skips. Решение о публикации
остаётся отдельным owner gate.

## A16 · P2 · Уязвимость в dev dependency MCP conformance

**Код:** [package-lock.json:48](../../conformance/mcp/package-lock.json#L48).
**Evidence:** [OSV scan пяти lockfiles](evidence/osv-scan.log).

OSV обнаружил **GHSA-6qxp-vccf-f47h**, CVSS **7.5 / High**, в npm
`@modelcontextprotocol/sdk` **1.30.0**; исправленная ветка начинается с **1.31.0**.
Advisory автора опубликован 30 сентября 2026; проверен 8 октября.
Он описывает отправку OAuth credentials authorization server, выбранному
недоверенным MCP server.
[Первичный advisory](https://github.com/modelcontextprotocol/typescript-sdk/security/advisories/GHSA-6qxp-vccf-f47h).

Пакет здесь транзитивный **dev dependency официального conformance runner**.
Доказательств, что production PHP/Dart SDK либо фактически используемые локальные
сценарии задействуют уязвимый OAuth-client path, аудит не установил. Но security
gate уже возвращает RED; наличие уязвимой версии подтверждено.

**Исправление:** проверить допускающий fixed SDK диапазон conformance package,
обновить dependency graph в изолированной копии. Если требуется новый conformance
pin, одновременно пересмотреть integrity, applicability inventory и receipts.
Не подавлять advisory общим allowlist.

**Приёмка:** повторный OSV scan всех пяти lockfiles без этой уязвимости;
совместимость runner, все применимые официальные сценарии и deterministic lock.

## A17 · P2 · Secret scan имеет воспроизводимые ложные срабатывания и разрыв scope

**Код:** [ci_matrix.py:544](../../tools/ci_matrix.py#L544),
[test vectors:663](../../tools/ci_matrix.py#L663), [.gitleaks.toml](../../.gitleaks.toml).
**Evidence:** [history findings](evidence/gitleaks-findings-redacted.json),
[worktree findings](evidence/gitleaks-worktree-redacted.json).

History scan нашёл **3** попадания в hardcoded AWS-like samples самого negative
test, commit `87dbb55`, строки 664–666. Отдельная проверка текущих versioned и
неигнорируемых файлов нашла те же 3 samples и **3** digest entries в migrated
plan log. Контекст последних проверен: это SHA-256-значения для файлов, а не
доказанные credentials. Настоящая утечка по этим шести попаданиям не установлена.

Штатный `gitleaks-scan` вызывает history-oriented `detect` без отдельного directory
scan. Название CI step «history and worktree» шире выполняемой команды: локальные
uncommitted changes ею не покрываются.

**Исправление:** документированная узкая обработка синтетических samples и
конкретных исторических fingerprints; для новых samples генерация в temp fixture.
Для digest-попаданий — проверка контекста и узкая path+shape политика. Простое
удаление строк из HEAD не уберёт их из history scan. Добавить настоящий отдельный
worktree scan с relative path semantics и редактированием секретных значений.

**Приёмка:** два чистых scans; negative test с новым синтетическим секретом в
fixtures/plans/reports по-прежнему RED. Не исключать целиком эти каталоги и не
переписывать историю без отдельного решения владельца.

## A18 · P2 · Disposable resolution не доказывает заявленную совместимость

**Код:** [PHP resolution:415](../../tools/ci_matrix.py#L415),
[Dart resolution:440](../../tools/ci_matrix.py#L440).
**Evidence:** [MCP downgrade](evidence/dart-mcp-lowest.log).

Dart helper копирует только выбранный package в `tmp/tree`. У MCP companion
остаётся `path: ../agentools`, но соседнего core package нет. Реальный
`disposable-dart_mcp-lowest` заканчивается кодом **66**: package agentools не найден.

Кроме того, PHP helper после composer update и Dart helper после upgrade/downgrade
проверяют только exit code resolver и неизменность исходного lock. Tests, analyzer
и package consumer на новом dependency graph не выполняются. GREEN такого lane
означает разрешимость зависимостей, не совместимость API/поведения.

**Исправление:** staging должен сохранять private-paired layout обоих пакетов;
для каждой lowest/latest комбинации запускать нужные tests/analyzers и consumer
smoke. Сохранять resolved lock и runtime tuple в receipt, не изменяя source locks.

**Приёмка:** все шесть disposable lanes проходят; намеренный API-break dependency
fixture делает lane RED даже при успешном dependency resolution.

## A19 · P2 · У CLAUDE.md конфликтуют владельцы генерации

**Код:** [build-agent-docs.sh:23](../../scripts/build-agent-docs.sh#L23),
[source](../../.ai/guidelines/agent-entrypoint.md), [CLAUDE.md](../../CLAUDE.md).
**Evidence:** [entrypoint check](evidence/agent-entrypoints.log).

В текущем worktree в CLAUDE.md добавлен managed block Swissknifeman, отсутствующий
в source `.ai/guidelines/agent-entrypoint.md`. Repository generator ожидает
побайтового совпадения AGENTS/CLAUDE со своим render и выдаёт stale. При обычном
write он заменит весь CLAUDE.md, удалив дополнительный блок.

Это finding о текущей интеграции генераторов, а не предложение удалить настройки
пользователя. Во время аудита `write` не запускался.

**Исправление:** выбрать одного владельца полного файла либо формально задать
секции и последовательную композицию generated output. Source, generator и
ecosystem exporter должны знать это разделение. Добавить check в CI, если эта
инварианта действительно является обязательной частью DEVELOPMENT.

**Приёмка:** два последовательных полных regeneration cycles ничего не меняют;
оба managed blocks сохраняются; check-mode подтверждает согласованное состояние.

## A20 · P2 · Mutation gate меняет рабочее дерево и считает infrastructure failures kills

**Код:** [run_all:162](../../tools/mutation_gate.py#L162),
[run_patch](../../tools/mutation_gate.py#L101).

`workspace_copy()` существует, но `run_all()` вызывает `run_patch(..., workspace=REPO)`.
Файлы меняются на месте и восстанавливаются в finally. `workspace_unchanged`
сравнивает только один TokenEnvelope.php, хотя каталог затрагивает другие классы
и оба языка. SIGKILL не обязан выполнить finally. Такой gate нельзя считать
безопасным для параллельного package/conformance run или пользовательской правки.

Дополнительно почти любой ненулевой test exit code, если stderr не содержит
несколько жёстко заданных compilation substrings, трактуется как `killed=true`.
Нехватка dependency или ошибка bootstrap может дать ложный mutation success.
Это не утверждение, что все 26 текущих kills ложные.

Исполненная [проба классификатора](evidence/mutation-classifier-probe.log) подставляет
ошибку test infrastructure с exit=2. Gate возвращает `outcome: killed` и
`killed: true`; следовательно, этот недостаток подтверждён отдельно от анализа кода.

**Исправление:** отдельный workspace для каждого mutation run с корректной
dependency resolution; baseline tests до мутаций. Учитывать structured test
results и различать assertion failure, infrastructure, timeout, compile failure.
Сверять весь набор изменяемых файлов, не один fingerprint. Receipt должен
содержать исходный tree digest и результаты baseline.

**Приёмка:** искусственный dependency/bootstrap failure не считается killed;
kill по нужному assertion считается. Прерывание процесса оставляет исходный
checkout неизменным. Параллельная сборка исходного дерева выдаёт прежние digests.
Диагностическая [проба классификатора](reproduce_mutation_gate.py) не меняет source
и использует временный файл и синтетический инфраструктурный отказ.
