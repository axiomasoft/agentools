# Resolution brief к аудиту 2026-09-21

**Статус:** готовое задание на прямую разработку, не lifecycle-план.  
**База проверки:** `main` @ `29482e1c6b2bcd19d63d5f3e2ead0e6ffa07665a`.  
**Исходный аудит:** [`2026-09-21-audit.md`](2026-09-21-audit.md).  
**Цель:** устранить подтверждённые release/evidence-дефекты без создания нового
`plans/*`, без публикации пакетов и без изменения замороженных байтов `2026-08`.

Этот файл можно целиком передать Composer 2.5 или Grok 4.6. Решения ниже уже
приняты; исполнителю не нужно заново проектировать работу или задавать владельцу
вопросы. Единственные действия, для которых всё ещё требуется отдельное разрешение
владельца, — push, создание PR, изменение GitHub settings/secrets/environments и
публичный release. Они в scope не входят.

## 1. Короткий вердикт

Аудит полезен как список кандидатов, но его нельзя исполнять буквально.
Подтверждены реальные дефекты CI, P6 conformance, MCP evidence и benchmark evidence.
Одновременно в аудите есть неверное готовое исправление supply-chain lock,
переоценённые GitHub-governance замечания и смешение двух разных значений
`standalone`/`connected`.

Это не малая правка для одной короткой сессии. Самый большой блок — фактически
незакрытая P6: полный candidate corpus `2026-09` сейчас красный (`143/213`), а
закрытый ранее semantic gate проверяет только error codes. Частичное исправление
одних YAML-файлов создало бы ещё одну ложную зелень.

До выполнения обязательных work packages ниже repository/private RC нельзя считать
готовым. Публичная готовность по-прежнему не заявляется.

## 2. Наблюдаемый baseline

Команды выполнены 2026-09-21 в текущем checkout:

| Проверка | Фактический результат |
|:--|:--|
| `python3 tools/ci_policy.py check-lock` | GREEN, хотя workflow использует SHA от другого repository |
| `python3 tools/ci_policy.py check-workflows` | GREEN; checker не сопоставляет `owner/repo@sha` с lock |
| `python3 tools/ci_matrix.py validate` | GREEN |
| reference corpus `fixtures/2026-09` | **143 pass, 70 fail, 213 total** |
| `php conformance/bin/semantic-parity` | GREEN, но сравнивает только `error_codes` |
| `python3 tools/mcp_applicability.py --check` | GREEN, хотя driver уже поддерживает сценарий, помеченный non-applicable |
| `python3 -m unittest tools.ci_policy tools.ci_matrix` | 15 tests GREEN; негативного теста на repo↔SHA нет |

Семьдесят падений `2026-09` сосредоточены в `audit-*`, `idempotency-*`,
`lifecycle*`, `scenario-*` и `security-*`. Отчёт диагностического прогона создавался
как `out/ci/audit-reference-2026-09.json` и не является коммитимым evidence.

## 3. Исправления к выводам исходного аудита

### 3.1 Supply-chain finding верный, предложенный patch — неверный

SHA `0057852bfaa89a56745cba8c7296529d2fc39830` существует в
`actions/cache`, а в `private-rc.yml` ошибочно вызывается как
`actions/upload-artifact@005785…`. В repository `actions/upload-artifact` такого
commit нет. Поэтому нельзя просто переименовать `id` в lock, сохранив SHA.

Фиксированное решение:

- `actions/upload-artifact` v4 pin:
  `ea165f8d65b6e75b540449e92b4886f43607fa02`;
- удалить неиспользуемую запись `actions/cache`;
- добавить точную pair-проверку workflow `owner/repo@sha` ↔ lock entry;
- добавить `actions/setup-node` v6 pin
  `249970729cb0ef3589644e2896645e5dc5ba9c38`, поскольку MCP contour должен
  устанавливаться в clean checkout.

Проверено через GitHub REST refs/commits репозиториев
[`actions/cache`](https://github.com/actions/cache/commit/0057852bfaa89a56745cba8c7296529d2fc39830),
[`actions/upload-artifact`](https://github.com/actions/upload-artifact/commit/ea165f8d65b6e75b540449e92b4886f43607fa02)
и [`actions/setup-node`](https://github.com/actions/setup-node/commit/249970729cb0ef3589644e2896645e5dc5ba9c38).

### 3.2 `semantic-parity` — реальный acceptance defect, а не только naming

`conformance/bin/semantic-parity` строит normative profile только из
`error_codes`. При этом закрытая P6.2 требовала также case retryability,
states/transitions, envelope versions, bounds, outcomes и capabilities. Текущий
GREEN не удовлетворяет принятому контракту. Простого rename в
`error-code-parity` недостаточно: нужно завершить promised gate.

### 3.3 `standalone` и `connected` не противоречат друг другу

В `ecosystem-setup` слово `standalone` обозначает project profile, а в
`ecosystem-mcp` `connected`/`partial`/`standalone` описывают runtime connectivity
к mAInd. Исправлять состояние подключения на основании этих строк нельзя. Нужна
только явная терминология в управляющем maind template; generated skills этого
репозитория не являются правильным source для ручной правки.

### 3.4 GitHub governance и historical commit не являются release blockers

Отсутствие `CODEOWNERS`, issue forms, `GOVERNANCE.md` и разбиение уже существующего
82k-line commit — не дефекты runtime/protocol evidence частного single-owner
репозитория. Не создавать эти файлы «для комплекта». Добавлять их можно позже при
появлении реального multi-maintainer workflow.

### 3.5 Hooks finding требует более узкого исправления

Нельзя регистрировать все dormant handlers как security boundary. Часть из них
выключена отсутствующими mode-файлами, часть зависит от внешнего harness, а
`safe-dirs` анализирует только прямой `rm`/`rmdir`. Восстановить fail-closed guard
для прямого удаления полезно, но документация обязана называть его guardrail, а не
shell sandbox. Косвенные интерпретаторы (`bash -c`, Python, `find -delete`) не
считать покрытыми.

## 4. Неподлежащие обсуждению ограничения исполнения

1. Работать как direct development; не создавать новый каталог или lifecycle в
   `plans/` и не переписывать историю старого hardening plan.
2. Не менять `spec/revisions/2026-08/**` и `fixtures/2026-08/**`.
3. Не публиковать, не создавать tag, не push/PR без отдельной команды владельца.
4. Не скрывать красные проверки allowlist/baseline-обновлением. Сначала исправить
   поведение, затем регенерировать только owner-generated receipts.
5. Сохранять посторонние изменения worktree. На baseline единственный новый
   пользовательский файл — исходный аудит.
6. PHP/Dart/protocol semantics менять lockstep: normative source → fixtures →
   reference → PHP → Dart → shared conformance.
7. Каждый package ниже завершать focused tests до перехода к следующему. Финальный
   GREEN допустим только после раздела 7 целиком.

## 5. Обязательные work packages

### WP1 — сделать CI и supply-chain проверки честными

**Файлы:**

- `.ci/supply-chain-lock.json`
- `tools/ci_policy.py`
- `tools/ci_matrix.py`
- `.github/workflows/ci.yml`
- `.github/workflows/extended.yml`
- `.github/workflows/private-rc.yml`
- `.github/dependabot.yml`
- `.osv-scanner.toml` (только комментарий/перечень, если остаётся declarative)

**Изменения:**

1. Заменить ошибочную запись `actions/cache` на `actions/upload-artifact` с pin
   `ea165f8d65b6e75b540449e92b4886f43607fa02`, правильными `source` и MIT license.
2. В `private-rc.yml` использовать тот же upload-artifact pin.
3. Добавить lock entry `actions/setup-node` v6 с pin
   `249970729cb0ef3589644e2896645e5dc5ba9c38`, source
   `https://github.com/actions/setup-node`, MIT.
4. `ci_policy.py` должен:
   - отклонять duplicate action IDs и duplicate `(repo, sha)` rows;
   - требовать `source == https://github.com/<id>`;
   - для каждого внешнего `uses:` извлекать точные repo и SHA;
   - отклонять отсутствующий lock entry, repo mismatch и SHA mismatch;
   - иметь негативные tests: правильный SHA от неправильного repo, неизвестный
     action, duplicate id, неверный source, stale SHA.
5. Manual RC разрешить только при `github.ref == 'refs/heads/main'`. Публичные или
   release tags сейчас не разрешать: owner release gate не открыт. Push trigger уже
   ограничен `main`.
6. Удалить job `hosted-attest` с `if: false`. Состояние
   `hosted_attestation: unavailable-plan` оставить в capability evidence; не
   имитировать условную готовность dead YAML-кодом.
7. Во всех jobs, которые косвенно запускают `conformance/mcp/bin/run` через
   `ci_gate.py`, до gate выполнить:
   - pinned `actions/setup-node`;
   - `npm ci --ignore-scripts --prefix conformance/mcp`;
   - проверку локального binary version.
   Это требуется в bounded CI, extended и private RC.
8. Bounded job запускать и на `pull_request`, и на push в `main`. Не вводить
   path-filter в первом исправлении: protocol-first repo слишком связан, а простая
   обязательная проверка надёжнее условного skip.
9. Расширить OSV lock roster до пяти файлов:
   - `php/composer.lock`;
   - `conformance/composer.lock`;
   - `dart/agentools/pubspec.lock`;
   - `dart/agentools_mcp_dart/pubspec.lock`;
   - `conformance/mcp/package-lock.json`.
   OSV официально поддерживает Composer, Dart и npm lockfiles:
   [supported artifacts](https://google.github.io/osv-scanner/supported-languages-and-lockfiles/).
10. Добавить Dependabot entries `pub` для обоих Dart package roots и `npm` для
    `/conformance/mcp`. Сохранить weekly cadence и существующие dependency labels;
    использовать отдельные directory entries без overlap. Формат directory и npm
    подтверждён [GitHub Dependabot docs](https://docs.github.com/en/code-security/concepts/supply-chain-security/about-the-dependabot-yml-file).

**Focused acceptance:**

```bash
python3 tools/ci_policy.py test
python3 tools/ci_policy.py check-lock
python3 tools/ci_policy.py check-workflows
python3 tools/ci_matrix.py test
python3 tools/ci_matrix.py validate
npm ci --ignore-scripts --prefix conformance/mcp
conformance/mcp/node_modules/.bin/conformance --version
```

Негативный fixture `actions/upload-artifact@005785…` обязан стать RED.

### WP2 — завершить promised semantic profile

**Файлы:**

- `tools/semantic_manifest.py`
- `spec/revisions/2026-09/semantic-manifest.json` (только генератором)
- `php/conformance/semantic_profile.php`
- `dart/agentools/conformance/semantic_profile.dart`
- `conformance/src/Conformance/SemanticParity.php`
- `conformance/bin/semantic-parity`
- `conformance/tests/SemanticParityTest.php`
- профильные PHP/Dart tests
- `conformance/reports/semantic-parity.json` (только gate-командой)

**Изменения:**

1. Сохранить имя `semantic-parity`, но довести `semantic-profile/v1` до принятого
   P6.2 содержания. Обязательные top-level families:
   - `error_codes`;
   - `case_retryability`;
   - `states`;
   - `transitions`;
   - `envelope_versions`;
   - `bounds`;
   - `outcomes`;
   - `capabilities`.
2. Normative manifest извлекать детерминированно из revision Markdown/schema/case
   manifests; SDK profiles строить из production constants/types. Не копировать
   normative JSON целиком в exporter и не генерировать SDK source.
3. Comparator должен делать exact-set сравнение normative↔PHP,
   normative↔Dart и PHP↔Dart для каждой family. Для keyed rows выводить
   `missing`, `extra`, `mismatch` с family и key; порядок отчёта стабильный.
4. `case_retryability` нельзя сворачивать к default error retryability.
5. Пустая required family является RED, кроме capability family, где пустота
   допустима только при явном normative empty set и таком же SDK set.
6. Добавить red mutants минимум для: missing code, wrong retryability, removed
   transition, changed bound, wrong outcome, unsupported capability и extra SDK row.

**Focused acceptance:**

```bash
python3 tools/semantic_manifest.py --check
php conformance/vendor/bin/phpunit --configuration conformance/phpunit.xml --filter SemanticParity
php conformance/bin/semantic-parity
composer test --working-dir=php
composer analyse --working-dir=php
(cd dart/agentools && dart analyze --fatal-infos && dart test)
```

Report обязан перечислять все восемь families; GREEN только по error codes больше
не принимается.

### WP3 — реально перевести conformance на candidate `2026-09`

**Файлы:**

- `conformance/adapters/reference/ReferenceEngine.php`
- `conformance/adapters/reference/CompiledReference.php`
- `php/conformance/SdkConformanceEngine.php`
- `dart/agentools/conformance/sdk_conformance_engine.dart`
- связанные production classes/tests в PHP и Dart, если fixture выявляет реальный
  runtime defect
- `conformance/bin/p6-gate`
- `tools/ci_gate.py`
- `conformance/reports/p6-integrated.json` и SDK/reference reports через их owners
- `spec/revisions/2026-09/revision.lock.json` только штатным генератором

**Изменения:**

1. Сначала устранить все 70 reference failures против `fixtures/2026-09`. Не
   ослаблять matcher и не менять expected fixture только ради GREEN. Для каждого
   failure определить owner: reference omission, harness observation gap или
   противоречие normative fixture; последнее исправлять от normative source вниз.
2. Затем прогнать тот же полный 213-case corpus через PHP и Dart adapters и
   исправить language-specific gaps. Reference, PHP и Dart должны объявлять
   `spec_rev=2026-09` и давать одинаковую semantic outcome.
3. В `p6-gate` удалить `successor corpus probe (non-blocking)`. Полные reference,
   PHP и Dart successor reports сделать обязательными children integrated receipt.
   Любой non-zero exit, отсутствующий report или digest mismatch — exit 1.
4. Устранить потенциальный pipe deadlock: не читать stdout и stderr
   последовательно. Передать их процессу-наследнику либо собирать одновременно;
   вывод должен остаться видимым в CI.
5. Удалить устаревший комментарий `probe-only` из `tools/ci_gate.py`. Layer 4
   должен быть GREEN только после полного candidate corpus.
6. Сохранить отдельный regression run `2026-08` и byte-diff guard; не выдавать его
   за candidate proof.

**Обязательные прямые прогоны:**

```bash
php conformance/bin/run \
  --adapter 'php conformance/adapters/reference/adapter.php' \
  --vectors fixtures/2026-09 --spec-rev 2026-09 \
  --report out/ci/reference-2026-09.json

php conformance/bin/run \
  --adapter 'php php/conformance/adapter.php' \
  --vectors fixtures/2026-09 --spec-rev 2026-09 \
  --report out/ci/php-2026-09.json

php conformance/bin/run \
  --adapter 'dart run dart/agentools/conformance/adapter.dart' \
  --vectors fixtures/2026-09 --spec-rev 2026-09 \
  --report out/ci/dart-2026-09.json

python3 tools/ci_gate.py --all
```

Acceptance: каждый полный report `213 pass, 0 fail`; integrated receipt содержит
digest каждого из трёх reports. Если число cases изменилось из-за легитимной
normative правки, exact-set count должен совпадать между всеми тремя adapters и
fixture inventory.

### WP4 — сделать MCP evidence воспроизводимым и честным

**Файлы:**

- `conformance/mcp/bin/run`
- `conformance/mcp/applicability.schema.json`
- `conformance/mcp/applicability-2026-07-28.json` (генератором)
- `tools/mcp_applicability.py`
- `dart/agentools_mcp_dart/tool/conformance_client.dart`
- `conformance/mcp/README.md`
- tests для generator/runner/driver

**Изменения:**

1. Runner обязан использовать только
   `conformance/mcp/node_modules/.bin/conformance`; если binary отсутствует —
   понятный exit 2 с инструкцией `npm ci --ignore-scripts`. Удалить `npm exec`,
   потому что npm официально допускает remote/cache install fallback для
   отсутствующего local package:
   [npm exec](https://docs.npmjs.com/cli/npm-exec/).
2. Развести оси в schema v2:
   - `applicability`: `applicable | non_applicable | blocked_dependency`;
   - `execution_status`: `pending | passed | failed | not_run`.
   Runner не должен переписывать `applicability` значением `passed/failed`.
3. Required client scenario считать applicable, если он наблюдает поведение уже
   существующих public `connect`, `discoverTools`, `invokeTool` или clarification
   resume boundaries, даже когда конкретная обязанность реализована нижележащим
   `mcp_dart`.
4. Зафиксировать applicable set для текущего public contour:
   - `tools_call`;
   - `request-metadata`;
   - `sep-2322-client-request-state`;
   - `http-standard-headers`;
   - `http-custom-headers`;
   - `http-invalid-tool-headers`;
   - `json-schema-ref-no-deref`.
5. OAuth scenarios оставить non-applicable: wrapper не предоставляет auth/OAuth
   configuration surface. Server-role scenarios оставить non-applicable:
   repository не поставляет MCP server. `added-after-release`/unscored scenarios
   не смешивать с frozen required denominator.
6. Дополнить driver явными case handlers. `sep-2322-client-request-state` уже
   реализован и потому не может оставаться non-applicable. Для `$ref` достаточно
   `connect → discoverTools → close`; driver не должен сам fetch network ref.
   HTTP/custom-header cases должны вызвать advertised tools с данными официального
   scenario, а не подменять проверку локальным mock.
7. Summary обязан печатать и сохранять `passed/applicable/frozen-required-client`,
   например `7/7 applicable; 7/32 frozen required client scenarios applicable`.
   Не называть это полной MCP certification.
8. Добавить negative tests: local binary отсутствует; applicable receipt
   отсутствует; failed result; оси смешаны; driver scenario отсутствует;
   non-applicable row без конкретной boundary-причины.

**Focused acceptance:**

```bash
npm ci --ignore-scripts --prefix conformance/mcp
python3 tools/mcp_applicability.py --check
conformance/mcp/bin/run
(cd dart/agentools_mcp_dart && dart analyze --fatal-infos && dart test)
```

Clean-checkout validation выполнить в disposable copy без `node_modules`: сначала
команда должна контролируемо упасть, после `npm ci --ignore-scripts` — пройти,
используя только local binary.

### WP5 — починить benchmark/evidence, а не только labels

**Файлы:**

- `benchmarks/php/run_workload.php`
- `dart/agentools/benchmark/run_workload.dart`
- `tools/resource_gate.py`
- focused tests/fixtures resource gate
- generated P8 resource receipt только после GREEN

**Изменения:**

1. В обоих SDK `scenario_steps` использовать `spec_rev: 2026-09`.
2. В обоих SDK `event_fold(N)` должен реально:
   - создать N deterministic `UncommittedEvent` с уникальными IDs;
   - append batch/chunks в `InMemoryEventStore` в пределах `MAX_BATCH`;
   - загрузить весь stream страницами в пределах `MAX_PAGE`;
   - fold/reduce загруженные `ActionEvent` в детерминированное состояние;
   - вернуть `appended_count`, `loaded_count`, `folded_count`, final seq и digest.
   Канонизация объекта `{events: N}` не считается fold.
3. Growth series для event fold: `1, 10, 100, 1_000, 10_000`. Отдельно хранить
   append/load/fold timing, чтобы один этап не маскировал другой.
4. Ввести одинаковую PHP/Dart outcome taxonomy:
   `reject.syntax`, `reject.claim`, `reject.signature`, `reject.limit`,
   `reject.unsupported`, `error.internal`. Не классифицировать любой `Throwable`
   как ожидаемый user rejection.
5. Не возвращать exception message/secret material в committed receipts;
   internal failure должен быть RED для gate и иметь безопасный diagnostic class.
6. Добавить unit tests, доказывающие, что requested event count действительно
   создаёт/загружает/fold-ит N событий и что injected unexpected exception даёт
   `error.internal`.

**Focused acceptance:**

```bash
python3 tools/resource_gate.py test
python3 tools/resource_gate.py run
composer test --working-dir=php
(cd dart/agentools && dart analyze --fatal-infos && dart test)
```

### WP6 — сузить security blind spots и привести agent-facing docs в порядок

**Файлы:**

- `.gitleaks.toml`
- `tools/ci_matrix.py` tests
- `.cursor/hooks.json`
- `.cursor/hooks/lifecycle.py` и его tests, если они добавляются
- `.mcp.json.example`
- новый `.mcp.internal.example` при необходимости
- `.ai/guidelines/agent-entrypoint.md`
- `AGENTS.md`, `CLAUDE.md` только через `bash scripts/build-agent-docs.sh`
- новый `DEVELOPMENT.md`
- `SECURITY.md`

**Изменения:**

1. Удалить global directory allowlist для `fixtures/`, `plans/` и всего
   `conformance/reports/`. Gitleaks paths являются regex и исключают весь matching
   path; это подтверждается upstream implementation:
   [allowlist source](https://github.com/gitleaks/gitleaks/blob/master/config/allowlist.go).
2. Разрешать только конкретные generated receipt paths и конкретные fake-secret
   forms. Для path+content исключений использовать отдельные allowlists с AND
   semantics, доступные в pinned Gitleaks; не разрешать каталог одним regex.
3. Добавить adversarial test: реалистичный secret под каждым из трёх прежних
   каталогов обязан находиться; известный fake fixture остаётся GREEN.
4. В `.cursor/hooks.json` восстановить только
   `security.safe-dirs` на `beforeShellExecution` с `failClosed: true`. Остальные
   lifecycle handlers не регистрировать автоматически без versioned mode config и
   self-contained policy runtime. Документировать, что это direct-rm guardrail,
   не security sandbox.
5. Исправить memory scope вычисление так, чтобы при включённой памяти scope брался
   из versioned project metadata (`agentools`), а не basename checkout. Worktree
   `agentools-audit` должен получать `project:agentools`.
6. `.mcp.json.example` оставить переносимым: `maind` + `codegraph` с placeholder
   project path. Локальный Perplexity path перенести в `.mcp.internal.example` с
   placeholder или убрать. Ни одного `<HOME>/projects/...` в portable example.
7. Не раздувать root entrypoint десятью разделами. Создать `DEVELOPMENT.md` с:
   normative hierarchy, change-impact matrix, точными focused/full commands,
   generated-file policy, definition of done и release prohibitions. В source
   entrypoint добавить одну короткую ссылку на него и пересобрать `AGENTS.md` /
   `CLAUDE.md`.
8. В `SECURITY.md` дать конкретный private GitHub Security Advisory route
   `https://github.com/axiomasoft/agentools/security/advisories/new`, не обещая
   публичный SLA или включённый public private-vulnerability-reporting feature.

**Не делать в этом package:** не править вручную generated
`.agents/skills/ecosystem-*.md`. Canonical naming (`codegraph` server, Serena
engine), project profile и connectivity terminology должны быть исправлены в
управляющих maind templates и затем синхронизированы отдельной разрешённой
cross-project задачей. Это не блокирует protocol/private RC.

**Focused acceptance:**

```bash
python3 tools/ci_matrix.py test
python3 tools/ci_matrix.py gitleaks-scan
python3 -m py_compile .cursor/hooks/lifecycle.py
bash scripts/build-agent-docs.sh --check
rg -n '<HOME>|/home/|projects/packages/perplexity' .mcp.json.example
git diff --exit-code -- AGENTS.md CLAUDE.md
```

Последняя `rg` должна вернуть пустой результат. Если build script не принимает
`--check`, сначала запустить обычную генерацию, затем повторить её и доказать
нулевой diff.

## 6. Принятые deferrals — не маскировать под TODO текущего release

Эти пункты исходного аудита не входят в обязательное закрытие:

- генерация всех schemas/DTO из единой semantic model — отдельная архитектурная
  миграция; текущая защита должна быть structural-diff/parity gate;
- `CODEOWNERS`, issue forms, `GOVERNANCE.md`, blanket `ARCHITECTURE.md` — только
  когда появится соответствующий team workflow;
- переписывание исторического большого commit — запрещено; следующие изменения
  просто делать тематическими;
- hosted attestation — capability `unavailable-plan`, пока владелец не разрешит
  GitHub plan/settings change;
- изменение maind/swissknifeman canonical templates — cross-project scope, не
  скрытая часть этой repository task.

Каждый deferral должен оставаться честно описанным как non-guarantee. Он не должен
делать обязательные gates зелёными через `skip`, `if: false` или broad allowlist.

## 7. Финальная приёмка

Перед заявлением готовности выполнить всё ниже из clean dependency state. Если
PostgreSQL tests мутируют БД, использовать только доказанную isolated test DB по
правилам репозитория.

```bash
# Generated docs and immutable predecessor
bash scripts/build-agent-docs.sh
git diff --exit-code -- spec/revisions/2026-08 fixtures/2026-08

# Supply chain / policy
python3 tools/ci_policy.py test
python3 tools/ci_policy.py check-lock
python3 tools/ci_policy.py check-workflows
python3 tools/ci_matrix.py test
python3 tools/ci_matrix.py validate
python3 tools/ci_matrix.py osv-scan
python3 tools/ci_matrix.py gitleaks-scan

# Dependencies
composer install --working-dir=php --no-interaction --prefer-dist
composer install --working-dir=conformance --no-interaction --prefer-dist
(cd dart/agentools && dart pub get)
(cd dart/agentools_mcp_dart && dart pub get)
npm ci --ignore-scripts --prefix conformance/mcp

# Language packages
composer validate --strict --working-dir=php
composer test --working-dir=php
composer analyse --working-dir=php
(cd dart/agentools && dart analyze --fatal-infos && dart test)
(cd dart/agentools_mcp_dart && dart analyze --fatal-infos && dart test)

# Protocol/conformance/evidence
python3 tools/semantic_manifest.py --check
php conformance/bin/semantic-parity
python3 tools/mcp_applicability.py --check
conformance/mcp/bin/run
python3 tools/ci_gate.py --all
python3 tools/adversarial_corpus.py run
python3 tools/mutation_gate.py run
python3 tools/resource_gate.py run

# Private RC, без публикации
python3 tools/release_candidate.py run
python3 tools/sbom_gate.py run
python3 tools/private_rc_gate.py run
```

Definition of done:

- все команды GREEN;
- reference/PHP/Dart candidate reports имеют одинаковый exact case set и ноль
  failures;
- semantic report покрывает все восемь families;
- MCP summary сохраняет обе независимые оси и точный denominator;
- CI не может запустить private RC с feature branch;
- ни один workflow action не проходит policy с SHA от другого repository;
- benchmark event fold доказывает реальные append/load/fold counts;
- нет изменений frozen `2026-08`;
- итоговый diff не содержит public release/tag/registry действий.

## 8. Формат отчёта исполнителя

Не писать новый план. В конце вернуть только:

1. какие work packages закрыты;
2. какие файлы/observable guarantees изменены;
3. точные выполненные проверки и их результаты;
4. оставшиеся ограничения только из раздела 6;
5. подтверждение, что публикации, push и GitHub settings mutations не было.

