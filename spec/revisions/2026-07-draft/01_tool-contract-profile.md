# 01 — Tool Contract Profile

> Ревизия спеки: `2026-07-draft` (см. `README.md`). Статус: draft.
> Нормативные ключевые слова — по [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119)
> (MUST / SHOULD / MAY). Базис — MCP, ревизия
> [2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/server/tools).

Документ задаёт **профиль tool-контракта AgenTools** — требования, которые пакет
накладывает на MCP-инструмент, и расширение, которое он несёт в `_meta`. Профиль —
вычитаемое подмножество уже принятых контрактов обоих контуров экосистемы
(PHP: `corex/ai` ToolProvider/ToolRegistry; Dart: `axioma_mcp` AxiomaTool). Это фундамент
спеки: всё, что описывают `02_action-lifecycle.md`–`06_idempotency-errors.md`, оперирует
инструментом именно в этой форме.

Профиль спроектирован **stateless-ready**: он не опирается на MCP-примитивы, помеченные
к депрекации в ревизии 2026-07-28 (Roots / Sampling / Logging / сессии). Состояние
диалога и подтверждений живёт в БД приложения (см. `02_action-lifecycle.md`), а не в
MCP-сессии.

Схемы к документу: `schema/tool-descriptor.json`, `schema/side-effect.json`.

---

## 1. Базис: MCP tool (2025-11-25)

**TCP-1 (MUST).** Инструмент AgenTools есть MCP-tool ревизии 2025-11-25. Поля
`name`, `title`, `description`, `inputSchema`, `outputSchema`, `annotations`
трактуются строго по MCP. Профиль их **не переопределяет** — только сужает
использование (разделы 2–3) и добавляет метаданные (раздел 4).

**TCP-2 (MUST).** Корень `inputSchema` — `type: object` (требование MCP). Содержимое
SHOULD быть валидной JSON Schema 2020-12. Профиль не разбирает `inputSchema`: его
валидирует рантайм.

**TCP-3 (SHOULD).** Для инструментов, чей результат рендерится в UI (preview, таблицы,
карточки), объявлять `outputSchema`. Тогда сервер MUST вернуть `structuredContent`,
соответствующий схеме (MCP), и SHOULD продублировать его сериализованным JSON в
`TextContent` (обратная совместимость MCP).

**TCP-4 (SHOULD).** Задавать `title` — человекочитаемое имя для UI; `name` для показа
человеку не использовать.

## 2. Имя инструмента

**TCP-5 (MUST).** `name` соответствует шаблону `^[a-z][a-z0-9]*(_[a-z0-9]+)+$`, длина
≤64 символов, с префиксом модуля `{module}_`. Запрещены ведущие/хвостовые/сдвоенные
подчёркивания и любые символы вне `[a-z0-9_]`.

**TCP-6 (SHOULD).** Структура имени — `{module}_{resource}_{verb}` (напр.
`crm_report_create`, `fina_revenue_summary`). Порядок `resource`/`verb` — рекомендация,
не MUST: устоявшиеся формы вроде `resto_open_shift` допустимы.

**TCP-7 (MUST).** `name` уникален в пределах одного сервера/реестра инструментов
(один `ToolRegistry` — D8 мастер-плана; инструмент пишется один раз и обслуживает и
MCP-сервер, и внутренний agent-loop).

## 3. Аннотации побочных эффектов

MCP `ToolAnnotations` (`readOnlyHint`, `destructiveHint`, `idempotentHint`,
`openWorldHint`) — по спеке MCP лишь **подсказки** и для недоверенного сервера MUST
считаться untrusted. В контуре AgenTools сервер свой (доверенный), поэтому профиль
поднимает их до сигнала политики.

**TCP-8 (MUST).** Все четыре аннотации задаются **явно**. Полагаться на консервативные
дефолты MCP запрещено — иначе гейты и UX выводятся из неявных значений.

**TCP-9 (MUST).** Аннотации согласованы с `side_effect` (раздел 4):

- `side_effect = read` ⇒ `readOnlyHint = true`;
- `side_effect ∈ {write, irreversible}` ⇒ `readOnlyHint = false`;
- `side_effect = irreversible` ⇒ `destructiveHint = true`;
- для `write`/`irreversible`: `idempotentHint = true` ⇒ `idempotent_commit = true`
  (раздел 4); для `read`/`draft` `idempotent_commit` неприменим (фазы commit нет) и
  не задаётся, при этом `idempotentHint` для read штатно `true`.

**TCP-10 (MUST, доверенный сервер).** Для внутреннего (trusted) сервера аннотации —
**enforcement-сигнал** гейтов подтверждения, а не только UX-хинт. Внешний
(untrusted) клиент вправе трактовать их как подсказки (поведение MCP по умолчанию).

## 4. Расширение `_meta["agentools/v1"]`

Профиль несёт свои метаданные в MCP-точке расширения `_meta`, в собственном
пространстве имён `agentools/v1`. Верхний уровень tool профиль не трогает (TCP-27) —
это и даёт деградацию для внешних клиентов (раздел 8). Перечисления `side_effect` и
`confirmation` — единый источник в `schema/side-effect.json`.

**TCP-11 (MUST).** Объект `_meta["agentools/v1"]` присутствует и содержит
`side_effect` ∈ {`read`, `draft`, `write`, `irreversible`}.

**TCP-12 (MAY).** `confirmation` явно переопределяет политику гейта. При отсутствии
дефолт выводится из `side_effect` по нормативной таблице:

| side_effect  | confirmation (дефолт) | Flex stake (инфо) | Axioma Tier (инфо) |
|:-------------|:----------------------|:------------------|:-------------------|
| read         | none                  | low               | 0                  |
| draft        | none                  | —                 | 1                  |
| write        | confirm               | med \| high       | 2                  |
| irreversible | double_confirm        | —                 | —                  |

Колонка `confirmation` — **нормативна** (источник — `x-default-confirmation` в
`schema/side-effect.json`). Колонки `stake`/`Tier` — **информативные** алиасы D7
мастер-плана; полная нормативная таблица соответствия
`side_effect ↔ stake ↔ Tier` фиксируется в `00_overview.md` (P0.10). Прочерк — D7 не
специфицирует алиас для этой строки.

**TCP-13 (MUST).** Для `side_effect = irreversible` `confirmation` MUST быть
`double_confirm`. Ослаблять (переопределять на `confirm`/`none`) запрещено.

**TCP-14 (SHOULD).** `idempotent_commit: bool` объявляет идемпотентность commit-фазы.
Для `write`/`irreversible` SHOULD задаваться. Механика ключа идемпотентности —
`06_idempotency-errors.md`.

**TCP-15 (SHOULD).** `permissions_hint: string[]` — справочный список требуемых прав
для каталогизации инструмента. Это **не** механизм авторизации: enforcement прав —
дело рантайма (раздел 7), не спеки.

## 5. Two-phase конвенция write-инструментов

Профиль реализует подтверждение перед записью как конвенцию поверх **одного** имени
инструмента (D4 плана): различие prepare/commit — в наличии зарезервированного
аргумента `confirmation_token`, а не в двух разных tools. Это не раздувает `tools/list`
и совместимо с любым MCP-клиентом.

**TCP-16 (MUST).** Инструмент с `side_effect ∈ {write, irreversible}` реализует две
фазы под одним `name`:

- вызов **без** аргумента `confirmation_token` = **prepare**;
- вызов **с** `confirmation_token` = **commit**.

**TCP-17 (MUST).** Фаза prepare **не применяет изменения**. Она MUST вернуть:

- превью в `structuredContent` (что именно будет записано);
- блок `_meta["agentools/v1"] = { confirmation_token, expires_at, preview_digest }`.

Структура превью и семантика токена/дайджеста нормируются в
`03_confirmation-protocol.md`; здесь фиксируется только точка, где они появляются.

**TCP-18 (MUST).** `confirmation_token` — **зарезервированное** имя входного аргумента.
Инструмент не вправе использовать его для доменных данных. Наличие непустого
`confirmation_token` во входе означает фазу commit.

**TCP-19 (MUST).** Commit при отсутствующем, истёкшем или не совпавшем с превью токене
MUST вернуть ошибку и не применять изменения (классы ошибок и привязка токена к
`(session, step, preview_digest)` — `03_confirmation-protocol.md`,
`06_idempotency-errors.md`).

**TCP-20 (SHOULD).** Инструменты `read`/`draft` не участвуют в two-phase — их вызов
однофазный.

## 6. Ответы read-инструментов

Каноны ответов заимствованы из контракта FlexCRM MCP (B-13) и делают вывод пригодным
для LLM и для UI одновременно.

**TCP-21 (SHOULD).** read-инструмент принимает аргумент `response_format` ∈
{`concise`, `detailed`} и по умолчанию отдаёт `concise`.

**TCP-22 (SHOULD).** Списочные ответы страничные: поле `next_cursor` в результате,
разумный лимит по умолчанию (профиль рекомендует ≤50 элементов на страницу).

**TCP-23 (SHOULD).** Идентификаторы в ответах — human-readable (не сырые внутренние
ключи), чтобы модель могла ссылаться на них в диалоге и превью.

## 7. Видимость и права

**TCP-24 (MUST).** Инструмент, недоступный субъекту по правам, MUST **отсутствовать**
в `tools/list` этого субъекта — не «виден, но падает». (Инвариант обоих контуров:
Axioma — tool не виден модели вовсе; Flex — tool без права не попадает в каталог.)

**TCP-25 (MUST).** Двойная проверка: доступность повторно проверяется **при вызове**.
Для `write`/`irreversible` re-check выполняется на фазе commit (права могли измениться
между prepare и commit).

## 8. Совместимость и деградация

**TCP-26 (SHOULD).** Внешний MCP-клиент без поддержки `_meta["agentools/v1"]` видит
валидный MCP-tool, но теряет профиль-семантику (two-phase, `side_effect`, гейты).
Поэтому инструменты `write`/`irreversible` профиля SHOULD **не** публиковаться наружу
для таких клиентов без внешнего гейта подтверждения.

**TCP-27 (MUST).** Профиль не вводит несовместимых с MCP полей на верхнем уровне
инструмента. Все расширения — только внутри `_meta`. Это гарантирует, что дескриптор
остаётся корректным MCP-tool.

---

## Примеры

### Пример 1 — read-инструмент

```json
// example: tool-descriptor.json
{
  "name": "crm_report_list",
  "title": "Список отчётов о работе",
  "description": "Возвращает страницу отчётов сотрудника за период. Только чтение.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "period": { "type": "string" },
      "response_format": { "type": "string", "enum": ["concise", "detailed"] },
      "cursor": { "type": "string" }
    }
  },
  "outputSchema": {
    "type": "object",
    "properties": {
      "items": { "type": "array" },
      "next_cursor": { "type": "string" }
    }
  },
  "annotations": {
    "readOnlyHint": true,
    "destructiveHint": false,
    "idempotentHint": true,
    "openWorldHint": false
  },
  "_meta": {
    "agentools/v1": {
      "side_effect": "read",
      "permissions_hint": ["crm.report.read"]
    }
  }
}
```

Комментарий: `side_effect = read` ⇒ `readOnlyHint = true` (TCP-9); гейта нет
(дефолт `none`, TCP-12); поддержаны `response_format` и `next_cursor` (TCP-21, TCP-22).

### Пример 2 — write-инструмент (two-phase)

Дескриптор объявляет `side_effect = write` и зарезервированный аргумент
`confirmation_token`:

```json
// example: tool-descriptor.json
{
  "name": "crm_report_create",
  "title": "Отправить отчёт о работе",
  "description": "Создаёт отчёт о проделанной работе. Write: two-phase (prepare→commit).",
  "inputSchema": {
    "type": "object",
    "properties": {
      "body": { "type": "string" },
      "date": { "type": "string", "format": "date" },
      "confirmation_token": { "type": "string" }
    },
    "required": ["body", "date"]
  },
  "outputSchema": {
    "type": "object",
    "properties": {
      "report_id": { "type": "string" }
    }
  },
  "annotations": {
    "readOnlyHint": false,
    "destructiveHint": false,
    "idempotentHint": true,
    "openWorldHint": false
  },
  "_meta": {
    "agentools/v1": {
      "side_effect": "write",
      "confirmation": "confirm",
      "idempotent_commit": true,
      "permissions_hint": ["crm.report.create"]
    }
  }
}
```

**Фаза prepare** — вызов без `confirmation_token`; сервер возвращает превью и токен
(структура превью нормируется в `03_confirmation-protocol.md`):

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
      ]
    }
  },
  "_meta": {
    "agentools/v1": {
      "confirmation_token": "at_ct_9f8b1c2d",
      "expires_at": "2026-07-10T12:15:00Z",
      "preview_digest": "sha256:2b7e0a1f"
    }
  }
}
```

**Фаза commit** — повторный вызов того же `name` с `confirmation_token` и ключом
идемпотентности (`06_idempotency-errors.md`):

```json
{
  "name": "crm_report_create",
  "arguments": {
    "body": "Сегодня закрыл 3 сделки и согласовал бюджет на квартал.",
    "date": "2026-07-10",
    "confirmation_token": "at_ct_9f8b1c2d"
  },
  "_meta": {
    "agentools/v1": {
      "idempotency_key": "at1:9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b"
    }
  }
}
```

Комментарий: `readOnlyHint = false` (TCP-9); `confirmation = confirm` соответствует
дефолту `write` (TCP-12); `confirmation_token` — зарезервирован (TCP-18); при подмене
`body`/`date` после prepare токен инвалидируется, т.к. привязан к `preview_digest`
(TCP-19, детали — `03`/`06`).
