# INT-002 — автономный референсный вертикальный срез (только репозиторий agentools)

**Статус:** draft (публикуется решением D186; исполняется P9.3–P9.7)  
**Версия:** 1.0.0  
**Дата:** 2026-09-19  
**План:** AGENTOOLS P9 · **Дом кода:** `~/projects/packages/agentools` (D186)  
**Норматив протокола:** ревизия `spec/revisions/2026-08/` (frozen)  
**Заменяет для исполнения P9:** INT-001 (deferred)

> Референсный срез доказывает, что восемь портов SDK и сценарий протокола сходятся **без** зависимости
> на CoreX, FlexCRM, AiGateway, ToolRegistry контура или Laravel-приложение потребителя.
> Продуктовая интеграция — вне scope (D186).

**Опора:** `findings/P9-decouple-corex-2026-09-19.md` · `php/src/**` (порты и in-memory референсы P7)

---

## 1. Карта восьми портов (референсный контур)

Направление зависимости: `php/reference/**` → `php/src/**` (SDK). Внешних composer/path-зависимостей на CoreX/Flex **нет**.

| Порт SDK | Референсный класс (целевой) | Носитель | Примечание |
|:--|:--|:--|:--|
| `EventLog` | `AgenTools\Reference\Persistence\SqliteEventLog` (P9.4) | SQLite `php/var/reference-slice.sqlite` | append-only, `expected_prev_seq`, `prev_hash` |
| `SessionStore` | `AgenTools\Reference\Persistence\SqliteSessionStore` | та же БД + fold | снапшот отдельно от журнала |
| `Clock` | `AgenTools\Reference\Support\InjectableClock` | in-memory | подменяемый для E2E |
| `TokenSigner` | `AgenTools\Reference\Crypto\TenantHmacTokenSigner` | config теста / env | секрет на тенанта, не глобальный |
| `IdempotencyStore` | `AgenTools\Reference\Persistence\SqliteIdempotencyStore` | та же БД | три состояния, два таймера §D1–§D3 |
| `LlmPort` | `AgenTools\Reference\Adapters\ScriptedLlmPort` | stub-ответы из вектора / JSON fixture | **без** сети и без AiGateway |
| `ToolPort` | `AgenTools\Reference\Adapters\RegistryToolPort` | in-process registry | двухуровневый permission-гейт |
| `AuditSink` | `AgenTools\Reference\Audit\CollectingAuditSink` | in-memory / SQLite проекция | единственный путь записи решения §I4 |

**Корень пакета:** `php/reference/` (адаптеры, persistence, demo).  
**Тесты:** `php/tests/reference/**`.

---

## 2. Поведенческие адаптеры (P9.3)

### 2.1 `ScriptedLlmPort`

- Читает scripted-ответ из `llm_stub` вектора или из named fixture в `php/reference/fixtures/llm/`.
- Ошибка «квота» — структурный код `LLM_QUOTA_EXCEEDED` → **tool execution error** SDK, не протокольная.
- Прямых HTTP-вызовов к провайдеру нет; греп по `php/reference` на URL провайдеров = 0.

### 2.2 `RegistryToolPort`

- Единственный in-process реестр инструментов; второй реестр не заводится.
- **Discover:** каталог фильтруется по субъекту (permission set).
- **Invoke:** повторная проверка прав; инструмент вне прав отсутствует в каталоге **и** отвергается при прямом вызове.
- Субъект обязателен: `PrincipalTriple` из SDK Ops; «система без субъекта» → typed error.

### 2.3 Доставка протокольных объектов

- `DeliveryCollector` — in-process подписчик: clarification, preview, deferred-confirmation **как есть** (без переименования полей).
- Тенантная изоляция: подписчик с `tenant_id` A не получает события tenant B (тест).
- Классификация побочного эффекта — только таблица §3 ниже (не локальный словарь).

### 2.4 `InjectableClock`

- Обёртка над `FixedClock` с возможностью advance в тестах.

---

## 3. Классификация побочного эффекта (единственная таблица)

| `side_effect` (ревизия) | Policy hint (срез) | Two-phase |
|:--|:--|:--|
| `read` | нет | нет |
| `draft` | нет | нет |
| `write` | да | да |
| `irreversible` | да + `dual_control` | да |

---

## 4. Персистентность (P9.4)

- SQLite — единственная БД среза; миграции в `php/reference/migrations/`.
- Прогон эквивалентности: conformance adapter с SQLite-портами, `--level L1`, 0 отказов вне baseline.
- In-memory реализации SDK (`php/src/Persistence/*`) — эталон при расхождении.

---

## 5. Сценарий и векторы (P9.5)

- Сценарий «итог дня → черновик → уточнения → превью → запись» на **референсных** инструментах (`read`/`draft`/`write`).
- Продуктовые векторы: `fixtures/product-slice/**` (вне `fixtures/2026-08/**`).
- LLM только scripted (D8). Счётчик запросов подтверждения на полном пути = 1.

---

## 6. Потребитель UI (P9.6) — CLI, не продукт Flex

- `php/bin/reference-slice-demo` (или эквивалент): читает объекты из `DeliveryCollector`, не интерпретирует протокол.
- Нет Reverb, нет Flex UI, нет batch-STT (голос — `Pending Work` / P9.7 optional).
- Три отрицательных: чужой tenant, TTL превью, `edit` → новый prepare.

---

## 7. Гейт фазы (P9.7)

Условия `ГЕЙТ P9` (адаптация DR-01 §K без продуктовой БД Flex):

1. Сценарий доведён до записи в **SQLite** среза (идентификатор записи в отчёте).
2. Шесть отрицательных доказательств фазы (`research/08-…` §9) — на референсном контуре.
3. Продуктовые векторы зелёные (scripted LLM).
4. Прогон L1 на SQLite-адаптере зелёный.
5. Тесты + статанализ `php/` зелёные.
6. `artifacts/P9-slice-report.md` с `ГЕЙТ P9: PASS` и разделом «что НЕ проверялось» (нет Flex, нет CoreX, нет remote CI, нет реальной модели по умолчанию).

**Опционально** (не блокирует PASS): E2E с реальной моделью при `AGENTOOLS_REAL_LLM=1` — фиксируется в отчёте отдельной таблицей.

---

## 8. Явно вне scope

- Любой код в `~/projects/packages/corex`, `~/projects/flexcrm`.
- AiGateway, ADR-020, ADR-022, B-32 как runtime-зависимости.
- INT-001 и предложения дельт Flex — до отдельного решения владельца.
