# Chatom integration: ветка Git в AgenTools

2026-10-31. Код сохранён в общем Git-репозитории AgenTools на ветке
**`integration/chatom-durable-delivery`**. Основной checkout:
`/home/vostrikov/projects/packages/agentools`.

Ветка содержит два последовательных коммита:

1. `e993633ba3f670e15e74e3fa9b82696dcdcc2d0f` — снимок входного незакоммиченного PLAN2 candidate.
   Эта база нужна для существующих platform APIs; она не объявляется нашей новой
   реализацией или завершением этапов PLAN2. Dependency vendor directories исключены.
2. Следующий коммит этой ветки — только Chatom owner integration, документация и
   исключение package vendor directories из Git. Именно этот diff нужно сопоставить
   с активным PLAN2 при интеграции, учитывая развитие его контрактов и migrations.

Проверить и посмотреть изменения можно из основного AgenTools:

```bash
cd /home/vostrikov/projects/packages/agentools
git log -2 --oneline integration/chatom-durable-delivery
git diff --stat e993633ba3f670e15e74e3fa9b82696dcdcc2d0f..integration/chatom-durable-delivery
git diff e993633ba3f670e15e74e3fa9b82696dcdcc2d0f..integration/chatom-durable-delivery -- packages/agentools-postgres
git show integration/chatom-durable-delivery:docs/platform/chatom-delivery.md
```

Прежняя временная папка `agentools-chatom-integration` после сохранения и проверки
кода в Git удаляется по просьбе пользователя. Она больше не является источником
для продолжения. Ветка и коммиты принадлежат основному репозиторию AgenTools.

## Что сохранено в integration commit

Новые owner files:

- `packages/agentools-postgres/migrations/0006_delivery_claims.sql`;
- `packages/agentools-postgres/src/Delivery/DeliverySubmission.php`;
- `packages/agentools-postgres/src/Delivery/DeliveryClaim.php`;
- `packages/agentools-postgres/src/Delivery/DeliveryRepository.php`;
- `packages/agentools-postgres/src/Inbox/IntakeAdmission.php`;
- `packages/agentools-postgres/src/Inbox/SourceAdmission.php`;
- `packages/agentools-postgres/src/Payloads/FileEncryptedPayloadStore.php`;
- `docs/platform/chatom-delivery.md`.

Изменены соответствующие части:

- `packages/agentools-postgres/src/Inbox/IntakeRepository.php` — source admission hook внутри intake transaction;
- `packages/agentools-postgres/composer.json` — optional sodium backend в suggest;
- `packages/agentools-postgres/tests/SchemaTest.php` и
  `tests/Isolation/IsolationRetentionTest.php` — additive migration/upgrade expectations;
- `docs/platform/storage.md`, README и эта памятка — API, границы и Git refs.

Это реальные SQL persistence/receipts, lease/CAS/fencing, source closure/Run
cancellation и encrypted disk storage. Runtime source при сохранении в Git не менялся.

## Следующий шаг

Сопоставить integration commit с актуальной веткой `execution/PLAN2-platform`
в `/home/vostrikov/projects/packages/agentools-plan2`. Сохранить параллельную
работу; интегрировать через Git с разрешением конфликтов по смыслу и проверкой
актуальной нумерации migrations. Весь baseline повторно переносить не требуется.

Затем повторить owner isolation suite и установленный Chatom consumer уже на
интегрированном source. Точки подключения Chatom:
`integrations/agentools-chatom/src/Platform/{PostgresInbox,PostgresAssistantDirectory,PostgresDeliveryOwner,PostgresSourceInvalidator}.php`.

```bash
# Из checkout проверяемой ветки, с установленными dependencies:
python3 packages/agentools-postgres/tests/Isolation/run.py
```

Runner сам создаёт isolated PostgreSQL test DB и проверяет restricted owner/name/allow
marker. Сохранённые результаты исходного candidate: **54 pass / 1411 assertions**;
установленный Chatom consumer PHP8.5/Laravel13.35/Opis2.6: **162 pass / 906 assertions**.
После интеграции нужны новые receipts, а не переименование прежних.

Полная приёмка и команды:
`/home/vostrikov/projects/packages/chatom/tasks/2026-10-31-chatom-ai-transcript-I5.3-continuation.md`.
Исторический evidence сохраняет старые пути, потому что прогоны выполнялись до
переноса в Git. Для нового Composer install следует использовать выбранный checkout
ветки/интегрированного PLAN2; source directories старой папки больше не доступны.

Пока открыты host identity/ACL/payload policy, derived cleanup/retention,
Edufy MessengerPort/R1–R12 и live voice. Перенос в Git и удаление временной папки
не закрывают эти gates или PLAN2 milestones. Push, публикация, release и
PLAN6/P6.4 не входят в этот шаг.

## Готовый запрос для продолжения

> Прочитай `/home/vostrikov/projects/packages/agentools/CHATOM-INTEGRATION-HANDOFF.md`.
> В общем Git AgenTools есть ветка `integration/chatom-durable-delivery`.
> Интегрируй её Chatom integration commit (diff после `e993633ba3f670e15e74e3fa9b82696dcdcc2d0f`)
> в актуальный PLAN2, сохрани параллельную работу и учти текущие owner contracts/migrations.
> Повтори owner isolation suite и установленный Chatom consumer, обнови план и отчёты.
> Без push, публикации, release и PLAN6/P6.4; host/live gates требуют реальных proofs.
