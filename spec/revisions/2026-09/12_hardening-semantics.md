# 12 — Hardening semantics: P2–P5 successor delta

> Ревизия спеки: `2026-09` (состав и статус — `README.md`). Базовая frozen-ревизия —
> `2026-08`. Исполняемые контракты PLAN1 P2–P5 транскрибированы сюда с новыми
> маркерами `HRD-`; таблица кодов расширена в документе `09`. Кейсы —
> `fixtures/hardening/*-v1.json` и зеркала в `fixtures/2026-09/hardening/`.

Документ — **единственный носитель** норм hardening, не входивших в frozen `2026-08`.
Реализация MUST NOT трактовать plan-only markdown как норму; нормативны строки правил
ниже и таблица кодов `09`.

## Покрытие решений DR-01 (PLAN1)

| Решение | Как покрыто |
|:--|:--|
| D7 confirmation security | `HRD-1`…`HRD-8` |
| D8 token replay | `HRD-9`…`HRD-14` |
| D9 lease/fencing | `HRD-15`…`HRD-20` |
| D10 EventStore/audit | `HRD-21`…`HRD-28` |

## 1. Confirmation boundary (P2)

**HRD-1 (MUST, L1).** Поддерживаемая граница подтверждения MUST быть `ConfirmationService`;
`prepare` MUST принимать только идентификатор действия, имя инструмента, фактические
аргументы и превью; `commit` MUST принимать только непрозрачный токен и фактические
аргументы. Актор, время, дескриптор, контекст, политика, доказательства одобрения и
авторизация MUST поставляться доверенными портами конструктора, а не параметрами вызова.

**HRD-2 (MUST NOT, L1).** Реализация MUST NOT ослаблять политику или одобрение через
инъекцию вызывающего на пути `prepare`/`commit`. Любая попытка caller-injection MUST
завершаться отказом до внешнего эффекта (кейсы `CNF-SAFE-*`, `CNF-ADV-*`).

**HRD-3 (MUST, L1).** Commit MUST выполнять проверки в порядке: целостность токена и
запись → сравнение tenant/proposal/arguments/descriptor → текущая политика →
journal-backed approval → явное allow авторизации → legacy presentation boundary.
Структурные отказы MUST предшествовать presentation.

**HRD-4 (MUST, L1).** Отсутствующая запись, политика, дескриптор, актор или требуемое
доказательство MUST deny-by-default с кодами таблицы `09` (напр.
`agentools.precondition.confirmation_record_missing`,
`agentools.permission.confirmation_policy_denied`).

**HRD-5 (MUST NOT, L1).** Неизвестное решение авторизации, необработанное исключение или
пустой allow MUST NOT трактоваться как разрешение (`HRD-3`, кейсы `CNF-ADV-*`).

## 2. Token envelope и durable consume (P3)

**HRD-9 (MUST, L1).** Токен подтверждения MUST использовать bounded codec `at1` без
unsigned JOSE header; лишние сегменты, отсутствие префикса или неизвестный префикс MUST
отвергаться как malformed (`agentools.validation.confirmation_token_invalid`).

**HRD-10 (MUST, L1).** Погашение токена MUST быть атомарным через `ConfirmationTokenStore`;
process-local множества spent MUST NOT быть единственным механизмом на поддерживаемом пути.

**HRD-11 (MUST, L1).** Повторное предъявление уже погашенного токена MUST возвращать
`agentools.precondition.confirmation_token_spent` до любого внешнего эффекта.

**HRD-12 (MUST, L1).** Несовпадение binding tuple (tenant, proposal, arguments, principal,
expiry, key id) MUST deny с кодами permission/precondition таблицы `09`.

## 3. Lease/fencing idempotency (P4)

**HRD-15 (MUST, L1).** Production idempotency MUST использовать fenced acquire/heartbeat/
complete/fail с lease token; unfenced `complete(request)` MUST NOT оставаться на
production interface после cutover.

**HRD-16 (MUST, L1).** Пустой `RETURNING` после CAS complete/fail MUST трактоваться как
lost lease; эффект MUST NOT исполняться (`agentools.conflict.idempotency_lost_lease`).

**HRD-17 (MUST, L1).** `IdempotencyCoordinator` MUST применять `ToolIdempotencyCapability`
до/после acquire; `unknown` high-risk MUST deny до acquire
(`agentools.precondition.idempotency_capability_unknown`).

**HRD-18 (MUST, L1).** Takeover без probe на `read_before_write` MUST NOT автоматически
re-invoke; ambiguous external effect MUST `fail(lease, ambiguous)`
(`agentools.precondition.idempotency_ambiguous_effect`).

## 4. EventStore, audit и observability (P5)

**HRD-21 (MUST, L1).** Поддерживаемый journal port MUST быть `EventStore.append/load`;
caller MUST NOT поставлять `seq`/`global_position`; dual uncommitted+caller-seq append
запрещён.

**HRD-22 (MUST, L1).** Append MUST классифицировать batch до INSERT по правилам P5 contract
(collision, partial overlap, version conflict, validation codes таблицы `09`).

**HRD-23 (MUST, L1).** Pagination MUST быть keyset-only; `OFFSET` запрещён; limit вне
[1,1000] MUST `agentools.budget.event_page_limit`.

**HRD-24 (MUST, L1).** Audit/metric deny-list MUST проверяться до persist; forbidden field
→ `agentools.validation.audit_forbidden_field`; forbidden label →
`agentools.validation.metric_label_forbidden`.

**HRD-25 (MUST, L1).** `ActionSnapshot` restore MUST отбрасывать snapshot при hash/spec_rev
mismatch и fold journal; журнал MUST NOT усечься snapshot.

## 5. Совместимость с `2026-08`

**HRD-28 (MUST, L1).** Реализация, декларирующая только `2026-08`, MUST NOT обязана
реализовывать `HRD-*`; реализация `2026-09` MUST реализовать `HRD-*` и расширенную таблицу
кодов `09`. Изменённые spellings кодов — migration entry, не alias в SDK.

Инвентарь migration — `plans/2026.09.19-№1-AGENTOOLS-HARDENING/findings/P6-semantic-migration.md`.
