# Интеграция с main перед commit/push

Владелец явно поручил закоммитить и отправить в `main` всё рабочее дерево, включая
исправления аудита, результаты проверок, миграцию планов и настройки инструментов.
При fetch обнаружено отставание локального дерева на 26 коммитов от
`origin/main` (`3dfca79`). Эти изменения объединены с результатом аудита.

## Разрешение пересечений

- Сохранены принятые в main исправления CredentialGuard, HS256/key hygiene,
  обработки исключений IdempotencyCoordinator, требования PDO ERRMODE_EXCEPTION,
  locked CLI verification, PostgreSQL CI и pinned archive epoch.
- PostgreSQL EventStore сохраняет точный `ts_text` из оригинального envelope.
  Регрессии main для hash chain сохранены и проверяют этот более точный контракт,
  включая исходное смещение и семь знаков дробных секунд. Перенормализация в UTC
  при чтении не выполняется; действуют [инструкции миграции](../../docs/audit-fixes-2026-10.md).
- Существующие вызовы `run(..., IdempotencyKey runKey)` сохраняют прежнее
  вычисление ключей main: length-prefixed UTF-8 tuple → SHA-256 → base64url.
  PHP также принимает произвольный строковый `runId`, а Dart предоставляет
  `runWithId(..., runId: ...)` для JCS-схемы аудита. Повтор запуска должен сохранять
  способ вычисления и тип идентичности. Контекст и обработка отказов компенсации
  из исправления A05 сохранены в обоих путях.
- CI использует полный bootstrap, изолированную БД и обязательность PostgreSQL
  там, где она заявлена. Gates используют `verification/`; JSON-планы и изменения
  пользовательских инструментов включены в итоговое дерево.
- Сканеры сохраняют проверку скачанного/cached бинарника по supply-chain lock.
  Gitleaks отдельно проверяет историю и текущее дерево.
- В аудиторском inventory 1250 SHA-256 сохранены без изменения значений.
  Представление заменено со словаря «путь → digest» на записи `path`/`sha256`,
  чтобы имена fixtures не интерпретировались как названия API-ключей.
  Специальное исключение inventory из Gitleaks удалено. Промежуточный локальный
  snapshot-коммит перед объединением свёрнут в итоговый коммит поверх main;
  история удалённой ветки не переписывается.

## Проверки объединённого дерева

- [PHP](evidence/merge-php.log): 325 тестов с PostgreSQL, без пропусков;
  [PHPStan после согласования API](evidence/merge-phpstan-api.log) проходит.
- [Dart core](evidence/merge-dart.log): 278 тестов;
  [analyze](evidence/merge-dart-analyze.log) и [MCP](evidence/merge-mcp.log) проходят.
- [Полный conformance](evidence/merge-conformance.log),
  [adversarial](evidence/merge-adversarial.log),
  [mutation](evidence/merge-mutation.log),
  [live PostgreSQL](evidence/merge-storage.log),
  [resource](evidence/merge-resource.log) и [packages](evidence/merge-packages.log)
  проходят. Шесть lowest/latest lanes повторно исполнены; команды записаны
  в `evidence/merge-compat-*.json`.
- [Python tools](evidence/merge-python-final.log): 147 тестов (один ожидаемый live skip);
  [дополнительные tools tests](evidence/merge-cli-lock-final.log): 79 тестов. Они проверяют
  объединённую инфраструктуру, включая locked scanners и archive epoch.
- [OSV](evidence/merge-osv.log), [workflow policy](evidence/merge-policy.log),
  [CI matrix](evidence/merge-matrix.log) и
  [сканирование текущих файлов](evidence/merge-worktree-secrets.log) проходят.

Свежие P9/P10 receipts пересозданы после фиксации объединённого продукта
`5b03b66`, чтобы ссылаться на доступный commit. Их команды записаны в
`evidence/push-*.json`; [финальная проверка истории](evidence/push-gitleaks.log) проходит.
[Машинная сводка](evidence/push-verification.json) подтверждает все команды и
согласованность итоговых SHA-256.
Ранние `merge-cli-lock` и `merge-gitleaks` содержат сохранённые неуспешные попытки:
первая зависела от ещё не пересобранного RC, вторая обнаружила два SHA-256
inventory в промежуточном локальном коммите. Их финальные повторы выполняются
после обновления соответствующих входов.

Предшествующий [отчёт об исправлениях](fixes.md) описывает дерево до интеграции;
его исходные журналы не заменены журналами нового прогона. Frozen `2026-08`
не изменён. Публичный выпуск и миграция пользовательских баз не выполняются.
