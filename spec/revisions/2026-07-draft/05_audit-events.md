# 05 — Audit Events

> Ревизия спеки: `2026-07-draft` (см. `README.md`). Статус: draft.
> Нормативные ключевые слова — по [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119)
> (MUST / SHOULD / MAY). Термины: конверт события и FSM — `02_action-lifecycle.md`;
> two-phase и governance-метаданные — `03_confirmation-protocol.md`; шаги и компенсации —
> `04_scenario-schema.md`; профиль инструмента — `01_tool-contract-profile.md`.

Документ задаёт **таксономию аудит-событий** AgenTools, их конверт (наследуемый из `02`),
**канонизацию** JSON по [RFC 8785 (JCS)](https://www.rfc-editor.org/rfc/rfc8785) для
межъязыковых golden-сравнений и ключей идемпотентности, а также правила трассировки и
хранения. Аудит — **юридическая опора write-действий**: кто подтвердил, что именно
исполнено. Канонизация — фундамент того, что PHP- и Dart-рантаймы дают **байт-в-байт**
одинаковую сериализацию (golden-тесты, ключи идемпотентности `06_idempotency-errors.md`).

Схема к документу: `schema/audit-event.json`.

---

## 1. Отношение к жизненному циклу (`02`)

**AU-1 (MUST).** Аудит-событие использует **тот же конверт**, что событие жизненного
цикла (`02` §3): `event_id`, `session_id`, `seq`, `ts`, `actor {type, id}`, `kind`,
`payload`, `spec_rev`. Аудит-события пишутся в **тот же** append-only лог сессии; `seq`
монотонен, стартует с 1, идёт без дыр (`02`/LC-6) — это **общий** счётчик с
lifecycle-событиями. Схема `audit-event.json` берёт общие части конверта **ссылкой**, не
копией: `actor` — через `$ref` на `lifecycle-event.json#/$defs/Actor` (`02`/LC-10).

**AU-2 (MUST).** `kind` аудит-события принадлежит закрытому enum §2, **дизъюнктному** с
`LifecycleEventKind` из `02` (`session_created`, `state_changed`, `checkpoint`,
`session_completed`, `session_failed`, `session_cancelled`, `session_expired`, `resumed`):
ни одно имя не пересекается. Аудит **расширяет** модель событий отдельной схемой, а не
переопределяет lifecycle-события (`02`/LC-10). Форма `payload` выбирается по `kind`
(`oneOf` в схеме).

**AU-3 (MUST).** Аудит-события **не являются переходами FSM** и **не меняют** свёрнутое
состояние сессии. Свёртка состояния (`02` §3) MUST трактовать любой аудит-`kind` как
немаркерный шум и **пропускать** его при вычислении состояния — ровно так же, как она
пропускает операционные маркеры `checkpoint`/`resumed` (`02`/LC-14). Состояние
по-прежнему определяется **только** lifecycle-событиями и их таблицей переходов (`02`/LC-3).

> Замечание (сшивка с `02`). Информативный псевдокод свёртки в `02` §3 перечисляет лишь
> восемь lifecycle-`kind` и отвергает прочие как недопустимый переход. Нормативно
> действуют `02`/LC-3, LC-14 и AU-3: реализация свёртки, встретив аудит-`kind`, MUST его
> пропустить (не отвергать). Приведение информативного псевдокода `02` к этому поведению —
> задача ревизии, затрагивающей `02` (перенос в Pending Work).

## 2. Таксономия событий

**AU-4 (MUST).** Множество аудит-`kind` — закрытый enum из тринадцати значений
(`schema/audit-event.json`, `$defs/AuditEventKind`); расширение — только новой ревизией
спеки. Колонка «Продюсер» — **концептуальная роль источника** события
(`runtime`/`tool`/`user`/`agent`), а **не** значение `actor.type` конверта. `actor.type`
берёт закрытый enum lifecycle-модели ({`user`, `agent`, `system`}, `02`/LC-10) и связан с
ролью маппингом: `user`→`user`, `agent`→`agent`, а серверные роли `runtime` и `tool` пишутся
в конверт как `actor.type = system` (`runtime` vs `tool` — уровень источника внутри рантайма,
не самостоятельный тип actor'а). Примеры §Примеры этот маппинг соблюдают.

| `kind`                    | Продюсер  | Когда возникает                                            | Обязательный `payload`                     |
|:--------------------------|:----------|:----------------------------------------------------------|:-------------------------------------------|
| `intent_extracted`        | agent     | распознано намерение из ввода                              | `intent` (`confidence?`)                   |
| `clarification_asked`     | runtime   | задан уточняющий вопрос (`03`/CP-24)                       | `clarification_id`, `kind`                 |
| `clarification_answered`  | user      | получен ответ на уточнение (`03`/CP-26)                    | `clarification_id`, `status`               |
| `plan_created`            | runtime   | выбран/инстанцирован сценарий (`04`/SC-1)                  | `scenario` (`step_count?`)                 |
| `step_started`            | runtime   | начато исполнение шага сценария                            | `step_id`, `kind`                          |
| `step_skipped`            | runtime   | шаг пропущен: `when` ложно (`04`/SC-12)                    | `step_id` (`reason?`)                      |
| `tool_prepared`           | tool      | завершена фаза prepare write-инструмента (`01`/`03`)       | `tool`, `args_digest`                      |
| `approval_requested`      | runtime   | открыт гейт подтверждения (`03`/CP-21)                     | `tool`, `governance`                       |
| `approval_resolved`       | user      | гейт разрешён — accept/decline/revoke (`03`/CP-21)         | `decision`, `governance`                   |
| `tool_committed`          | tool      | применена фаза commit write-инструмента                   | `tool`, `idempotency_key`                  |
| `tool_failed`             | tool      | вызов инструмента упал (prepare или commit)               | `tool`, `error_code`                       |
| `compensation_executed`   | tool      | выполнена компенсация шага (saga, `04`/SC-22)              | `step_id`, `tool`                          |
| `budget_exceeded`         | runtime   | исчерпан бюджет прогона (шаги/токены/стоимость)            | `metric`, `limit`                          |

**AU-5 (MUST).** `intent_extracted.payload` = `{intent, confidence?}`: `intent` — машинный
тип намерения; `confidence` ∈ [0..1]. Сырой текст ввода (`source_text`) в аудит **не
попадает** (AU-15).

**AU-6 (MUST).** `clarification_asked.payload` = `{clarification_id, kind}`, где `kind` ∈
{`confirm`, `select`, `multi_select`, `text`} (`$ref` на `clarification.json`);
`clarification_answered.payload` = `{clarification_id, status}`, где `status` ∈
{`answered`, `declined`, `cancelled`} (`$ref` на `clarification.json`). Само **значение**
ответа (`value`) может быть ПДн и в аудит **не кладётся** (AU-15) — фиксируется только
исход.

**AU-7 (MUST).** `plan_created.payload` = `{scenario, step_count?}`: `scenario` —
дескриптор `<ns>.<name>@<int-версия>` (`04`/SC-1). Событие открывает исполнение по
декларативной цепочке шагов.

**AU-8 (MUST).** `step_started.payload` = `{step_id, kind}`, `kind` ∈ {`llm`, `tool`,
`elicitation`} (`04`/SC-2); `step_skipped.payload` = `{step_id, reason?}` фиксирует
ветвление сценария при ложном `when` (`04`/SC-12). Значения выражения `when` не логируются.

**AU-9 (MUST).** `tool_prepared.payload` = `{tool, args_digest, step_id?}`: событие
завершения фазы prepare (`01`/TCP-16). `args_digest` — дайджест доменных аргументов
превью, `sha256(JCS(args))` — **тот же** дайджест, что confirmation-токен несёт как
`preview_digest` (`01`/TCP-17, `03`/CP-7).

**AU-10 (MUST).** `approval_requested.payload` = `{tool, governance, args_digest?}`;
`approval_resolved.payload` = `{decision, governance}`, где `decision` ∈ {`accept`,
`decline`, `edit`, `revoke`} (`$ref` на `confirmation.json#/$defs/Decision`). Оба несут
`governance` = `{decided_by, decided_at, scope, expires_at}` **ссылкой** на
`confirmation.json#/$defs/GovernanceMetadata` — форвард-референс `03`/CP-21 (MUST не
копировать). Эти аудит-события — governance-слой («кто и в каком scope решил»), отличный
от lifecycle-триггеров `approval_required`/`approval_granted`/`approval_declined` из `02`
(те несут переход состояния).

**AU-11 (MUST).** `tool_committed.payload` = `{tool, idempotency_key, step_id?,
args_digest?, result_digest?}`: применённая фаза commit (`01`/TCP-16). `idempotency_key` —
ключ формата `at1:` + sha256hex (`06`, D5); `result_digest` = `sha256(JCS(result))`
фиксирует исход без хранения самих данных. Это **юридически значимая** запись «что именно
исполнено».

**AU-12 (MUST).** `tool_failed.payload` = `{tool, error_code, retryable?}`. Полная модель
ошибок (категории, retryable, маппинг semantic/technical fail) нормируется в
`06_idempotency-errors.md`; здесь фиксируются код причины и обратимость.

**AU-13 (MUST).** `compensation_executed.payload` = `{step_id, tool, idempotency_key?}`:
выполнена saga-компенсация шага после его write-эффекта (`04`/SC-22..SC-23). Компенсации
идемпотентны и исполняются в обратном порядке от точки отказа.

**AU-14 (MUST).** `budget_exceeded.payload` = `{metric, limit, consumed?, currency?}`,
`metric` ∈ {`tokens`, `steps`, `cost`}. При `metric = cost` поле `currency` (ISO 4217)
**REQUIRED**, а `limit`/`consumed` — целые в минорных единицах (AU-16).

## 3. Конверт и маскирование ПДн

**AU-15 (MUST).** `payload` аудит-события MUST быть **уже замаскирован** от персональных
данных вышестоящим шлюзом (AiGateway или аналог — маска ПДн до любого внешнего вызова;
информативно: ADR-020 Flex, `R-00` Грань B). AgenTools **не хранит** немаскированные ПДн:
аудит-payload'ы несут идентификаторы, дайджесты и суммы, но **не** сырой пользовательский
текст (`source_text`, значения ответов уточнений, тела доменных записей в аудит не
логируются — они живут в доменных таблицах потребителя под его политикой хранения).

**AU-16 (MUST).** Денежные суммы в payload'ах — **целые в минорных единицах** (напр.
копейки) + отдельный код валюты (ISO 4217); значения с плавающей точкой **запрещены**.
Причина — детерминизм JCS: ES6-сериализация дробных чисел (§4) — сложная часть алгоритма,
и аудит её избегает, оставаясь воспроизводимым между языками (D5 плана).

## 4. Канонизация (RFC 8785 / JCS)

**AU-17 (MUST).** Каноническая форма любого JSON-значения — для golden-сравнений и для
ключей идемпотентности (`06`) — есть **JSON Canonicalization Scheme, RFC 8785 (JCS)**.
Изложение ниже нормативно достаточно для самостоятельной реализации (по `R-08`: в PHP есть
пакет `mmccook/php-json-canonicalization-scheme`; в Dart готового пакета нет — JCS-подмножество
реализуется самим, D5-fallback). Алгоритм:

1. **Ключи объектов** сортируются как массивы **UTF-16 code units**, сравнение беззнаковое,
   независимое от локали; при совпадении префикса **короче — раньше** (`""` < `"a"` <
   `"aa"` < `"ab"`). Дубли ключей запрещены.
2. **Строки** заключаются в `"..."`; экранирование: `"` → `\"`, `\` → `\\`; предопределённые
   управляющие U+0008/U+0009/U+000A/U+000C/U+000D → `\b`/`\t`/`\n`/`\f`/`\r`; прочие
   управляющие U+0000..U+001F → `\u00hh` (**нижний** регистр hex); остальные code points
   сериализуются **как есть** (в UTF-8), без экранирования не-ASCII.
3. **Числа** сериализуются по ECMAScript (ECMA-262 §7.1.12.1, «Number::toString», алгоритм
   Ryu/V8): целые — без дробной части и без лишних нулей; большие/малые — с экспонентой
   `e+`/`e-`; `4.50` → `4.5`, `1E30` → `1e+30`, `2e-3` → `0.002`. AgenTools **избегает**
   float в аудит-payload (AU-16), опираясь на целочисленно-строковое подмножество.
4. **Литералы** `null`/`true`/`false`; массивы сохраняют порядок; **никаких** незначащих
   пробелов, переводов строк или отступов между токенами.

**AU-18 (MUST).** **Golden-файл** канонизации = JCS-строка (UTF-8) + **ровно один**
завершающий перевод строки `\n` (LF). Сравнение golden — **байт-в-байт**. Кодировка вывода
JCS — UTF-8 без BOM.

**AU-19.** Контрольные пары «вход → канонический выход» (нормативные образцы; они же —
golden-фикстуры `fixtures/golden/jcs/*` в P0.9). Источник вектора №1 — RFC 8785 §3.2.3/§3.2.4
(полный пример сериализации, взят verbatim); векторы №2/№3 — производные, полученные
применением правил §4 (демонстрация сортировки ключей и экранирования без float).

**Вектор №1 — полный пример RFC 8785** (`https://www.rfc-editor.org/rfc/rfc8785#section-3.2.3`).
Вход:

```json
{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"€$\u000F\u000aA'\u0042\u0022\u005c\\\"\/","literals":[null,true,false]}
```

Канонический выход (байт-в-байт, 118 байт UTF-8; здесь без завершающего `\n`):

```
{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
```

**Вектор №2 — сортировка ключей и целые** (правило §4 п.1, п.3). Вход:

```json
{"b": 1, "a": 2, "aa": 3}
```

Канонический выход:

```
{"a":2,"aa":3,"b":1}
```

**Вектор №3 — экранирование строк и не-ASCII** (правило §4 п.2). Вход:

```json
{"emoji": "€", "ctrl": "\t\n", "q": "a\"b\\c"}
```

Канонический выход (не-ASCII `€` — как есть в UTF-8; управляющие → `\t`/`\n`; `"`/`\`
экранированы):

```
{"ctrl":"\t\n","emoji":"€","q":"a\"b\\c"}
```

## 5. Трассировка (W3C Trace Context)

**AU-20 (MAY).** Конверт MAY нести опциональный блок `trace` с полями `traceparent` и
`tracestate` ([W3C Trace Context](https://www.w3.org/TR/trace-context/)) — для сшивки
аудита с observability-стеком потребителя. Поля **зарезервированы**: AgenTools их не
интерпретирует и не переопределяет их формат, лишь переносит. Отсутствие `trace` — норма.

## 6. Хранение (retention)

**AU-21 (SHOULD).** События write-действий (`tool_committed` и связанные с ними
`approval_resolved`) SHOULD храниться **не меньше** срока хранения самой доменной записи,
которую они породили (юридическая прослеживаемость). Конкретный срок, транспорт доставки в
observability-стек и схемы БД — **вне** спеки (ответственность потребителя, D13 плана).

---

## Примеры

Все примеры используют сквозную сессию `sess_crm_report_42` (сценарий «отчёт о проделанной
работе», см. `01`–`04`). По одному событию на каждый `kind` таксономии §2.

### Пример 1 — `intent_extracted`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A0B1C2D3E4F5G6H7J8K9",
  "session_id": "sess_crm_report_42",
  "seq": 2,
  "ts": "2026-07-10T09:00:01Z",
  "actor": { "type": "agent", "id": "flex_report_agent" },
  "kind": "intent_extracted",
  "payload": { "intent": "crm.report.create", "confidence": 0.94 },
  "spec_rev": "2026-07-draft"
}
```

### Пример 2 — `clarification_asked`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A1B2C3D4E5F6G7H8J9K0",
  "session_id": "sess_crm_report_42",
  "seq": 3,
  "ts": "2026-07-10T09:00:03Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "clarification_asked",
  "payload": { "clarification_id": "clr_period", "kind": "select" },
  "spec_rev": "2026-07-draft"
}
```

### Пример 3 — `clarification_answered`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A2B3C4D5E6F7G8H9J0K1",
  "session_id": "sess_crm_report_42",
  "seq": 4,
  "ts": "2026-07-10T09:00:07Z",
  "actor": { "type": "user", "id": "user_128" },
  "kind": "clarification_answered",
  "payload": { "clarification_id": "clr_period", "status": "answered" },
  "spec_rev": "2026-07-draft"
}
```

### Пример 4 — `plan_created`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A3B4C5D6E7F8G9H0J1K2",
  "session_id": "sess_crm_report_42",
  "seq": 5,
  "ts": "2026-07-10T09:00:08Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "plan_created",
  "payload": { "scenario": "crm.daily_report@1", "step_count": 3 },
  "spec_rev": "2026-07-draft"
}
```

### Пример 5 — `step_started`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A4B5C6D7E8F9G0H1J2K3",
  "session_id": "sess_crm_report_42",
  "seq": 6,
  "ts": "2026-07-10T09:00:09Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "step_started",
  "payload": { "step_id": "create_report", "kind": "tool" },
  "spec_rev": "2026-07-draft"
}
```

### Пример 6 — `step_skipped`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A5B6C7D8E9F0G1H2J3K4",
  "session_id": "sess_crm_report_42",
  "seq": 7,
  "ts": "2026-07-10T09:00:09Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "step_skipped",
  "payload": { "step_id": "notify_manager", "reason": "when_false" },
  "spec_rev": "2026-07-draft"
}
```

### Пример 7 — `tool_prepared`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A6B7C8D9E0F1G2H3J4K5",
  "session_id": "sess_crm_report_42",
  "seq": 8,
  "ts": "2026-07-10T09:00:11Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "tool_prepared",
  "payload": {
    "step_id": "create_report",
    "tool": "crm_report_create",
    "args_digest": "sha256:3f2a1c0b9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b"
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 8 — `approval_requested`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A7B8C9D0E1F2G3H4J5K6",
  "session_id": "sess_crm_report_42",
  "seq": 9,
  "ts": "2026-07-10T09:00:12Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "approval_requested",
  "payload": {
    "tool": "crm_report_create",
    "args_digest": "sha256:3f2a1c0b9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b",
    "governance": {
      "decided_by": "user_128",
      "decided_at": "2026-07-10T09:00:12Z",
      "scope": "crm.report.create",
      "expires_at": "2026-07-10T09:15:12Z"
    }
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 9 — `approval_resolved`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A8B9C0D1E2F3G4H5J6K7",
  "session_id": "sess_crm_report_42",
  "seq": 10,
  "ts": "2026-07-10T09:00:20Z",
  "actor": { "type": "user", "id": "user_128" },
  "kind": "approval_resolved",
  "payload": {
    "decision": "accept",
    "governance": {
      "decided_by": "user_128",
      "decided_at": "2026-07-10T09:00:20Z",
      "scope": "crm.report.create",
      "expires_at": "2026-07-10T09:15:12Z"
    }
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 10 — `tool_committed`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3A9B0C1D2E3F4G5H6J7K8",
  "session_id": "sess_crm_report_42",
  "seq": 11,
  "ts": "2026-07-10T09:00:21Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "tool_committed",
  "payload": {
    "step_id": "create_report",
    "tool": "crm_report_create",
    "idempotency_key": "at1:9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b",
    "args_digest": "sha256:3f2a1c0b9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b",
    "result_digest": "sha256:7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d"
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 11 — `tool_failed`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3AAB1C2D3E4F5G6H7J8K9",
  "session_id": "sess_crm_report_42",
  "seq": 12,
  "ts": "2026-07-10T09:00:22Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "tool_failed",
  "payload": {
    "tool": "crm_report_create",
    "error_code": "agentools.unavailable.upstream_timeout",
    "retryable": true
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 12 — `compensation_executed`

```json
// example: audit-event.json
{
  "event_id": "01J9Z3ABB2C3D4E5F6G7H8J9K0",
  "session_id": "sess_crm_report_42",
  "seq": 13,
  "ts": "2026-07-10T09:00:24Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "compensation_executed",
  "payload": {
    "step_id": "create_report",
    "tool": "crm_report_delete",
    "idempotency_key": "at1:1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f"
  },
  "spec_rev": "2026-07-draft"
}
```

### Пример 13 — `budget_exceeded`

Денежная метрика: `limit`/`consumed` — целые в минорных единицах (копейки), `currency`
обязателен при `metric = cost` (AU-14, AU-16).

```json
// example: audit-event.json
{
  "event_id": "01J9Z3ACB3C4D5E6F7G8H9J0K1",
  "session_id": "sess_crm_report_42",
  "seq": 14,
  "ts": "2026-07-10T09:00:25Z",
  "actor": { "type": "system", "id": "runtime" },
  "kind": "budget_exceeded",
  "payload": { "metric": "cost", "limit": 50000, "consumed": 50120, "currency": "RUB" },
  "spec_rev": "2026-07-draft"
}
```
