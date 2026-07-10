# 03 — Confirmation Protocol

> Ревизия спеки: `2026-07-draft` (см. `README.md`). Статус: draft.
> Нормативные ключевые слова — по [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119)
> (MUST / SHOULD / MAY). Термины профиля инструмента — `01_tool-contract-profile.md`;
> состояния и переходы FSM — `02_action-lifecycle.md`.

Документ задаёт **протокол подтверждения** AgenTools: two-phase-конвейер
prepare → preview → commit поверх одного имени инструмента (D4 плана), семантику
`confirmation_token`, решения пользователя (accept / decline / edit / revoke / expiry),
политику гейтов из `side_effect`, policy-валидацию до показа превью и **уточняющие
вопросы** (clarification). Это центральный кусок добавленной стоимости пакета: превью и
подтверждение перед записью — главное продуктовое требование (юридически и финансово
значимые действия) и ровно то, чего в MCP нет (`R-03`, гэп №1).

Протокол транспорт-агностичен: он описывает **семантику** подтверждения и уточнения, а
не виджеты UI. Биндинги к MCP elicitation и к Reverb-событиям — информативные детали
рантайма (§8, D12 плана). Состояние гейтов живёт в append-only логе и снимках сессии
(`02_action-lifecycle.md`), а не в MCP-сессии — профиль stateless-ready.

Схемы к документу: `schema/confirmation.json`, `schema/clarification.json`.

---

## 1. Two-phase поток (prepare → preview → commit)

**CP-1 (MUST).** Гейт подтверждения применяется к инструментам с
`side_effect ∈ {write, irreversible}` (`01`, TCP-16). Инструменты `read`/`draft`
однофазны и гейта не несут (TCP-20; дефолт `confirmation = none`,
`schema/side-effect.json`).

**CP-2 (MUST).** Фаза **prepare** — вызов инструмента без аргумента
`confirmation_token`. Она MUST NOT применять изменения (`01`, TCP-17) и MUST:

- вернуть превью в `structuredContent` и в `_meta["agentools/v1"].preview` (§3);
- вернуть блок `_meta["agentools/v1"] = { confirmation_token, expires_at, preview_digest }`;
- создать в хранилище рантайма запись `PendingAction` (`schema/confirmation.json`) со
  статусом `pending`.

На уровне FSM prepare исполняется в `EXECUTING_TOOL` и по возврату превью ведёт сессию в
`WAITING_APPROVAL` (`02`, LC-T13, триггер `approval_required`).

**CP-3 (MUST).** Фаза **commit** — повторный вызов того же `name` с непустым
`confirmation_token` (`01`, TCP-18). Рантайм MUST провалидировать токен (§2) до
применения; при успехе изменения применяются, `PendingAction.status` становится
`committed`. На уровне FSM согласие ведёт `WAITING_APPROVAL → EXECUTING_TOOL`
(LC-T18, `approval_granted`); успешный commit возвращает `EXECUTING_TOOL → RUNNING`
(LC-T14, `tool_succeeded`).

**CP-4 (MUST).** Разграничение ответственности: **рантайм** (хост AgenTools)
персистит `PendingAction`, пишет lifecycle-события и хранит привязку токена; **инструмент**
(доменный сервер) вычисляет превью на фазе prepare и применяет изменение на фазе commit.
Инструмент не хранит состояние подтверждения между вызовами — источник истины по гейту —
лог и снимок сессии (`02`, §6).

### Диаграмма последовательности (информативная)

Нормативны правила CP-*; диаграмма приведена для наглядности.

```mermaid
sequenceDiagram
  participant U as Пользователь (UI)
  participant R as Рантайм AgenTools
  participant T as Инструмент (домен)
  U->>R: запрос действия (write-инструмент)
  R->>R: policy-валидация ДО превью (CP-20)
  R->>T: prepare(args) без confirmation_token
  T-->>R: preview + _meta{confirmation_token, expires_at, preview_digest}
  R->>R: PendingAction=pending; FSM → WAITING_APPROVAL (LC-T13)
  R-->>U: показать preview (approval_requested)
  U-->>R: решение (accept | decline | edit | revoke)
  alt accept
    R->>T: commit(confirmation_token, idempotency_key)
    T-->>R: результат; PendingAction=committed
    R->>R: FSM → EXECUTING_TOOL (LC-T18) → RUNNING (LC-T14); approval_resolved
  else decline
    R->>R: PendingAction=declined; FSM → RUNNING (LC-T19)
  else revoke
    R->>R: PendingAction=revoked; FSM → CANCELLED (LC-T20)
  end
```

## 2. confirmation_token

**CP-5 (MUST).** `confirmation_token` — непрозрачная строка, MUST нести **≥128 бит
энтропии** и быть **одноразовой** (single-use). Пользователь и модель не должны уметь
предсказать или переиспользовать токен.

**CP-6 (MUST).** Токен несёт **TTL**. Дефолт — **15 минут**, значение конфигурируемо
потребителем. По достижении `expires_at` токен инвалиден (§4, expiry).

**CP-7 (MUST).** Токен MUST быть привязан к тройке `(session_id, step_id, preview_digest)`,
где `preview_digest = sha256(JCS(args))` над доменными аргументами, сформировавшими
превью (алгоритм JCS и формат — `06_idempotency-errors.md`, D5 плана). В снимке
`PendingAction` этот дайджест персистится полем `args_digest` — это **тот же** дайджест,
что фаза prepare отдаёт на проводе как `preview_digest` (`01`, TCP-17). Подмена доменных
аргументов между prepare и commit меняет дайджест: commit с токеном, чей `args_digest` не
совпадает с дайджестом присланных на commit аргументов, MUST быть отвергнут без применения
изменений (`01`, TCP-19; класс ошибки — `06`).

**CP-8 (MUST).** Токен одноразовый на уровне гейта: после перехода `PendingAction` в любой
терминальный статус (`committed`/`declined`/`revoked`/`expired`) повторное предъявление
того же токена MUST быть отвергнуто. Безопасность повторной доставки самого commit-вызова
(at-least-once, ретраи сети) обеспечивается **не** повторным предъявлением токена, а ключом
идемпотентности commit-вызова (`06`): дедуп на границе инструмента вернёт сохранённый
результат, не открывая гейт заново.

## 3. Payload превью

**CP-9 (MUST).** Превью (`schema/confirmation.json`, `$defs/Preview`) присутствует
одновременно в `structuredContent` результата prepare и в
`_meta["agentools/v1"].preview` (`01`, TCP-17). Структура: `title` (строка), `summary`
(строка), опционально `diff` и `warnings`.

**CP-10 (MUST).** Превью **детерминировано**: одинаковые доменные аргументы MUST давать
одинаковое превью (NFR §7 мастер-плана «детерминированный preview»). Это предпосылка
привязки токена по дайджесту (CP-7): недетерминированное превью сделало бы дайджест
бессмысленным.

**CP-11 (SHOULD).** `diff` — список изменений поле-за-полем: `{field, old, new}`. `old = null`
означает создание поля (значение появляется впервые). Типы `old`/`new` — доменные
(строка/число/bool/null/объект), профиль их не ограничивает.

**CP-12 (SHOULD).** `warnings` — человекочитаемые (ru) предупреждения о рисках действия,
которые UI SHOULD показать до подтверждения (напр. «Отчёт будет отправлен руководителю и
недоступен для правки»).

## 4. Решения пользователя

Каждый исход — отдельное правило со своим событием и переходом FSM. Терминология решений —
`$defs/Decision` схемы `confirmation.json`.

**CP-13 (MUST) — accept.** Пользователь подтверждает превью → рантайм исполняет фазу
commit (CP-3). `PendingAction.status`: `pending → committed`. FSM:
`WAITING_APPROVAL → EXECUTING_TOOL` (LC-T18) → при успехе `→ RUNNING` (LC-T14). Гейт
фиксируется audit-событием `approval_resolved` с `decision = accept` и
governance-метаданными (§5, CP-19).

**CP-14 (MUST) — decline.** Пользователь отклоняет превью → изменения не применяются,
`PendingAction.status`: `pending → declined`, токен инвалидируется (CP-8). FSM:
`WAITING_APPROVAL → RUNNING` (LC-T19, `approval_declined`) — **сессия продолжается**
(агент вправе перепланировать или завершить). Гейт фиксируется `approval_resolved` с
`decision = decline`.

**CP-15 (MUST) — edit.** Пользователь правит аргументы перед подтверждением. Edit
эквивалентен **decline текущего действия + новый prepare** с новыми аргументами: прежний
`PendingAction` переходит в `declined` (вытеснен), рантайм открывает новый гейт (новый
токен, новое превью, новый `PendingAction=pending`). Новых переходов FSM edit не вводит:
`WAITING_APPROVAL → RUNNING` (LC-T19), затем повторный вызов инструмента
`RUNNING → EXECUTING_TOOL` (LC-T5) и `→ WAITING_APPROVAL` (LC-T13).

**CP-16 (MUST) — revoke.** Пользователь отзывает действие до commit → `PendingAction.status`:
`pending → revoked`. В отличие от decline, revoke **отменяет сессию**: FSM
`WAITING_APPROVAL → CANCELLED` (LC-T20, `cancelled`; терминальное состояние, `02`, LC-1).

**CP-17 (MUST) — expiry.** По достижении `expires_at` без решения токен авто-declined
(`PendingAction.status`: `pending → expired`), изменения не применяются. На уровне FSM
истёкший гейт ведёт сессию в терминальное `EXPIRED`: `WAITING_APPROVAL → EXPIRED`
(LC-T21, `expired`, actor `type = system`). Механика истечения на уровне FSM — `02`, LC-18.

## 5. Политика гейта из side_effect

**CP-18 (MUST).** Политика гейта выводится из `side_effect` по нормативной таблице
`01`/TCP-12 (источник — `x-default-confirmation` в `schema/side-effect.json`):
`read`/`draft` → `none`; `write` → `confirm`; `irreversible` → `double_confirm`. Явный
`confirmation` в дескрипторе инструмента может ужесточить политику, но для `irreversible`
ослабление запрещено (`01`, TCP-13).

**CP-19 (MUST).** `confirm` — **один** гейт подтверждения: единичный конвейер
prepare → preview → commit (§1). Одно решение accept открывает commit.

**CP-20 (MUST).** `double_confirm` — **два последовательных** гейта подтверждения (не
UI-виджет с «двойным кликом»). Первый гейт — обычное превью + accept. Второй гейт — повторный
показ превью, который MUST требовать **явного подтверждающего ввода**: отдельное required-поле,
отсутствующее у первого гейта (подтверждающая фраза либо явный чекбокс — семантика, не
рендер). Commit исполняется только после accept **обоих** гейтов. На уровне FSM это два
цикла `EXECUTING_TOOL → WAITING_APPROVAL → EXECUTING_TOOL` (LC-T13/LC-T18 дважды): accept
первого гейта ведёт снова в `EXECUTING_TOOL`, откуда повторный `approval_required` открывает
второй гейт. Каждый гейт независимо поддаётся decline/revoke/expiry (CP-14, CP-16, CP-17).

**CP-21 (MUST).** Каждый гейт порождает два audit-события: `approval_requested` (при входе в
`WAITING_APPROVAL`) и `approval_resolved` (на решении) — оба несут governance-метаданные
`{decided_by, decided_at, scope, expires_at}` (`schema/confirmation.json`,
`$defs/GovernanceMetadata`). Это **аудит-слой**, отличный от lifecycle-триггеров
`approval_required`/`approval_granted`/`approval_declined` из `02` (те несут переход
состояния; governance-события несут «кто и в каком scope решил»). Envelope и `kind` этих
audit-событий нормируются в `05_audit-events.md` (P0.7) и MUST ссылаться на
`$defs/GovernanceMetadata`, не копируя его.

## 6. Policy-валидация до превью

**CP-22 (MUST).** Рантайм MUST провалидировать вызов **до** формирования и показа превью
(паттерн Loqum SPEC-05: policy до dispatch — защита от prompt injection, `R-00` Грань C).
Проверяется:

- **допустимость инструмента сессии** — closed-world allowlist инструментов, разрешённых
  этой сессии (модель не может расширить свой набор инструментов подставленным именем);
- **права субъекта** — инструмент виден субъекту и разрешён ему (`01`, TCP-24: недоступный
  инструмент отсутствует в `tools/list`);
- **соответствие аргументов `inputSchema`** инструмента.

Провал любой проверки MUST завершиться **без превью и без токена**, ошибкой класса
`permission`/`validation` (`06`). Превью недоверенного/неавторизованного действия не
показывается пользователю вовсе.

**CP-23 (MUST).** Проверка прав MUST повторяться на фазе commit: права могли измениться между
prepare и commit (`01`, TCP-25). Валидный токен не заменяет re-check прав.

## 7. Уточняющие вопросы (clarification)

**CP-24 (MUST).** Когда рантайму нужно доуточнение (недостающее поле, разрешение
неоднозначности, «правильно ли я понял?»), он порождает `ClarificationRequest`
(`schema/clarification.json`): `{id, question, kind, options?, default?, schema?}`. FSM:
`RUNNING → WAITING_INPUT` (`02`, LC-T4, `clarification_requested`).

**CP-25 (MUST).** `kind` ∈ `{confirm, select, multi_select, text}` (ровно четыре):

- `confirm` — булево «правильно ли я понял?»;
- `select` — ровно один вариант из `options`;
- `multi_select` — подмножество `options`;
- `text` — свободный ввод (опционально ограничен полем `schema`).

`options` REQUIRED для `select`/`multi_select` и SHOULD отсутствовать для `confirm`/`text`.
Вариант — `{value, title}`: `value` (машинное, ≤128) кладётся в ответ, `title`
(отображаемое, ≤256) показывается пользователю.

**CP-26 (MUST).** Ответ — `ClarificationAnswer` (`$defs/ClarificationAnswer`):
`{id, status, value?}`, где `id` MUST совпадать с `id` запроса. При `status = answered`
`value` присутствует и согласован с `kind` (bool / value опции / массив value / строка).
FSM: `WAITING_INPUT → RUNNING` (`02`, LC-T10, `input_received`).

**CP-27 (MUST).** Исходы `declined` и `cancelled` различаются:

- `declined` — пользователь отказался отвечать на этот вопрос. Рантайм MAY продолжить по
  правилу сценария: подставить `default` (если задан) либо маршрутизировать как отказ шага
  (`04_scenario-schema.md`, ветки `on_*_fail`). При отсутствии фолбэка рантайм MUST
  трактовать исход как `cancelled`.
- `cancelled` — пользователь прервал диалог. Сессия MUST перейти `WAITING_INPUT → CANCELLED`
  (`02`, LC-T11, `cancelled`).

**CP-28 (MUST).** Истечение `WAITING_INPUT` по `expires_at` ведёт сессию в `EXPIRED`
(`02`, LC-T12, `expired`), аналогично истечению гейта подтверждения (CP-17).

**CP-29 (MAY).** `default` — предзаполнение/подсказка значения; его тип согласован с `kind`.
Способ применения `default` (предвыбор в UI, фолбэк при `declined`) — деталь рантайма, не
нормируется.

## 8. Транспорт-биндинги (информативно)

Раздел информативен: нормативен транспорт-агностичный объект `ClarificationRequest`.

**CP-30 (SHOULD).** `ClarificationRequest` отображается 1:1 на **MCP elicitation form-mode**
(`R-03`, D4/D12 плана): `confirm → boolean`; `select → enum` (single); `multi_select →
items.enum`; `text → string` (с `schema` как ограничениями); `options → titled enum`
(oneOf/const/title, SEP-1330); `default → default`. Исходы `answered`/`declined`/`cancelled`
соответствуют elicitation `accept`/`decline`/`cancel`.

**CP-31 (SHOULD).** В PHP-контуре v1 (FlexCRM) уточнение доставляется **Reverb-событием**
по образцу command envelope B-13 (`R-00` Грань B): у `laravel/mcp` client-side elicitation
отсутствует, поэтому клиент (он же UI) реализует обработчик сам (D12). Семантика вопроса
идентична; отличается лишь транспорт.

## 9. Привязка к FSM (сводка)

Нормативная таблица соответствия исходов протокола переходам `02_action-lifecycle.md`
(единственный источник переходов — таблица §2 того документа):

| Событие протокола             | Переход FSM                          | LC     |
|:------------------------------|:-------------------------------------|:-------|
| prepare открыл гейт           | `EXECUTING_TOOL → WAITING_APPROVAL`   | LC-T13 |
| accept → commit               | `WAITING_APPROVAL → EXECUTING_TOOL`   | LC-T18 |
| commit успешен                | `EXECUTING_TOOL → RUNNING`            | LC-T14 |
| decline                       | `WAITING_APPROVAL → RUNNING`          | LC-T19 |
| revoke                        | `WAITING_APPROVAL → CANCELLED`        | LC-T20 |
| гейт истёк (expiry)           | `WAITING_APPROVAL → EXPIRED`          | LC-T21 |
| clarification открыт          | `RUNNING → WAITING_INPUT`             | LC-T4  |
| clarification answered        | `WAITING_INPUT → RUNNING`             | LC-T10 |
| clarification cancelled       | `WAITING_INPUT → CANCELLED`           | LC-T11 |
| clarification истёк (expiry)  | `WAITING_INPUT → EXPIRED`             | LC-T12 |

---

## Примеры

### Пример 1 — снимок PendingAction

Отложенное действие `crm_report_create` (`side_effect = write`, `01` Пример 2) ждёт
подтверждения. `args_digest` = `sha256:332f...cd23` — реальный SHA-256 канонической формы
доменных аргументов `{"body":"…","date":"2026-07-10"}` (JCS — `06`; воспроизводимость
дайджеста фиксируется там).

```json
// example: confirmation.json
{
  "token": "at_ct_9f8b1c2d4e6a8c0f2b4d6e8a0c2e4f60",
  "session_id": "sess_crm_report_42",
  "step_id": "save",
  "tool": "crm_report_create",
  "args_digest": "sha256:332f8dd09222a1b1f5baa77968645d1727ffda41f6ed2221172882a0aa28cd23",
  "preview": {
    "title": "Отчёт за 10.07.2026",
    "summary": "Будет создан отчёт о проделанной работе на 3 пункта.",
    "diff": [
      { "field": "status", "old": null, "new": "submitted" }
    ],
    "warnings": ["Отчёт станет виден руководителю и будет недоступен для правки."]
  },
  "created_at": "2026-07-10T12:00:00Z",
  "expires_at": "2026-07-10T12:15:00Z",
  "status": "pending"
}
```

### Пример 2 — ответ фазы prepare

Вызов `crm_report_create` без `confirmation_token`: инструмент вернул превью и токен, не
применив изменения (CP-2). Превью дублируется в `structuredContent` и в
`_meta["agentools/v1"].preview`; `preview_digest` = тот же дайджест, что персистится как
`args_digest`.

```json
{
  "content": [
    { "type": "text", "text": "Черновик отчёта готов. Проверьте и подтвердите отправку." }
  ],
  "structuredContent": {
    "preview": {
      "title": "Отчёт за 10.07.2026",
      "summary": "Будет создан отчёт о проделанной работе на 3 пункта.",
      "diff": [
        { "field": "status", "old": null, "new": "submitted" }
      ],
      "warnings": ["Отчёт станет виден руководителю и будет недоступен для правки."]
    }
  },
  "_meta": {
    "agentools/v1": {
      "preview": {
        "title": "Отчёт за 10.07.2026",
        "summary": "Будет создан отчёт о проделанной работе на 3 пункта."
      },
      "confirmation_token": "at_ct_9f8b1c2d4e6a8c0f2b4d6e8a0c2e4f60",
      "expires_at": "2026-07-10T12:15:00Z",
      "preview_digest": "sha256:332f8dd09222a1b1f5baa77968645d1727ffda41f6ed2221172882a0aa28cd23"
    }
  }
}
```

### Пример 3 — audit-событие approval_resolved

Пользователь нажал accept. Гейт разрешён; фиксируется governance-контекст (CP-21). `kind`
`approval_resolved` — **аудит-событие** (envelope нормируется в `05_audit-events.md`, P0.7);
здесь блок иллюстративен, а его `payload.governance` соответствует `$defs/GovernanceMetadata`
схемы `confirmation.json`.

```json
{
  "event_id": "01J9Z3P4S7B9D1F3H5K7M9Q1T3",
  "session_id": "sess_crm_report_42",
  "seq": 7,
  "ts": "2026-07-10T12:03:20Z",
  "actor": { "type": "user", "id": "user_128" },
  "kind": "approval_resolved",
  "payload": {
    "decision": "accept",
    "governance": {
      "decided_by": "user_128",
      "decided_at": "2026-07-10T12:03:20Z",
      "scope": "crm.report.create",
      "expires_at": "2026-07-10T12:15:00Z"
    }
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 4 — commit-вызов

Повторный вызов того же `name` с `confirmation_token` и ключом идемпотентности (`06`).
Подмена `body`/`date` инвалидировала бы токен, т.к. он привязан к `preview_digest` (CP-7).

```json
{
  "name": "crm_report_create",
  "arguments": {
    "body": "Сегодня закрыл 3 сделки и согласовал бюджет на квартал.",
    "date": "2026-07-10",
    "confirmation_token": "at_ct_9f8b1c2d4e6a8c0f2b4d6e8a0c2e4f60"
  },
  "_meta": {
    "agentools/v1": {
      "idempotency_key": "at1:9c1f4b7e"
    }
  }
}
```

### Пример 5 — ClarificationRequest (select, 3 варианта)

Резолвер нашёл несколько совпадений («какой из Ивановых?», `R-00` Грань B) — рантайм задаёт
уточнение и уходит в `WAITING_INPUT` (CP-24, LC-T4).

```json
// example: clarification.json
{
  "id": "clr_pick_contact",
  "question": "Какой из контактов Иванов имеется в виду?",
  "kind": "select",
  "options": [
    { "value": "contact_101", "title": "Иванов Иван Иванович (ООО «Ромашка»)" },
    { "value": "contact_102", "title": "Иванов Пётр Сергеевич (ИП Иванов)" },
    { "value": "contact_103", "title": "Иванова Мария Олеговна (АО «Спектр»)" }
  ],
  "default": "contact_101"
}
```

### Пример 6 — ClarificationAnswer

Пользователь выбрал второй вариант; сессия возвращается в `RUNNING` (CP-26, LC-T10). Блок
соответствует `$defs/ClarificationAnswer` схемы `clarification.json`.

```json
{
  "id": "clr_pick_contact",
  "status": "answered",
  "value": "contact_102"
}
```
