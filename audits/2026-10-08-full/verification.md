# Методика и результаты

## Объект проверки

Дата: **2026-10-08**. HEAD: **87dbb5500eaf48c13ef9160e4ee11afdf2942371**.
Аудит относится к текущему рабочему дереву, включая пользовательскую миграцию
планов/agent configuration. На старте: 50 modified, 746 deleted и 421 untracked
entries в porcelain status. Это исходное состояние пользователя.

Обследованы normative revision/fixtures и связь с SDK, production и testkit
boundaries, confirmation/token/authorization, idempotency/fencing, persistence,
hash/recovery, scenarios/expressions/compensation, audit/ops, MCP companion,
package consumers, CI/release/security, Python gates, agent-entrypoint ownership,
README/API/support/operations и предыдущие audits. Изучение реализации было
сосредоточено на границах доверия, сохранении данных, совместимости и composition;
это не построчное формальное доказательство корректности всего репозитория.

Inventory production sources: PHP — 144 файла / 7316 строк; Dart core — 179 /
9126; MCP companion lib — 3 / 260. Tools — 34 Python-файла / 17380 строк.
Эти числа показывают размер областей, а не долю построчно просмотренного кода.
Тесты и conformance использовались как исполняемые доказательства и как предмет
проверки полноты покрытия.

## Среда и сохранность дерева

Основной PHP: 8.4.1, отдельный compatibility probe: PHP 8.3.35.
Dart 3.13.5, Node 24.12.0, Python 3.12.3, Linux.
Для PG tests создан отдельный временный PostgreSQL 18 cluster на loopback со
случайным свободным портом и базой `agentools_audit_test`. Existing databases не
использовались. Результаты PG18 не заменяют проверку на других поддерживаемых
major versions PostgreSQL.

Штатные tests/analyzers использовали имеющееся installed dependency state.
Owner commands, меняющие generated receipts или source во время mutation, запускались
в отдельной временной копии. Package consumers собирались самим gate в disposable
directories. Это не утверждение о прохождении полного bootstrap из чистой
операционной системы; обнаруженные clean-run gaps разобраны в A14/A15.

Перед запуском сохранены SHA-256 2896 tracked paths и исходный Git status.
После проверок **ни один tracked path не изменился относительно стартового
состояния**. Исходные пользовательские изменения не исправлялись и не откатывались.
Новые материалы находятся только в этой папке audits. Generated entrypoint
проверялся через `--check`; source спецификации и frozen predecessor не менялись.

При подготовке disposable среды были исправлены копирование executable symlinks
и наличие consumer test files. Пробный package result во время mutation был
отброшен; окончательная package диагностика выполнена отдельно на неподвижных
исходниках. Заявления о package/conformance results основаны на финальных
корректных прогонах, не на ошибках подготовки аудиторской среды.

## Основные результаты

| Проверка | Результат | Evidence |
|---|---|---|
| Composer validate --strict | PASS | [log](evidence/php-validate.log) |
| PHP tests с отдельной PG базой | 254 tests, 674 assertions, PASS | [log](evidence/php-tests.log) |
| PHPStan | PASS | [log](evidence/php-analyse.log) |
| Dart core analyze/tests | PASS, 217 tests | [analyze](evidence/dart-core-analyse.log), [tests](evidence/dart-core-tests.log) |
| Dart MCP analyze/tests | PASS, 7 tests | [analyze](evidence/dart-mcp-analyse.log), [tests](evidence/dart-mcp-tests.log) |
| Python tests/tools | 65 tests, 17 errors | [log](evidence/python-tests.log) |
| CI layers 1–4 | PASS в подготовленной копии | [log](evidence/ci-all.log) |
| Conformance harness unit tests внутри layer 3 | 34 tests, 347 assertions, PASS | [log](evidence/ci-all.log) |
| Candidate reference/PHP/Dart | 213/213 каждый, ноль fail/warn | [reference](evidence/candidate-reference.json), [PHP](evidence/candidate-php.json), [Dart](evidence/candidate-dart.json) |
| Frozen predecessor reference | 213/213 | [receipt](evidence/predecessor-reference.json) |
| Semantic parity | PASS, восемь семейств | [receipt](evidence/semantic-parity-receipt.json) |
| Official MCP | 7/7 applicable; 7/32 required-client applicable | [receipt](evidence/mcp-official-receipt.json) |
| Manifest/revision/projection freshness | PASS | Соответствующие logs в evidence |
| Adversarial corpus | 29 cases, 0 failed | [receipt](evidence/P8-property-fuzz.json) |
| Mutation catalog | 26 killed, 0 survived, с ограничениями A20 | [receipt](evidence/P8-mutation-baseline.json) |
| PostgreSQL multi-process schedules | GREEN | [receipt](evidence/P8-storage-chaos.json) |
| Resource selftests | 14 tests, PASS | [log](evidence/resource-selftests.log) |
| Resource computation | GREEN | [receipt](evidence/P8-resource-baseline.json) |
| Adversarial aggregate | RED: AV-017, отсутствующий threat-model carrier | [receipt](evidence/P8-adversarial-gate.json) |
| Resource CLI | FAIL при записи findings | [log](evidence/resource-gate.log) |
| Package computation отдельно | GREEN, repeat digests совпали, consumers прошли | [diagnostic receipt](evidence/package-diagnostic-receipt.json) |
| Package CLI | FAIL при записи findings после computation | [log](evidence/package-gate.log) |
| Supply-chain lock/workflow policy | PASS | [lock](evidence/supply-lock.log), [workflow](evidence/workflow-policy.log) |
| CI matrix | FAIL: отсутствующий compatibility contract | [log](evidence/ci-matrix.log) |
| PHP 8.3 install --dry-run | FAIL, exit 2 | [log](evidence/php83-lock-install.log) |
| Disposable MCP lowest resolution | FAIL, exit 66 | [log](evidence/dart-mcp-lowest.log) |
| OSV, пять lockfiles | 1 High advisory в npm dev dependency | [log](evidence/osv-scan.log) |
| Gitleaks history | RED, 3 synthetic test samples | [triage](evidence/gitleaks-triage.json) |
| Gitleaks current nonignored worktree | RED, 3 samples + 3 digest false positives | [redacted findings](evidence/gitleaks-worktree-redacted.json) |
| Agent entrypoint freshness | FAIL | [log](evidence/agent-entrypoints.log) |
| Docs CLI | FAIL при записи findings | [log](evidence/docs-gate.log) |
| Existing RC/SBOM verify | PASS в пределах standalone verifier | [RC](evidence/release-candidate-verify.log), [SBOM](evidence/sbom-verify.log) |
| Private RC aggregate verify | FAIL: отсутствующий release contract | [log](evidence/private-rc-verify.log) |

Всего сохранено **47 command records**: [checks.json](evidence/checks.json).
Не складывать pass counts разных уровней: часть tests выполняется повторно внутри
integrated gate. Exit 0 у диагностических `reproduce*` означает, что сбор
наблюдений завершился; обнаруженные ошибки находятся в полях `actual`, а не в
exit code скрипта.

## Воспроизведение диагностических случаев

Из корня репозитория с установленными development dependencies:

```bash
php audits/2026-10-08-full/reproduce.php
dart --packages=dart/agentools/.dart_tool/package_config.json audits/2026-10-08-full/reproduce.dart
dart --enable-asserts --packages=dart/agentools/.dart_tool/package_config.json audits/2026-10-08-full/reproduce.dart
/usr/bin/php8.4 -n audits/2026-10-08-full/reproduce_no_mbstring.php
python3 audits/2026-10-08-full/reproduce_mutation_gate.py
node -e 'console.log(JSON.stringify(JSON.parse("{\"n\":9007199254740993}")))'
```

PHP DB-пробы намеренно пропускаются без явно заданного `AGENTOOLS_TEST_PG_DSN`,
совпадающего с формой `pgsql:host=127.0.0.1;port=<isolated-port>;dbname=agentools_audit_test`.
Скрипт использует тестовую роль `agentools`, синтетический пароль `agentools-test`
и schema `audit_probes`; запускать только на специально созданной отдельной базе.
Имя само по себе не является достаточным доказательством изоляции — в этом аудите
она обеспечена новым cluster/data directory.

Для повторного package computation использовать отдельную копию репозитория,
сохранив relative private-paired layout и executable symlinks:

```bash
python3 audits/2026-10-08-full/diagnose_package_gate.py /absolute/path/to/disposable/repo
```

`run_checks.py` сохраняет argv/cwd/exit/time и объединённый log. Для generated
receipts сохранены исходные bytes: [receipt-paths.json](evidence/receipt-paths.json)
сопоставляет оригинальное местоположение с именем копии. Пути временного checkout
в receipts являются контекстом запуска, а не обещанием постоянного существования
этого checkout. Gitleaks reports дополнительно сокращены до диагностических
полей; все найденные secret-like значения замаскированы.

## Ограничения и внешние основания

- Не проверялись GitHub branch protection, required checks settings, secrets,
  environments и реальные hosted runs. О них нет выводов по догадке.
- Не выполнена вся runtime/platform matrix: нижняя Dart 3.11, отдельные OS и все
  PostgreSQL major versions требуют CI. PHP 8.3 проверен на конкретном install
  failure, основной набор tests — на PHP 8.4.1.
- Не проводились production load test, внешний pentest, восстановление реального
  backup и действия с реальными credentials. В отчёте нет утверждения о доказанной
  компрометации или исчерпывающем отсутствии уязвимостей.
- Full private RC rebuild не объявляется успешным: обязательные contracts и
  aggregate gates в текущем дереве не готовы. Существующие green receipts имеют
  более узкий смысл.
- Scanner scope — история и current versioned/nonignored files. Игнорируемые
  локальные secrets/configs пользователя намеренно не перепечатывались в отчёт;
  сканирование не доказывает отсутствие секретов вне этого scope.
- Внешние основания проверены в первоисточниках: [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785.html)
  для A03 и [advisory автора MCP SDK](https://github.com/modelcontextprotocol/typescript-sdk/security/advisories/GHSA-6qxp-vccf-f47h)
  для A16. Точная доступность advisory/уязвимости привязана к дате аудита.

Исходники продукта, dependency locks, normative fixtures, пользовательские планы
и generated evidence исходного checkout не исправлялись. Commit, push, tags,
публикация и изменения внешних сервисов не выполнялись.
