# Chatom integration: ветка Git в AgenTools

## Полное объединение в базовый AgenTools — 2026-11-01

Вся работа по фазам `P1`–`P7` (`P1.1`–`P7.8`) из `plans/2026.10.30-№1-AGENTOOLS-PLATFORM` (бывший worktree `agentools-plan2`, ветка `execution/PLAN2-platform`) и все доработки Chatom (`6fe2a25` + `e05ac9d` на `integration/chatom-durable-delivery`, включая `DerivedPayloadRepository`, `FileEncryptedPayloadStore` с exact-handle staging cleanup и `0012_derived_payloads.sql`) сведены, выверены и объединены в базовый репозиторий `/home/vostrikov/projects/packages/agentools`. Отдельная копия `/home/vostrikov/projects/packages/agentools-plan2` удалена.

Актуальная последовательность миграций `packages/agentools-postgres/migrations/`:
- `0001_intake.sql` .. `0005_delivery_resolution.sql`
- `0006_delivery_claims.sql` (Chatom durable delivery claims)
- `0007_action_submissions.sql` (P5.1 action submissions)
- `0008_payload_holds.sql` (Chatom retention holds/release receipts)
- `0009_confirmation.sql` (P5.2 confirmation challenges)
- `0010_effect_recovery.sql` (P5.3 effect recovery & reconciliation)
- `0011_operator_operations.sql` (P6.2/P7.7 operator operations)
- `0012_derived_payloads.sql` (Chatom derived payload cleanup across 7 stores)

## Актуализация веток — 2026-11-01

Единственная рабочая ветка для Chatom integration теперь **`integration/chatom-durable-delivery`**,
HEAD **`e05ac9d836609723a0e22fe7cce80289486cfed4`**. Она fast-forward продолжена с `6fe2a25`
до уже существовавшего `e05ac9d`; отдельный ref `integration/chatom-retention-cleanup` удалён как
полностью включённый. Коммиты, исходники, незакоммиченные изменения и index сохранены.
Старое имя ветки в отчётах ниже — историческое. Не создавать третью integration-ветку.

Все дальнейшие доработки AgenTools для Chatom делать в этом репозитории и этой ветке.
Активный `agentools-plan2` ведёт другой исполнитель: не менять его source/index/HEAD и не
дублировать deliverables его плана. Уже сохранённый retention/cleanup candidate по-прежнему
заморожен в пересекающейся части; объединение Git refs не означает его приёмку или активацию.
Evidence: `chatom/var/quality/ai-transcript-branch-consolidation-20261101`.
Продолжение: `chatom/tasks/2026-11-01-chatom-ai-transcript-handoff.md`.

Последний непересекающийся срез — [изолированный Edufy Messenger candidate](/home/vostrikov/projects/packages/chatom/tasks/2026-10-31-chatom-ai-transcript-host-profile.md):
11 tests / 89 assertions через tools/bin/test, Pint/PHPStan проходят. Candidate находится только
в `edufy/tools/profiles/chatom-transcript/overlays`; обычный runtime Edufy сохранён.
School/prescreen/Identity directory остаются boundary fixtures. Полная I5.3 NOT ACCEPTED;
consumer/host/live aggregate gates не присвоены. Active PLAN2 этим срезом не менялся.

## Ограничение владельца: не дублировать PLAN2

После сверки подтверждено пересечение owner retention/derived cleanup, context,
summary/memory/cache, delivery и voice с PLAN2. Дальнейшая параллельная реализация
этих пунктов прекращена. Новый candidate сохранён в отдельной ветке
`integration/chatom-retention-cleanup`, commit `e05ac9d836609723a0e22fe7cce80289486cfed4`,
parent `6fe2a25`. Основной checkout сейчас на этой ветке; candidate заморожен и
в активный PLAN2 не влит. Это не принятый owner API и не закрытие пунктов PLAN2.

Новые правки данного продолжения сняты с `agentools-plan2` с проверкой входных hashes;
HEAD/index и параллельная работа сохранены. В активном PLAN2 последняя migration
по-прежнему `0008`; candidate `0009` находится только в отдельной integration-ветке.
Связанный новый адаптер Chatom снят и сохранён patch. Дальше требуется потреблять
API единственного владельца PLAN2 без второй реализации его deliverables.

Отчёт: `chatom/tasks/2026-10-31-chatom-ai-transcript-scope-correction.md`.
Evidence: `chatom/var/quality/ai-transcript-host-20261031`.
Полная I5.3 остаётся NOT ACCEPTED; host/live gates не присвоены.

## Продолжение 2026-10-31: интегрировано в текущий PLAN2

Diff **только `6fe2a25` после `e993633`** применён в существующем
`/home/vostrikov/projects/packages/agentools-plan2`, ветка `execution/PLAN2-platform`.
Использован Git three-way merge с отдельным временным index, затем materialized
только delta к текущим файлам. Baseline повторно не переносился; HEAD, рабочий
index и параллельные изменения PLAN2 сохранены. Новый commit не создавался.

`0006_delivery_claims.sql` сохранён побайтно. Параллельный PLAN2 добавил
`0007_action_submissions.sql`; новые retention controls используют **`0008_payload_holds.sql`**.
Теперь есть durable holds/release receipts, bounded numeric-policy decorator,
expiry/orphan scan и fsync directory в encrypted backend. Direct Action input refs
не обходят unresolved/purge guards. Документ: `docs/platform/chatom-retention.md`
в активном checkout. Исходная integration-ветка по-прежнему содержит прежний
проверенный срез, а **текущий source для установки — активный PLAN2 checkout**.

Новая проверка: owner source **60 tests / 1486 assertions / 0 skips**; свежий
Composer-installed Chatom consumer **162 / 906 / 0 skips**. Изолированный actual
Edufy app profile через `tools/bin/test`: **2 / 26**, только tuple/schema и negative
readiness. Реальный MessengerPort бросает `CapabilityUnavailable`; R1–R12 не закрыты.
Полная I5.3 по-прежнему **NOT ACCEPTED**: host mappings/ACL, семь derived cleanup
owners, deployment retention/key/backup policy и live voice остаются открытыми.

Актуальный отчёт:
`/home/vostrikov/projects/packages/chatom/tasks/2026-10-31-chatom-ai-transcript-I5.3-execution.md`.
Evidence: `chatom/var/quality/ai-transcript-finish-20261031`.
Ни push, ни release/production activation, ни PLAN6/P6.4 не выполнялись.

---


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
