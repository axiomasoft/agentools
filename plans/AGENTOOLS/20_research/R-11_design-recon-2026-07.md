# R-11 — Design-recon: разведка под пересборку архитектуры (modern-only)

> Метод: research-fanout `wf_2f0f5053-b8a` — 12 тем × (research sonnet/high + адверсариальный verify sonnet/high) = 24 агента,
> 467 tool-вызовов, 1.72M токенов, первоисточники дочитывались через WebFetch. Дата: 2026-07-26.
> Директива владельца: неприкасаемого кода нет; legacy как понятия нет; ресурсы не экономим.
> Выход разведки → решения в `10_decisions/DR-01_architecture-2026-08.md`.
> Факты без источника — `[UNVERIFIED]`.

---

## 0. Как читать

Здесь только факты и их источники. Что из них следует — в `DR-01`. Разделы соответствуют темам разведки.
В конце каждого раздела — что verify-волна не подтвердила.

---

## 1. MCP 2026-07-28 — нормативный срез

**Статус.** На 26.07.2026 ревизия — Release Candidate, финал 28.07.2026. Все цитаты ниже — со страниц
`/specification/draft/**`, т.е. из ещё не опубликованного финала. Verify-волна отдельно это подчеркнула:
подавать содержимое draft как свершившийся факт нельзя, **обязательна ре-верификация после 28.07**.

**Statelessness (ядро).**
- «A server processes each request independently; no state should be inferred from previous requests, even those on the same connection or stream».
- «Servers SHOULD NOT require that a client reuse the same connection or process to perform related operations».
- «State that needs to span multiple requests (e.g., long-running tasks, application-level handles) **MUST be referenced by an explicit identifier the client passes on each request**».
- «an open connection, such as a STDIO process, is not a conversation or session».
- Серверам, которым нужно cross-call состояние: «explicit, **server-minted handles passed as ordinary tool arguments**».
[src: https://modelcontextprotocol.io/specification/draft/basic/index · https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http]

**Per-request `_meta` (таблица нормативная).**

| Ключ | Тип | Required |
|:--|:--|:--|
| `io.modelcontextprotocol/protocolVersion` | string (напр. `"2026-07-28"`) | **Yes** |
| `io.modelcontextprotocol/clientCapabilities` | ClientCapabilities | **Yes** |
| `io.modelcontextprotocol/clientInfo` | Implementation | No (но SHOULD слать на каждом запросе) |
| `io.modelcontextprotocol/logLevel` | LoggingLevel | No |

Отсутствие обязательного поля = malformed: сервер MUST вернуть `-32602`, на HTTP — `400 Bad Request`.
На результате сервер SHOULD класть `io.modelcontextprotocol/serverInfo`. Оговорка безопасности:
`clientInfo`/`serverInfo` самозаявляемы и не верифицируются — «SHOULD NOT rely on them for security decisions».
[src: https://modelcontextprotocol.io/specification/draft/basic/index]

**`server/discover`.** «Servers MUST implement it… Clients MAY call it before any other request… but are not required to».
Ответ: `supportedVersions`, `capabilities` (включая `extensions`), `_meta['io.modelcontextprotocol/serverInfo']`,
`instructions`, кэширование через `ttlMs`/`cacheScope`. На stdio — рекомендованная проба эры.
[src: https://modelcontextprotocol.io/specification/draft/server/discover]

**`resultType`.** Обязателен в каждом результате: `"complete"` | `"input_required"`. Незнакомое значение клиент
MUST считать невалидным. Отсутствие поля трактуется как `"complete"`.

**Коды ошибок — политика выделения диапазона.**
- `-32000..-32019` — legacy; «New codes MUST NOT be allocated in this sub-range».
- `-32020..-32099` — **зарезервировано за спекой MCP**; «Implementations MUST NOT emit any code from this sub-range that is not defined by this specification».
- Определены: `-32020` HeaderMismatch, `-32021` MissingRequiredClientCapability, `-32022` UnsupportedProtocolVersion.
- Ретировано и запрещено: `-32002` (→ `-32602`), `-32042` (было только в 2025-11-25).
- Новые прикладные коды SHOULD размещать **вне** JSON-RPC reserved range `-32768..-32000`.
[src: https://modelcontextprotocol.io/specification/draft/basic/index]

**Транспорт.** `Mcp-Session-Id` удалён полностью; GET-стрим-эндпоинт удалён; SSE-resumability (Last-Event-ID)
убрана — «A broken response stream loses the in-flight request; clients MUST re-issue it as a new request with a
new request ID». `tools/list`/`resources/list`/`prompts/list` больше не варьируются per-connection.
Origin: сервер MUST валидировать, при невалидном — `403`. Локально — SHOULD слушать только `127.0.0.1`.
[src: https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http]

**Схемы инструментов (SEP-2106).** `inputSchema`/`outputSchema` допускают любые ключевые слова JSON Schema
2020-12; `structuredContent` — любое JSON-значение; MUST NOT автодереференсить сетевые `$ref` по умолчанию;
SHOULD ограничивать глубину/число подсхем/бюджет времени на `anyOf`/`oneOf`/`allOf` (защита от DoS).

**Авторизация.** Для HTTP — OAuth 2.1 + PRM (RFC 9728) + Client ID Metadata Documents вместо DCR +
resource-параметр (RFC 8707) + валидация `iss` (RFC 9207). Для stdio — «SHOULD NOT follow this [auth]
specification, instead retrieve credentials from the environment».

**W3C Trace Context.** `traceparent`/`tracestate`/`baggage` в `_meta` — **явное исключение** из правила
префикса, зарезервированы под OpenTelemetry; формат значений MUST по W3C, если присутствуют.

---

## 2. Extensions Track — как оформить своё расширение

- **SEP-2133 «Extensions», статус Final.** Вводит отдельный тип SEP — Extensions Track.
- **Идентификатор:** `{vendor-prefix}/{extension-name}`, напр. `io.modelcontextprotocol/oauth-client-credentials`,
  `com.example/websocket-transport`. «The names follow the same rules as the `_meta` keys… except that the prefix
  is **mandatory**». Для третьих сторон: «the vendor prefix **SHOULD** be a reversed domain name that the
  extension author owns or controls».
- **Объявление:** расширения живут в `capabilities.extensions` — мапе `extension-id → settings`. В modern-эре
  это поле лежит **внутри per-request `_meta`** (`io.modelcontextprotocol/clientCapabilities.extensions`), а не в
  одноразовом `initialize`. Пустой объект настроек = поддержка без параметров.
- **Graceful degradation (дословно):** «If one party supports an extension but the other does not, the supporting
  party MUST either revert to core protocol behavior or reject the request with an appropriate error if the
  extension is mandatory». + «Extensions SHOULD document their expected fallback behavior».
- **Версионирование:** «Breaking changes MUST use a new identifier, e.g. `…-v2`. A breaking change is any
  modification that would cause existing compliant implementations to fail… including: removing or renaming
  fields, changing field types, altering the semantics of existing behavior, or adding new required fields».
- **Документация:** «Extension specifications MUST use the same language as the core specification (RFC2119/BCP14)
  and SHOULD be worded as if they were part of the core specification».
- **SDK:** «Where implemented, extensions MUST be disabled by default and require explicit opt-in»; поддержка
  расширений не требуется для 100% conformance и SDK-тиров.
- **Таксономия:** Official (репозитории `modelcontextprotocol/ext-*`, обязательная Apache-2.0, CLA в Linux
  Foundation), Experimental (`experimental-ext-*`), и **Unofficial** — «not recognized by MCP governance and may
  be introduced and governed by developers outside the MCP organization». Юридические требования SEP-2133
  (Apache-2.0/CLA/trademark) относятся к Official, не к Unofficial.
- Публичного реестра сторонних расширений **нет**.
[src: https://modelcontextprotocol.io/seps/2133-extensions · https://modelcontextprotocol.io/extensions/overview]

**Грамматика `_meta`-ключа (нормативно, действует и в 2025-11-25, и в 2026-07-28):** префикс опционален; если
задан — MUST быть **серией labels через точку** с завершающим `/`; labels начинаются с буквы, заканчиваются
буквой/цифрой, внутри допустимы дефисы. «Implementations SHOULD use reverse DNS notation». Зарезервирован любой
префикс, где **второй** label = `modelcontextprotocol` или `mcp`. Имя после `/`: начинается и заканчивается
алфанумериком, внутри — дефисы/подчёркивания/точки/алфанумерики.

**Найдено отсутствие нормы:** явной формулировки «получатель MUST игнорировать неизвестные `_meta`-ключи» в
ядре спеки **нет** — она выводится только косвенно из открытой сигнатуры `[key: string]: unknown`.

**Совместимость эр:** Legacy-клиент ↔ Modern-сервер — Fails («Legacy clients have no fall-forward mechanism»);
Modern-клиент ↔ Legacy-сервер — Fails.

---

## 3. MRTR и elicitation в modern-эре

- **SEP-2322.** «The previous pattern of server-initiated requests is no longer supported. **This is a breaking
  change.**» Вместо `elicitation/create`, `sampling/createMessage`, `roots/list` как отдельных серверных запросов —
  результат `InputRequiredResult` (`resultType: "input_required"`) с `inputRequests` (мапа ключ → запрос) и
  опциональным `requestState`.
- `requestState` — непрозрачная строка: «Clients MUST NOT inspect, parse, modify…».
- Ретрай: «The JSON-RPC id MUST be different between the initial request and the retry».
- Разрешено только на `prompts/get`, `resources/read`, `tools/call`; «Servers MUST NOT send InputRequiredResult
  responses on any other client requests».
- **Число раундов не ограничено спекой** — сервер MAY повторять, клиент MAY не ретраить вовсе.
- Elicitation: form- и url-режимы сохранены, схемы `ElicitRequest`/`ElicitResult` структурно не изменены.
  Удалены `notifications/elicitation/complete` и `elicitationId` url-режима. SEP-1330 (4 варианта enum:
  titled/untitled × single/multi) — Final, вошёл без изменений.
- Form-mode ограничен **плоским объектом с примитивными свойствами** — вложенные объекты и массивы объектов
  туда не помещаются.
- Рекомендация спеки по `requestState` (non-normative, раздел Stateful Tools): integrity-protected blob с
  принципалом, TTL и дайджестом исходных параметров; «A call against an expired or unknown handle should return
  a **tool execution error** that says so, so the model can recover by creating a new one».
[src: https://modelcontextprotocol.io/specification/draft/basic/patterns/mrtr · /draft/client/elicitation]

---

## 4. Tasks и progress

- **SEP-2663.** Tasks выведены из ядра в расширение `io.modelcontextprotocol/tasks`. Результат `tools/call`
  может быть `CreateTaskResult` (`resultType: "task"`) с `taskId`/`ttlMs`/`pollIntervalMs`. Статусы:
  `working`, `input_required`, `completed`, `failed`, `cancelled`. Методы `tasks/get`, `tasks/update`,
  `tasks/cancel`. `tasks/result` и `tasks/list` удалены (последний — намеренно, security-by-design).
- Отмена кооперативная: «the server acknowledges the intent but is **not obligated** to stop the work».
- **`notifications/progress` на задачах не поддерживается** — «not supported on tasks in general». Т.е. для
  Task-backed операции «процент выполнения» недостижим, единственный сигнал — смена статуса.
- Асимметрия консистентности: выдача `taskId` strongly-consistent; фактическое применение — eventually.
- **Client-side idempotency key для создания задачи отсутствует** — дедупликация есть только для ключей
  `inputRequests` внутри уже созданной задачи.
- Транспорт: на Streamable HTTP `tasks/get|update|cancel` требуют заголовок `Mcp-Name: <taskId>`.
- **Зрелость:** официальные TS/Python SDK не имеют законченной реализации (issue #2189 «needs decision»);
  документация одновременно ссылается на `experimental-ext-tasks` и показывает карточку `ext-tasks` как
  официальную — признак незавершённого перехода.
[src: https://modelcontextprotocol.io/extensions/tasks/overview]

---

## 5. Расширяемость и forward-compatibility

- **RFC 9110 §5.1 / §16.3** — эталонная формулировка must-ignore: «Other recipients SHOULD ignore unrecognized
  header and trailer fields»; новые поля вводятся без смены версии протокола **только** если их семантика
  позволяет безопасно игнорировать. [src: https://www.rfc-editor.org/rfc/rfc9110.html#section-16.3]
- **JSON-RPC 2.0 не содержит** явного MUST об игнорировании неизвестных членов — это практика, не норма.
  Единственная зарезервированная зона — префикс метода `rpc.`. [src: https://www.jsonrpc.org/specification]
- **Protobuf** сохраняет неизвестные поля при парсинге и повторной сериализации; данные теряются при
  конвертации в JSON или ручной пересборке поле-за-полем вместо `CopyFrom()`/`MergeFrom()`.
  [src: https://protobuf.dev/programming-guides/proto3/]
- **Голый `enum` в JSON Schema делает добавление значения breaking-изменением автоматически.** Рабочий приём —
  «open enum»: композиция `oneOf` из `const`-веток известных значений + fallback-ветка со `string`+`pattern`.
- **Антипаттерн схем:** `additionalProperties: false` во вложенных/переиспользуемых подсхемах (`$defs`, базовые
  типы в `allOf`) ломает будущее добавление опциональных полей; закрывать объект следует на внешнем уровне
  композиции через `unevaluatedProperties: false`.
- **Урок IAB о неиспользуемых точках расширения:** механизмы, которые не используются, отказывают чаще всего
  (прецеденты TLS version intolerance, SNI). Отсюда два правила: (а) GREASE-векторы с первого релиза, (б) не
  вводить extension point «про запас» без хотя бы одного реального потребителя.

---

## 6. Durable-сессии и детерминизм

- **Две несовместимые модели детерминизма.** Temporal — строгий replay кода с ошибкой недетерминизма (требует
  сравнения сгенерированных команд, привязан к рантайму). Restate / DBOS / Inngest / Cloudflare Workflows —
  «журнал есть истина, replay есть проекция»: журналируем шаг и его результат, при retry пропускаем завершённые.
  Вторая модель переносима между языками, первая — нет.
- **Resume перезапускает шаг с начала** во всех разобранных системах (LangGraph explicit). Cloudflare
  формулирует правило: весь код с побочными эффектами живёт **внутри** шага; неидемпотентные вызовы выполняются
  только после проверки, нужна ли операция ещё.
- **Недетерминированный ввод журналируется** (Restate `ctx.rand.uuidv4()` — детерминированный replay-safe
  генератор, результат которого попадает в журнал). Шаги именуются детерминированно, без времени/случайности.
- **Exactly-once честно не даёт никто** на границе произвольного внешнего эффекта. DBOS даёт его только для
  `@DBOS.Transaction`, где checkpoint и эффект коммитятся в одной Postgres-транзакции. Общая гарантия —
  at-least-once до эффекта + never-re-executed после checkpoint.
- **Optimistic concurrency на доменном event-логе не даёт ни одна из систем готовым** — это место, которое
  спека обязана нормировать сама (append с ожидаемым `last_seq`, типизированная ошибка конфликта).
- **Два хранилища, не одно:** durable-состояние сессии (LangGraph checkpointer) и память/контекст агента
  (LangGraph store) — разный жизненный цикл, разные API. Зрелый консенсус — не смешивать.
- **Версионирование событий:** `event_version` + upcasting на чтении, запрет мутации записанных событий.
- **Снапшот отдельно от журнала** (DBOS `workflow_status` + `operation_outputs`; Temporal continue-as-new) —
  иначе resume длинных цепочек становится дорогим.

---

## 7. HITL/approval — что делают зрелые системы и чего они не делают

Разобраны: Temporal (signals/updates), LangGraph (`interrupt` + `Command(resume)`), OpenAI Agents SDK,
Vercel AI SDK, Neuron AI, `laravel/ai` v0.10, Claude Code permission model, фин-практика maker-checker.

**Что подтверждено как общая практика:**
- Разные идентификаторы для «что вызвано» (`tool_call_id`) и «что подтверждается» (`approval.id`) — потому что
  один tool call проходит несколько циклов подтверждения при правке аргументов.
- Precedence-модель политики `deny → ask → allow` с merge между уровнями области видимости (Claude Code).
- Явное предупреждение о гонке «approval уже разрешён, но генерация после него упала» — «не ресабмить те же
  решения повторно» (`laravel/ai`).

**Системные пробелы индустрии (наш дифференциатор):**
1. **TTL ожидающего approval не документирует никто.** LangGraph явно: «waits indefinitely».
2. **Анти-tampering между preview и commit нативно не даёт ни один agent-фреймворк.** Готовый примитив есть
   только у Stripe: «тот же ключ + другие параметры = ошибка».
3. **Four-eyes / maker-checker (proposer ≠ approver) нет ни в одной из шести разобранных реализаций** — только
   single-approver approve/reject.
4. **Таксономии аудита решения** (кто/когда/что именно/на основании чего) как нормативного требования нет
   нигде: `laravel/ai` даёт только событие `ToolApprovalRequested`, остальные — ничего.
5. **`edit` слабо специфицирован даже в свежих реализациях** — заявлен в прозе, без API.

---

## 8. DSL сценариев и язык выражений

**Вердикт: своя закрытая грамматика обоснована фактами, а не ленью.**

| Кандидат | PHP | Dart | Общий conformance | Вывод |
|:--|:--|:--|:--|:--|
| **CEL** | `carthage-software/cel-php` — заявляет full spec compliance | `cel`, `libcel` — **обе partial** (нет timestamp/duration, type-conversion, оператора `type`) | есть у самого CEL | ❌ немедленный дрейф PHP↔Dart на уровне спеки языка |
| **JMESPath** | зрелая | `jmespath` — **заброшен 4 года**, лицензия не указана | jmespath.org/compliance | ❌ |
| **JSONata** | community-порт | community-порт | нет | ❌ |
| **JSON Logic** | 1.25M установок, **лицензия на Packagist не указана** | — | **нет вообще** | ❌ |
| **RFC 9535 JSONPath** | `loilo/jsonpath-php` (99% CTS) | `json_path` (заявляет CTS-target) | **единый официальный CTS** | ✅ единственный годный fallback |

**Безопасность вычислителя.** `CVE-2026-54133` (jmespath.php < 2.9.1, CVSS 9.3) — уязвимость возникла именно
из режима `CompilerRuntime`, компилировавшего выражения в исполняемый PHP-код. Аналог — `CVE-2026-1615` в npm
`jsonpath` (unsafe eval). Ни один из обследованных языков **не задаёт лимитов** (глубина AST, длина выражения,
размер коллекции, бюджет времени) по умолчанию.

**Saga/компенсации.** Ни CNCF Serverless Workflow 1.0.3 (только try/catch/retry), ни AWS ASL (только
Retry/Catch), ни Argo не имеют компенсации как first-class примитива. Единственная нормативная модель —
**BPMN** (`isForCompensation` + boundary compensation event; компенсируются только успешно завершённые
активности, в обратном порядке). Ниша не закрыта рынком.

**Формат ошибок.** CNCF Serverless Workflow 1.0.3 использует **RFC 7807 Problem Details** как нормативную форму
ошибки (`type`/`status`/`instance`/`title`/`detail` + префикс `.../errors/{category}`) — готовый словарь.

**Мелочь для честности:** «Temporal DSL» — сторонний community-сэмпл (`mrsimonemms/temporal-dsl`), не продукт
Temporal Inc.; ссылаться на него как на прецедент кросс-языкового DSL нельзя.

---

## 9. Идемпотентность и exactly-once

- **MCP не содержит механизма идемпотентности/дедупликации tool-вызовов.** Это целиком наш слой.
- **`draft-ietf-httpapi-idempotency-key-header-07`** — три состояния обработки: новый (выполнять), дубликат
  завершённого (вернуть сохранённый результат, «success **or an error**»), конкурентный in-flight (вернуть
  409-эквивалент — **не ждать и не выполнять повторно**). Ошибки: `409` при конкурентном, `422` при том же
  ключе с другим payload, `400` при отсутствующем заголовке.
- **Ключ — клиентский, fingerprint — дополнение.** Серверный детерминированный хеш ловит только точное
  совпадение payload и ломается на легитимных ретраях; ключ выражает **намерение**, fingerprint ловит misuse.
- **TTL дедупликации — функция бизнес-риска, не константа:** Stripe 24 ч, Adyen 7 дней, PayPal до 45 дней для
  refund. И это **другой таймер**, чем TTL токена подтверждения.
- **JCS (RFC 8785):** известное место расхождения реализаций — сортировка ключей выше U+FFFF (суррогатные
  пары). Официальные тест-векторы есть в самом RFC (Appendix) и в `cyberphone/json-canonicalization`. JCS
  намеренно **не делает** Unicode-нормализацию.
- **CBOR deterministic encoding (RFC 8949 §4.2)** даёт тот же детерминизм, но добавляет вторую сериализацию
  без выигрыша для JSON-центричного контракта.
- **Fencing-токен (монотонная эпоха)** — отдельный от идемпотентного ключа механизм: ключ защищает от дублей
  **запроса**, эпоха — от дублей **исполнителя** после failover (паттерн Kleppmann).

---

## 10. Против дрейфа двух SDK

- **Bowtie (JSON Schema)** — протокол **IHOP**: JSON-команды по stdin → harness → JSON-ответы в stdout,
  последовательность `start → (dialect) → run × N → stop`, harness'ы изолированы в OCI-контейнерах. Прямой
  прецедент CLI-адаптера. [src: https://github.com/bowtie-json-schema/bowtie]
- **protobuf conformance** — tester-процесс ↔ testee по stdin/stdout, length-prefixed бинарь; принцип «testee
  реализует только протокол, фикстуры и ожидания у tester».
- **`modelcontextprotocol/conformance` 0.2.0-alpha.9** — стабильные строковые check-ID; severity выводится из
  нормативного слова правила (**MUST → FAILURE, SHOULD → WARNING**); PR #372 ввёл статус **«Not testable»**
  вместо тихого SKIP; traceability YAML check-ID ↔ пункт SEP; `--suite`/`--spec-version`; baseline
  expected-failures (exit 0 только если фактические отказы ⊆ baseline; **устаревшая запись baseline = ошибка**).
- **SEP-1730 SDK tiering, Final:** Tier 1 = 100% применимых conformance-тестов + триаж issue за 2 рабочих дня;
  Tier 2 = 80%; Tier 3 — порога нет. Бейджи вида «90% MCP Compliant». Поддержка расширений в скор не входит.
- **Кодогенерация:** PHP-генератор `wol-soft/php-json-schema-model-generator` требует PHP ≥8.4; на стороне Dart
  зрелого schema-first генератора нет (`json_schema_builder` — только валидация). Полное покрытие draft 2020-12
  ни у одного не подтверждено. `quicktype` — кандидат на bakeoff.
- **Golden-файлы:** канонический вид (UTF-8 без BOM, LF, сортированные ключи, фиксированный отступ) + метаполя
  ревизии; сравнение двухступенчатое — структурное, затем байтовое.

---

## 11. Безопасность — что нормируется контрактом

- **OWASP MCP Top 10:** MCP03 Tool Poisoning (rug pull, schema poisoning, tool shadowing) — контрмерой прямо
  названо «tool description fingerprinting/hashing baseline with session comparison». MCP04 Supply Chain.
  MCP07 Insufficient AuthN/AuthZ — per-tool scope enforcement **на каждом вызове**, не на сессию.
  MCP08 Lack of Audit and Telemetry — «cryptographic hash chaining + signed checkpoints + DLP redaction before write».
- **Официальный MCP registry верифицирует только namespace** (GitHub/DNS/HTTP) — **не подписывает и не пиннит**
  содержимое `server.json`. Гарантию неизменности дескриптора обязан дать слой выше.
- **Token passthrough:** MCP security best practices — «MCP servers MUST NOT accept any tokens that were not
  explicitly issued for the MCP server» (confused deputy).
- **CaMeL (Google DeepMind, 2025)** — против инъекций через результаты инструментов: разделение Privileged LLM /
  Quarantined LLM + capability-based интерпретатор. В **контракте инструмента** из этого выразимо ровно одно —
  метка происхождения данных, включающая изолированный маршрут.
- **Delegation/on-behalf-of** (RFC 8693 Token Exchange + AI-agent drafts) — **не ратифицировано**; фиксировать
  конкретную claim-схему рано.
- **EU AI Act Art. 12** вступает в силу **2 августа 2026** (через 7 дней от даты разведки): требует
  «automatic recording of events over the lifetime of the system», но **намеренно не специфицирует** retention,
  безопасность хранилища и формат.

---

## 12. Наблюдаемость

- **OTel GenAI semantic conventions:** `gen_ai.*` вынесены в отдельный репозиторий `semantic-conventions-genai`;
  на июль 2026 **ни один атрибут не Stable** — все Development. Переименования: `gen_ai.system` →
  `gen_ai.provider.name`, `usage.prompt_tokens`/`completion_tokens` → `input_tokens`/`output_tokens`; переход
  через opt-in `OTEL_SEMCONV_STABILITY_OPT_IN`.
- Номенклатура спанов: `invoke_agent` (верхний уровень) → `execute_tool` (каждый вызов), `gen_ai.tool.name`.
- **Метрики стоимости в OTel GenAI нет вообще** — считать самим.
- **Долгие человеко-гейтированные операции:** практика — не держать span открытым, а связывать span links
  через сохранённый trace-context.
- **W3C Baggage:** лимиты — ≤64 list-members и ≤8192 байт суммарно.

---

## 13. Чего verify-волна не подтвердила (6 из 73 проверок)

| Тема | Что не устояло |
|:--|:--|
| `mcp-extensions-track` | **UNVERIFIED:** цитаты со страниц `/specification/draft/**` точны, но это **черновик**; подавать ревизию 2026-07-28 как свершившийся факт нельзя до 28.07 |
| `protocol-extensibility` | **REFUTED:** формулировка «2026-07-28 — точно таргет AgenTools» противоречит действующему тексту спеки (`TCP-1` фиксирует 2025-11-25). Разворот таргета — решение `DR-01`, а не факт разведки |
| `durable-agent-sessions` | **UNVERIFIED:** гарантия exactly-once у DBOS верна, но причинное объяснение приписано статье, где его нет |
| `workflow-dsl-and-expressions` | **REFUTED:** дата и URL анонса JSONata в AWS Step Functions указаны неверно (факт верен, атрибуция — нет) |
| `idempotency-exactly-once` | **REFUTED ×2:** IETF-драфт связывает `400` **только** с отсутствующим заголовком (не с невалидным); цитаты PayPal взяты с другой страницы, чем указано |
| `multilang-sdk-antidrift` | **REFUTED:** цитата из документации Bowtie приведена как дословная, хотя это пересказ |

Ни одно из опровержений не отменяет содержательного вывода — все касаются атрибуции, дат и статуса черновика.
В `DR-01` эти места сформулированы по фактически подтверждённым источникам.

---

## Sources

**MCP (draft 2026-07-28 и SEP)**
- https://modelcontextprotocol.io/specification/draft/basic/index
- https://modelcontextprotocol.io/specification/draft/basic/versioning
- https://modelcontextprotocol.io/specification/draft/basic/patterns/mrtr
- https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http
- https://modelcontextprotocol.io/specification/draft/server/discover
- https://modelcontextprotocol.io/specification/draft/server/tools
- https://modelcontextprotocol.io/specification/draft/client/elicitation
- https://modelcontextprotocol.io/specification/draft/changelog
- https://modelcontextprotocol.io/specification/draft/deprecated
- https://modelcontextprotocol.io/seps/2133-extensions
- https://modelcontextprotocol.io/seps/1730-sdks-tiering-system
- https://modelcontextprotocol.io/extensions/overview
- https://modelcontextprotocol.io/extensions/tasks/overview
- https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/
- https://github.com/modelcontextprotocol/conformance

**Стандарты и RFC**
- https://www.rfc-editor.org/rfc/rfc9110.html#section-16.3 · https://www.jsonrpc.org/specification
- https://www.rfc-editor.org/rfc/rfc8785 · https://github.com/cyberphone/json-canonicalization
- https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/
- https://www.rfc-editor.org/rfc/rfc9535 · https://www.rfc-editor.org/rfc/rfc7807
- https://www.w3.org/TR/trace-context/ · https://www.w3.org/TR/baggage/
- https://protobuf.dev/programming-guides/proto3/

**Durable / HITL / DSL**
- https://docs.temporal.io · https://docs.restate.dev · https://docs.dbos.dev
- https://developers.cloudflare.com/workflows/ · https://langchain-ai.github.io/langgraph/concepts/persistence/
- https://github.com/laravel/ai/pull/773 · https://serverlessworkflow.io/
- https://docs.aws.amazon.com/step-functions/latest/dg/intrinsic-functions.html

**Conformance / anti-drift**
- https://github.com/bowtie-json-schema/bowtie · https://github.com/protocolbuffers/protobuf/tree/main/conformance
- https://github.com/json-schema-org/JSON-Schema-Test-Suite

**Безопасность / регуляторика**
- https://genai.owasp.org/resource/owasp-top-10-for-agentic-applications-for-2026/
- https://cycode.com/blog/owasp-mcp-top-10/
- https://artificialintelligenceact.eu/article/12/
- https://arxiv.org/abs/2503.18813 (CaMeL)

**Наблюдаемость**
- https://github.com/open-telemetry/semantic-conventions-genai
- https://opentelemetry.io/docs/specs/semconv/gen-ai/
