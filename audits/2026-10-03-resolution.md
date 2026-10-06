# Resolution к аудиту 2026-10-03

**Ветка:** `audit/fixes-1` (от `main` @ `87dbb55`). В `main` ничего не вливалось, история не переписывалась.
**Исходный аудит:** [`2026-10-03-audit.md`](2026-10-03-audit.md); номера строк и доказательства — в его части III.
**Правила работы:** `spec/` нормативна. Если исправление меняет семантику спецификации, оно оформлено здесь как предложение. Каждое поведенческое исправление сопровождается тестом. PHPStan max чистый, без baseline, ignore и подавлений.

## 1. Что исправлено

| Коммит | Изменение | ID | Тесты |
|:--|:--|:--|:--|
| `ac4d56f` | `CredentialGuard` (PHP/Dart) fail-closed: обязательные аудитория и набор доверенных эмитентов; отказ для `argument`/`model_output`/`tool_result`; путаница ролей токенов; рекурсивный обход аргументов с лимитом глубины 64 и RFC 6901-указателем; причины и `security_rule` совпадают с векторами `security-*` | SEC-1, CONF-2 | `CredentialGuardTest`, `CredentialGuardVectorTest` (векторы 2026-08 и 2026-09), Dart `credential_guard_test` |
| `9906fd8` | `IdempotencyCoordinator` (PHP/Dart): брошенный инструментом `ToolExecutionException` сохраняется как ответ ключа (IDE-12/13/23); прочие исключения по-прежнему удерживают lease (HRD-17/18) | SEC-3 (часть) | повторный вызов отдаёт сохранённый ответ; неожиданное исключение даёт `idempotency_in_flight` |
| `1ceb622` | `#[\SensitiveParameter]`, редактирование секрета в отладочном выводе, порог HS256 в 32 байта и для `HmacTokenSigner` | SEC-5 | `SigningSecretHygieneTest`, Dart `hmac_token_signer_test` |
| `eaeabe1` | Канонический UTC-`ts` в event store Postgres/SQL: хранение, `prev_hash` и чтение совпадают | DB-2 п.4 | PG-тест с `Asia/Kathmandu` (до исправления: «broken chain at seq 2»), Dart scripted |
| `ef762ad` | Сканеры из lock проверяются при каждом использовании, распаковывается только нужный файл с `filter="data"`, канарейки gitleaks собираются во время выполнения, `.gitleaksignore` для трёх исторических отпечатков | CI-5 | `tests/tools/test_locked_cli.py` (8) |
| `76740c0` | Переэмиссия `fixtures/hardening/adversarial/manifest.v1.json` | CI-7 | `validate_adversarial_contract.py` |
| `1bbd554` | `RegistryToolPort`: типизированный обработчик, брошенная ошибка инструмента возвращается как результат | SEC-2 (часть) | `RegistryToolPortTest` (+2) |
| `ef80c2c` | `ScenarioRunner` (PHP/Dart): ключи шагов и компенсаций выводятся из ключа запуска и проходят IDE-4 | DES-1 | общие эталонные ключи PHP=Dart, прогон через `IdempotencyCoordinator` |
| `a380ea9` | CI-job `postgres` (образ закреплён по digest, `AGENTOOLS_TEST_PG_REQUIRED=1` превращает skip в ошибку); ci_policy SC-CONTAINER-002 | CI-6 | `test_ci_policy.py` (+2), локально с флагом |
| `b0809b8` | `.gitignore` больше не игнорирует закоммиченные lock-файлы; `php/.cursor/state/*.jsonl` снят с индекса | ST-5, ST-3 (часть) | — |
| `bfa6815` | `docs/migrations.md` указывает реальный DDL | DB-1 (документация) | `docs_gate` (лексикон) |
| `8c11288` | Dependabot: одна группа для GitHub Actions (одна синхронизация lock) | CI-2 (часть) | `ci_policy check-workflows` |
| `bfacd29` | `HarnessToolPort`: вызов замыкания вместо несуществующего метода | TYP-1 | `tests/Conformance/HarnessToolPortTest` |
| `5620fa0` | `PdoPostgres*`: отказ при соединении не в `ERRMODE_EXCEPTION` вместо изменения чужого PDO | DB-2 п.3 | `PdoErrorModeRequirementTest` |
| `403103a` | Якоря mutation-catalog для изменённого `IdempotencyCoordinator` | CI-7 | `mutation_gate.py run` 26/26 |
| `c3d5f79` | Каталог мутаций без абсолютных машинных путей | CI-7 | `mutation_gate.py test` |

**Ломающие изменения** (`CHANGELOG.md`, Unreleased): конструктор `CredentialGuard`; минимум 32 байта для `HmacTokenSigner`; `ScenarioRunner::run(…, IdempotencyKey $runKey)`; адаптеры `PdoPostgres*` требуют `ERRMODE_EXCEPTION` (это значение PDO по умолчанию с PHP 8.0).

### Ревью PR #10, 2026-10-06

При независимой проверке исправлены ещё четыре проблемы:

- PHP-бенчмарк не передавал новый ключ запуска в `ScenarioRunner`, поэтому
  `scenario_steps` возвращал `error.internal` / `ArgumentCountError`. Вызов
  обновлён; оба SDK проверяются на допустимых 1/64 шагах и отказе на 65-м.
- Команда `gitleaks-scan` проверяла только историю Git. Добавлена проверка
  текущих tracked-файлов и новых non-ignored файлов, с тестами на секрет в
  незакоммиченном изменении и в новом файле. Существующие точечные allowlist
  сохранены; зависимости и build state в snapshot не входят.
- `tarfile` удаляет начальный `/` при безопасной распаковке, но распаковщик
  возвращал путь, построенный из исходного абсолютного имени. Воспроизведено
  получение постороннего файла вместо проверенного бинарника; абсолютные
  имена теперь отклоняются, тест проверяет также неизменность постороннего файла.
- Workflow `private-rc` запускал PHP-проверки без установки Composer-зависимостей.
  Добавлены установка всех locked-зависимостей, изолированный PostgreSQL с
  обязательными DB-тестами и переэмиссия CI map после ресурсных проверок.

Локально прошли PHP 8.3 и 8.4 (по 318 тестов с отдельным PostgreSQL 17.11), PHPStan max,
Dart core (269 тестов), Dart MCP (7 тестов), Python tools (77 тестов),
`ci_policy`, `ci_matrix`, OSV, Gitleaks history/worktree, все четыре слоя
`ci_gate`, semantic parity, официальный MCP runner (7/7 применимых сценариев),
adversarial corpus (29 случаев), mutation gate (26/26), resource gate,
release candidate, SBOM и private RC aggregate. Замороженные `2026-08`
spec/fixtures не изменены. Новые проверки resource gate и locked CLI прошли.

GitHub API подтвердил причину отказа Actions: job не запущен из-за платежей
или лимита расходов аккаунта. Это локальная проверка, а не зелёный hosted CI.
Ограничение CONF-1 (ответы SDK conformance из каталога) сохраняется; изменения
SDK дополнительно проверены прямыми unit- и credential-vector тестами.

## 2. Тесты: до и после (локально)

| Набор | `main` @ `87dbb55` | `audit/fixes-1` |
|:--|:--|:--|
| PHPUnit (с PostgreSQL 17) | 254 OK | 318 OK |
| PHPStan max | OK | OK |
| Dart core `analyze --fatal-infos` / `test` | OK / 217 | OK / 269 |
| Dart MCP `analyze` / `test` | OK / 7 | OK / 7 |
| Python `tests/tools` | 65 OK | 75 OK |
| `ci_gate` layers 1–4, `semantic-parity`, `ci_policy`, `ci_matrix validate`, OSV | OK | OK |
| `validate_adversarial_contract.py` | **FAIL** (manifest не канонический) | OK |
| `ci_matrix gitleaks-scan` / `gitleaks git` по истории | **FAIL** (3 ложных срабатывания) | OK (224 коммита, 0) |
| `package_surface.py validate-contract` | OK | OK |
| `mutation_gate.py run` | 26/26 killed | 26/26 killed |
| Infection (minMsi 70) | 75,03 % (1854 мутанта) | 75,85 % (1909 мутантов) |

Заранее существовавших падений, которые остались бы красными, нет.

## 3. Что не проверено

- **GitHub Actions не запускались:** CI заблокирован биллингом (аудит, CI-1). Новый job `postgres` проверен только локально, с тем же образом, переменными и командой. Работу `services:` и health-check на раннере GitHub никто не видел.
- Dart-адаптеры SQL проверены на scripted executor, а не на реальном PostgreSQL (как и раньше в репозитории).
- Conformance не исполняет код SDK (CONF-1), поэтому его зелёный статус не подтверждает изменения в SDK. Их подтверждают прямые unit- и векторные тесты.
- Release-candidate слой (`release_candidate.py`, `sbom_gate.py`, `private_rc_gate.py`) не запускался: он требует публикационных артефактов.

## 4. Предложения (сознательно не реализованы)

1. **CONF-1:** SDK-адаптеры должны исполнять движок, а не каталог ответов; в отчёт раннера добавить `catalog_hits`, обязательный `0` для SDK.
2. **SEC-2:** default-deny в `RegistryToolPort` и коды ошибок валидации `inputSchema` требуют решения на уровне спецификации (TLP-16 и регистрация кодов по IDE-30/31).
3. **SEC-3:** продление lease во время долгой операции через ревизию HRD.
4. **SEC-4:** runtime-контроль `untrusted_external` (L3); реализации в коде нет.
5. **DB-2:** DDL v2 (индексы `expires_at`, SARGable cleanup, append-only через `REVOKE` и триггер, RLS). v1 закреплён дайджестами доказательств P8.4.
6. **DB-1:** поставлять DDL в архивах пакетов.
7. **TYP-1:** `php/conformance` под PHPStan (79 ошибок на max) вместе с CONF-1.
8. **API-1:** проверка `source_digest` в `package_surface.py validate-contract`.
9. **ST-1/ST-2/NM-8:** вынос `plans/` и сгенерированных артефактов, ASCII-пути. Обычными коммитами, без переписывания истории.
10. **CI-2:** синхронизация lock для открытых PR Dependabot; единая версия PHPUnit.

## 5. Ручные шаги владельца

1. Снять ограничение биллинга Actions и перезапустить `ci` на этой ветке.
2. Ruleset на `main`: PR обязателен, обязательные проверки (включая `postgres adapters (php 8.4)`), запрет force-push и удаления.
3. Включить secret scanning и push protection.
4. Создать аннотированный тег `spec-2026-08` на коммите заморозки ревизии.
5. После слияния группировки Dependabot пересоздать PR по actions одной группой.
