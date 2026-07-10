# 06 — Idempotency & Error Model

> Ревизия спеки: `2026-07-draft` (см. `README.md`). Статус: draft.
> Нормативные ключевые слова — по [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119)
> (MUST / SHOULD / MAY). Термины: профиль инструмента и two-phase — `01_tool-contract-profile.md`;
> FSM сессии и resume — `02_action-lifecycle.md`; confirmation_token и preview_digest —
> `03_confirmation-protocol.md`; шаги, маршруты отказа semantic/technical и компенсации —
> `04_scenario-schema.md`; конверт аудита и канонизация JCS — `05_audit-events.md`.

Документ задаёт **идемпотентность write-действий** и **единую модель ошибок** AgenTools.
Обе части — прямое следствие durable-механики `02`: resume перезапускает шаг **с начала**
(`02`/LC-16, урок LangGraph — `R-04`), поэтому без enforce-идемпотентности повторный
commit **задвоит** доменную запись. Единый формат ошибки нужен по двум причинам: модель
использует его для self-correction (обучающая ошибка — канон Anthropic tool-design,
`R-06`), а рантайм — для маршрутизации отказа по классам semantic/technical сценария
(`04`/SC-18).

Схемы к документу: `schema/idempotency.json`, `schema/error.json`.

---

## 1. Ключ идемпотентности

**IE-1 (MUST).** Ключ идемпотентности commit-вызова вычисляется по формуле (D5 плана):

```
idempotency_key = "at1:" + sha256hex( JCS( { tenant_id, session_id, step_id, tool, args } ) )
```

где `JCS(·)` — каноническая сериализация по RFC 8785 (`05` §4), `sha256hex(·)` — SHA-256,
записанный **64 знаками нижнего hex**. Все пять полей материала ключа (`KeyMaterial`,
`schema/idempotency.json#/$defs/KeyMaterial`) **обязательны**.

**IE-2 (MUST).** Префикс `at1:` **версионирует формулу** ключа. Смена состава материала
или алгоритма — новый префикс (`at2:` и т.д.); существующие `at1:`-записи от этого не
меняют смысл. Строка ключа матчится шаблоном `^at1:[0-9a-f]{64}$`.

**IE-3 (MUST).** `args` в материале ключа — **доменные аргументы commit-вызова ДО
исполнения**: ровно тот набор, что формировал превью на фазе prepare (`01`/TCP-17).
Зарезервированный аргумент `confirmation_token` (`01`/TCP-18) и служебные поля
`_meta["agentools/v1"]` в материал ключа **не входят**. Как следствие, один и тот же commit,
повторно исполненный после resume (`02`/LC-16), даёт **тот же** ключ — это и есть основа
дедупликации.

**IE-4 (MUST).** Ключ генерирует **рантайм** детерминированно из durable-состояния и
передаёт его в commit-вызове полем `_meta["agentools/v1"].idempotency_key` (`01`, конвенция
`_meta`; пример commit — `01` §Примеры). Модель ключ не вычисляет и не подменяет.

### Воспроизводимый пример ключа

Материал ключа (`KeyMaterial`) для сквозного кейса «отчёт о проделанной работе»
(`crm_report_create` в сессии `sess_crm_report_42`):

```json
{
  "tenant_id": "tnt_acme",
  "session_id": "sess_crm_report_42",
  "step_id": "create_report",
  "tool": "crm_report_create",
  "args": { "body": "Закрыл 3 сделки", "date": "2026-07-10" }
}
```

Каноническая форма JCS (ключи отсортированы на **каждом** уровне, без незначащих пробелов;
здесь используется JCS-подмножество без дробных чисел — все значения строковые, поэтому
`json.dumps(..., sort_keys=True, ensure_ascii=False, separators=(',',':'))` совпадает с
RFC 8785 байт-в-байт; не-ASCII `Закрыл` остаётся как есть в UTF-8, `05`/§4 п.2):

```
{"args":{"body":"Закрыл 3 сделки","date":"2026-07-10"},"session_id":"sess_crm_report_42","step_id":"create_report","tenant_id":"tnt_acme","tool":"crm_report_create"}
```

Итоговый ключ:

```
at1:99d8a4b33c80c11b6880b6355cebc4a56f34aab1bfbf16e1c0c511d179b07486
```

Команда для воспроизведения (хеширует каноническую строку выше; `printf '%s'` не добавляет
завершающего перевода строки):

```bash
printf '%s' '{"args":{"body":"Закрыл 3 сделки","date":"2026-07-10"},"session_id":"sess_crm_report_42","step_id":"create_report","tenant_id":"tnt_acme","tool":"crm_report_create"}' \
  | python3 -c "import sys,hashlib; print('at1:'+hashlib.sha256(sys.stdin.buffer.read()).hexdigest())"
```

## 2. Dedupe-контракт на границе инструмента

**IE-5 (MUST).** Идемпотентность commit enforce'ится **на границе инструмента** через
хранилище дедупликации с **UNIQUE-ограничением по ключу**. Запись хранилища
(`schema/idempotency.json`) = `{ key, status, result_digest?, stored_result?, ts }`, где
`status` ∈ {`in_flight`, `done`, `failed`}. Реализация хранилища (SQL тенанта Flex, Drift
Axioma) — вне репозитория (D13 плана); нормируется лишь форма записи.

**IE-6 (MUST).** Протокол обработки commit-вызова по его ключу:

| Текущее состояние ключа | Действие инструмента |
|:------------------------|:---------------------|
| записи нет              | атомарно создать запись `in_flight` и исполнить commit |
| `in_flight`            | **conflict** (`agentools.conflict.*`, `retryable=true`): одновременный дубль — не исполнять повторно |
| `done`                 | **MUST** вернуть сохранённый результат (`stored_result`/`result_digest`) **без переисполнения** |
| `failed`               | новый прогон **разрешён**: перевести запись в `in_flight` и исполнить |

**IE-7 (MUST).** По завершении commit инструмент MUST обновить запись: при успехе —
`status = done` и `result_digest = sha256(JCS(result))` (тот же дайджест, что аудит-событие
`tool_committed` несёт в `result_digest`, `05`/AU-11), опционально сохранив `stored_result`;
при сбое — `status = failed`. Переход `in_flight → done|failed` атомарен относительно
UNIQUE-ключа.

**IE-8 (MUST).** TTL записи дедупликации MUST быть **не меньше** TTL сессии: resume
допустим в пределах жизни сессии (`02`/LC-16), и повторный commit в этом окне обязан
попасть в дедупликацию, а не создать дубль.

**IE-9 (SHOULD).** Для инструментов с `side_effect = draft` (`01`/TCP-9) фаза commit
отсутствует, поэтому `at1:`-ключ **не применяется**; идемпотентность черновика SHOULD
обеспечиваться **natural key** (например, пара `(session_id, step_id)` → ровно один
черновик): повторный prepare draft SHOULD обновлять тот же черновик, а не плодить новые.
Natural key — ответственность инструмента.

## 3. Модель ошибок

**IE-10 (MUST).** Ошибка инструмента или шага представляется объектом ошибки
(`schema/error.json`): `{ code, category, retryable, message, details?, example_of_valid_call? }`.
Поле `code` — стабильный машинный код вида `agentools.<category>.<slug>`, где `<slug>` —
`snake_case`, устойчивый между ревизиями.

**IE-11 (MUST).** `category` принадлежит **закрытому enum** из восьми значений
(`schema/error.json#/$defs/ErrorCategory`): `validation`, `permission`, `not_found`,
`conflict`, `precondition`, `unavailable`, `budget`, `internal`. Расширение множества
категорий — **только новой ревизией** спеки.

**IE-12 (MUST).** Средний сегмент `code` MUST **совпадать** с полем `category` (например,
`code = "agentools.validation.date_out_of_range"` ⇒ `category = "validation"`). JSON Schema
ограничивает сегмент закрытым множеством категорий, но точную согласованность двух полей
enforce'ит **рантайм** (в схеме двух полей не связать).

**IE-13 (MUST).** Семантика полей: `retryable` — булев признак обратимости **на уровне
экземпляра**; `message` — человекочитаемое сообщение **на русском** (для пользователя
и/или подсказки модели); `details?` — структурированный контекст (например,
`{field, expected}`), уже **замаскированный от ПДн** (`05`/AU-15); `example_of_valid_call?` —
образец корректного вызова инструмента (обучающая ошибка `R-06`), дающий модели форму
валидных аргументов для self-correction, а не слепого повтора.

## 4. Маппинг категорий: класс отказа, retry, MCP

**IE-14 (MUST).** Каждая категория маппится на **класс отказа сценария** (`04`/SC-18) и
несёт **retryable-дефолт**:

| `category`     | Класс отказа (`04`) | `retryable` (дефолт) | Обоснование |
|:---------------|:--------------------|:---------------------|:------------|
| `validation`   | semantic            | `false`              | аргументы не прошли схему/бизнес-правило; модель исправляет по `example_of_valid_call`, повтор тем же вводом бесполезен |
| `permission`   | semantic            | `false`              | нет прав; повтор не поможет — эскалация к пользователю |
| `not_found`    | semantic            | `false`              | ссылка на отсутствующую сущность; модель переуточняет |
| `conflict`     | technical           | `true`               | гонка/дубль `in_flight`/версионный конфликт; повтор после паузы может пройти |
| `precondition` | semantic            | `false`              | состояние записи не допускает действие; модель меняет план |
| `unavailable`  | technical           | `true`               | транспорт/тайм-аут/квота upstream; повтор с backoff |
| `budget`       | technical           | `false`              | исчерпан бюджет прогона (`05`/AU-14); повтор бюджет не вернёт — обычно abort |
| `internal`     | technical           | `false`              | непредвиденный сбой рантайма; требует вмешательства |

Класс отказа определяет, какой маршрут шага срабатывает: semantic → `on_semantic_fail`,
technical → `on_technical_fail` (`04`/SC-15, SC-18). Значение `retryable` **экземпляра**
(`schema/error.json`) авторитетно и MAY перекрывать дефолт категории (IE-15).

**IE-15 (MUST).** Наружу ошибка выносится как **MCP tool-execution error**: результат
вызова с флагом ошибки `isError` и объектом ошибки в `structuredContent` (профиль `01`
поверх MCP 2025-11-25; исследование — `R-03`). Сбои **транспорта** (соединение,
JSON-RPC-уровень), не достигшие результата инструмента, к объекту ошибки не приводятся
рантаймом инструмента — они трактуются вызывающей стороной как `unavailable`/`internal`
по её усмотрению.

## 5. Retry-политика

**IE-16 (MUST).** Собственно **повтор** шага (число попыток, backoff) нормируется
сценарием, а не этим документом: рантайм повторяет шаг **только** если одновременно —
(1) класс отказа = technical, (2) `error.retryable = true`, (3) шаг идемпотентен
(`01`/TCP-14, enforce — §1–§2), и повтор объявлен маршрутом `on_technical_fail`
(`04`/SC-16). Число попыток `n ∈ [1..3]` и детерминированный backoff `2^i` секунд —
`04`/SC-16, SC-17. Категории с `retryable = false` по таблице §4 (`budget`, `internal`,
и все semantic) до retry-маршрута не доходят: они маршрутизируются в `abort`/`continue`.

---

## Примеры

Все примеры используют сквозной кейс `crm_report_create` в сессии `sess_crm_report_42`
(«отчёт о проделанной работе», см. `01`–`05`).

### Пример 1 — запись дедупликации (`done`)

Успешно применённый commit: повтор с этим ключом MUST вернуть `stored_result` без
переисполнения (IE-6). `key` — ключ из §1 (воспроизводится командой оттуда).

```json
// example: idempotency.json
{
  "key": "at1:99d8a4b33c80c11b6880b6355cebc4a56f34aab1bfbf16e1c0c511d179b07486",
  "status": "done",
  "result_digest": "sha256:7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d",
  "stored_result": { "report_id": "rpt_5521", "status": "created" },
  "ts": "2026-07-10T09:00:21Z"
}
```

### Пример 2 — ошибка `validation` (semantic, не retryable)

Аргумент `date` вне допустимого периода. Класс отказа — semantic (`on_semantic_fail`),
`example_of_valid_call` даёт модели форму корректного вызова.

```json
// example: error.json
{
  "code": "agentools.validation.date_out_of_range",
  "category": "validation",
  "retryable": false,
  "message": "Дата отчёта вне допустимого периода: не позднее сегодняшнего дня.",
  "details": { "field": "date", "max": "2026-07-10" },
  "example_of_valid_call": {
    "arguments": { "body": "Закрыл 3 сделки", "date": "2026-07-10" }
  }
}
```

### Пример 3 — ошибка `unavailable` (technical, retryable)

Тайм-аут вышестоящего сервиса. Класс отказа — technical (`on_technical_fail`); при
идемпотентном шаге маршрут `retry` допустим (IE-16). Тот же `error_code`, что несёт
аудит-событие `tool_failed` (`05` Пример 11).

```json
// example: error.json
{
  "code": "agentools.unavailable.upstream_timeout",
  "category": "unavailable",
  "retryable": true,
  "message": "Вышестоящий сервис не ответил вовремя; повтор допустим."
}
```
