<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-18
inputs_sha256: 70a6da549782f2d2c7684b35f7de73e3bc3fb57d68ae48bfbbd699a29a72068c
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | 0.41.5 |
| Status | 🟡 In progress |
| Last Updated | 2026-09-18 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Спека v0.1-draft: монорепо, нормативные документы, JSON Schema, fixtures | 7/11 | 🟠 Done with deviations |
| P1 | A0: ре-верификация финала MCP 2026-07-28 + пин коммита и `schema.json` | 3/3 | 🟢 Done |
| P10 | Перенос в ERP-контур + анализ offline-разрыва (отчёт применимости) | 0/6 | ⬜ Not started |
| P11 | Release & Hygiene: чистый публичный пакет и воспроизводимый кандидат | 0/4 | ⬜ Not started |
| P2 | A1: bakeoff генераторов (терпимость к незнакомым ключевым словам) + транспорт Dart | 3/5 | 🟠 Done with deviations |
| P3 | A2: авторинг ревизии `2026-08` (modern-only, идентификаторы правил) | 23/25 | 🟠 Done with deviations |
| P4 | A3: тест-векторы под все правила + двунаправленный линтер traceability | 21/21 | 🟢 Done |
| P5 | B: аудит согласования (гейт GREEN, блокирует реализацию) | 3/3 | 🟢 Done |
| P6 | Conformance: harness-протокол, раннер, baseline, четырёхслойный CI | 9/9 | 🟢 Done |
| P7 | PHP SDK `agentools/sdk` — зелёный на векторах уровня ядра | 2/12 | 🟡 In progress |
| P8 | Dart SDK `agentools` — зелёный на тех же векторах | 0/13 | ⬜ Not started |
| P9 | Вертикальный срез в CRM: сквозной сценарий с записью в БД | 0/7 | ⬜ Not started |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Bootstrap монорепо + spec_lint | 🟢 Done | 1970-01-01 |
| P0.2 | Research sweep #2: пины библиотек PHP/Dart (JCS, JSON Schema, ULID) | 🟢 Done | 1970-01-01 |
| P0.3 | 01_tool-contract-profile.md + схемы | 🟠 Done with deviations | 1970-01-01 |
| P0.4 | 02_action-lifecycle.md + схемы | 🟠 Done with deviations | 1970-01-01 |
| P0.5 | 03_confirmation-protocol.md + схемы | 🟢 Done | 1970-01-01 |
| P0.6 | 04_scenario-schema.md + схемы + грамматика выражений | 🟢 Done | 1970-01-01 |
| P0.7 | 05_audit-events.md + схемы + канонизация JCS | 🟠 Done with deviations | 1970-01-01 |
| P0.8 | 06_idempotency-errors.md + схемы | 🟠 Done with deviations | 1970-01-01 |
| P0.9 | Fixtures: формат, 15 векторов, golden-файлы | 🟢 Done | 1970-01-01 |
| P0.10 | 00_overview: глоссарий, маппинг D7, кросс-ревью спеки | 🟢 Done | 1970-01-01 |
| P0.11 | Vault-синк: контрольная запись о спеке в 00_MASTER_PLAN | 🟢 Done | 1970-01-01 |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Пин RC-снимка MCP 2026-07-28: коммит спеки + байты `schema.json` | 🟢 Done | 1970-01-01 |
| P1.2 | Построчная сверка нормативного среза `R-11` с опубликованным финалом | 🟢 Done | 1970-01-01 |
| P1.3 | Реконсиляция `DR-01` дельтами + вердикт гейта для фазы P3 | 🟢 Done | 1970-01-01 |

## Phase P10

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P10.1 | Re-сверка живого состояния ERP-контура + Q-17 предметно + пакет решения владельцу | ⬜ Not started | — |
| P10.2 | Интеграционный контракт INT-002: cross-plan gate-таблица, карта портов, реконсиляция канонов R1–R10 | ⬜ Not started | — |
| P10.3 | Адаптеры поведения в доме переноса: `LlmPort`, `ToolPort` с tier-политикой, доставка, `Clock` | ⬜ Not started | — |
| P10.4 | Сторы персистентности поверх хранилища контура + прогон эквивалентности векторов ядра | ⬜ Not started | — |
| P10.5 | Сценарии из канона продукта + продуктовые векторы + доказательство поведения через разрыв связи | ⬜ Not started | — |
| P10.6 | Анализ offline-разрыва + отчёт применимости — гейт фазы и выход плана | ⬜ Not started | — |

## Phase P11

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P11.1 | Истина идентичности и публикационной поверхности | ⬜ Not started | — |
| P11.2 | Явная публичная API-поверхность и документация | ⬜ Not started | — |
| P11.3 | Воспроизводимый clean-checkout quality gate | ⬜ Not started | — |
| P11.4 | Release candidate и независимый consumer smoke | ⬜ Not started | — |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Корпус проб bakeoff: 10 конструкций 2020-12 + шкала вердиктов | 🟢 Done | 1970-01-01 |
| P2.2 | Прогон PHP-кандидатов по корпусу проб | 🟢 Done | 1970-01-01 |
| P2.3 | Прогон Dart-кандидатов по корпусу проб | 🟢 Done | 1970-01-01 |
| P2.4 | Транспорт Dart: `mcp_dart` prerelease vs тонкий клиент — smoke-прототип | 🟠 Done with deviations | 1970-01-01 |
| P2.5 | Сводный вердикт A1: DR-02 (ключевые слова, кодогенерация, транспорт) | 🟠 Done with deviations | 1970-01-01 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Каркас ревизии `2026-08`: раскладка, README со статусом, профиль линтера + closer | 🟢 Done | 1970-01-01 |
| P3.2 | `00_overview.md`: глоссарий, RFC 2119, уровни L1–L3, side_effect, «out of scope by design» | 🟢 Done | 1970-01-01 |
| P3.3 | `01_extension-identity.md`: SEP-2133, `dev.agentools/core`, грамматика ключей, negotiation | 🟢 Done | 1970-01-01 |
| P3.4 | `02_tool-contract-profile.md`: профиль, `descriptor_digest`, `content_origin`, схемная гигиена | 🟢 Done | 1970-01-01 |
| P3.5 | `03_action-lifecycle.md`: журнал-истина, concurrency, эпоха, снапшот, хеш-цепочка | 🟢 Done | 1970-01-01 |
| P3.6 | `04_confirmation.md`: Policy/Protocol Layer, токен, решения, TTL, dual control, аудит решения | 🟢 Done | 1970-01-01 |
| P3.7 | `05_clarification-mrtr.md`: уточнения и единственный биндинг MRTR | 🟢 Done | 1970-01-01 |
| P3.8 | `06_scenarios.md` + `schema/expression.md`: грамматика, только AST, лимиты, saga | 🟢 Done | 1970-01-01 |
| P3.9 | `07_long-operations.md`: tasks как факультативная возможность, оба пути | 🟢 Done | 1970-01-01 |
| P3.10 | `08_audit-observability.md`: таксономия аудита, JCS, span links, стоимость, baggage | 🟢 Done | 1970-01-01 |
| P3.11 | `09_idempotency-errors.md`: ключ+fingerprint, три состояния, коды, открытые перечисления, must-ignore | 🟢 Done | 1970-01-01 |
| P3.12 | `10_security.md`: запрет проброса токенов, тройка принципалов, происхождение аргумента | 🟢 Done | 1970-01-01 |
| P3.13 | `11_conformance-harness.md`: harness-протокол, severity, baseline, CI, golden, lockstep | 🟢 Done | 1970-01-01 |
| P3.14 | `rules.json`: генератор индекса правил + уровни + зелёный линтер (гейт фазы) | 🟢 Done | 1970-01-01 |
| P3.15 | Гигиена бухгалтерии фазы P2 по находкам её аудита (F1, F4, F5, F8, F9) | 🟢 Done | 1970-01-01 |
| P3.16 | Отдельный permission-код для SEC-3 | 🟢 Done | 1970-01-01 |
| P3.17 | Namespace inventory + owner-ветка Q-5 | 🟢 Done | 1970-01-01 |
| P3.18 | Измеримый owner-вердикт Q-27 по small-model descriptions | 🟢 Done | 1970-01-01 |
| P3.19 | Повторный A2 после нормативной ремедиации | 🟢 Done | 1970-01-01 |
| P3.20 | Непересекающиеся open-enum схемы clarification/tasks | 🟢 Done | 1970-01-01 |
| P3.21 | Полный `EventKind` и markdown↔schema contract-check | 🟢 Done | 1970-01-01 |
| P3.22 | Нормативный адрес кодов MCP + закрытие долга `OVR-25` | 🟢 Done | 1970-01-01 |
| P3.23 | Повторный A2 после RED-аудита P3 | 🟢 Done | 1970-01-01 |
| P3.24 | Fail-closed open-enum gate + Draft 2020-12 oracle | 🟠 Done with deviations | 1970-01-01 |
| P3.25 | Owner reconciliation F17/Q-28 перед повторным Gate B | 🟠 Done with deviations | 1970-01-01 |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Формат вектора ревизии `2026-08`: три класса, `spec_rule_ids`, `spec_rev`, схемы фикстур | 🟢 Done | 1970-01-01 |
| P4.2 | Векторы ядра: идентичность расширения, профиль инструмента, жизненный цикл, подтверждение, MRTR | 🟢 Done | 1970-01-01 |
| P4.3 | Векторы идемпотентности, ошибок и канонизации (официальные векторы RFC 8785 + вне BMP) | 🟢 Done | 1970-01-01 |
| P4.4 | GREASE-векторы, DoS-пробы схем, вектор двух схем (провод открыт / авторинг закрыт) | 🟢 Done | 1970-01-01 |
| P4.5 | Двунаправленный линтер traceability + отчёт покрытия (гейт фазы) | 🟢 Done | 1970-01-01 |
| P4.6 | Ре-замер must-ignore на ОТКРЫТЫХ схемах + энфорсмент валидатора слоя 1 (F3 + триггер T-2) | 🟢 Done | 1970-01-01 |
| P4.7 | Векторы сценариев, длинных операций и безопасности (документы `06`, `07`, `10`) | 🟢 Done | 1970-01-01 |
| P4.8 | Артефактные векторы конвенций `00` + harness-кейсы `11` (классы `artifact`/`harness`) | 🟢 Done | 1970-01-01 |
| P4.9 | Формальный driver state для behavior-векторов | 🟢 Done | 1970-01-01 |
| P4.10 | Различающие behavior-carriers F1/F2/F4 | 🟢 Done | 1970-01-01 |
| P4.11 | Адресные scenario-carriers F5 | 🟢 Done | 1970-01-01 |
| P4.12 | Static checker/carrier remediation F3/F6/F8 | 🟢 Done | 1970-01-01 |
| P4.13 | Повторный A3 + evidence-map перед Gate B round 2 | 🟢 Done | 1970-01-01 |
| P4.14 | Outer-shape fail-closed open-enum fix-forward | 🟢 Done | 1970-01-01 |
| P4.15 | Typed driver-only contract remediation before W2 | 🟢 Done | 1970-01-01 |
| P4.16 | Harness-owned observational contract и anti-self-report gate | 🟢 Done | 1970-01-01 |
| P4.17 | Замена 38 behavior carriers F9/F11–F14 | 🟢 Done | 1970-01-01 |
| P4.18 | Замена 13 artifact carriers F9/F10/F15 | 🟢 Done | 1970-01-01 |
| P4.19 | Exact 51-rule semantic manifest и повторный A3 | 🟢 Done | 1970-01-01 |
| P4.20 | Semantic preflight corrections перед Gate B round 3 | 🟢 Done | 1970-01-01 |
| P4.21 | CLR-8 raw operation stimulus перед verdict Gate B round 3 | 🟢 Done | 1970-01-01 |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Адверсариальный аудит согласования волны A (вердикт GREEN обязателен) | 🟢 Done | 1970-01-01 |
| P5.2 | Freeze ревизии `2026-08`: owner-гейт Q-5/Q-10, статус, тег, `## 7. Contracts`, уведомление потребителей | 🟢 Done | 1970-01-01 |
| P5.3 | Базовая политика качества пакетов, границы SDK и reconciliation истины | 🟢 Done | 1970-01-01 |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Раннер: harness-протокол, конфигурация, матчер, отчёт, коды возврата | 🟢 Done | 1970-01-01 |
| P6.2 | Executable admission: corpus, oracle isolation, runner-owned probes, corrective re-freeze | 🟢 Done | 1970-01-01 |
| P6.3 | Полный эталонный адаптер: core 121 → extension 92 → full 213 | 🟢 Done | 1970-01-01 |
| P6.4 | Severity из нормативного слова + «Not testable» + baseline с анти-эрозией | 🟢 Done | 1970-01-01 |
| P6.5 | Golden-механика: канонический вид + двухступенчатое сравнение | 🟢 Done | 2026-09-18 |
| P6.6 | Самотест harness-протокола: исполнение класса `harness` | 🟢 Done | 1970-01-01 |
| P6.7 | Четырёхслойный CI одним носителем + optional owner input `DR-02` §6 | 🟢 Done | 1970-01-01 |
| P6.8 | Lockstep: флаг ревизии раннера + сверка манифестов SDK + реестр `sdks.json` | 🟢 Done | 1970-01-01 |
| P6.9 | Гейт фазы: зелёный прогон + заведомо красный мутант + отчёт покрытия по уровням | 🟢 Done | 2026-09-18 |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P7.1 | Скелет пакета: манифест, ре-верифицированные пины, QA-тулинг, политика lock | 🟢 Done | 2026-09-18 |
| P7.2 | Идентичность расширения и negotiation (документ `01`) | 🟢 Done | 2026-09-18 |
| P7.3 | Value objects, валидация против опубликованных схем, JCS, raw-preserving | ⬜ Not started | — |
| P7.4 | Профиль инструмента: дескриптор, `descriptor_digest`, `descriptor_drift`, `content_origin` | ⬜ Not started | — |
| P7.5 | Журнал действия + контракты персистентности (§C1–§C3, §C5–§C7, D13) | ⬜ Not started | — |
| P7.6 | Реестр эффектов + fencing-эпоха исполнителя (§C4) | ⬜ Not started | — |
| P7.7 | Подтверждение: Policy/Protocol Layer, подписанный токен, TTL, dual control, событие решения | ⬜ Not started | — |
| P7.8 | Идемпотентность: ключ + `args_fingerprint`, три состояния, два таймера (§D1–§D3) | ⬜ Not started | — |
| P7.9 | Ошибки и расширяемость: строковые коды, открытые перечисления, must-ignore (§E1–§E3, §E7) | ⬜ Not started | — |
| P7.10 | Сценарии и уточнения: AST-вычислитель с лимитами, saga + `COMPENSATION_FAILED`, MRTR | ⬜ Not started | — |
| P7.11 | Длинные операции, аудит и наблюдаемость, безопасность (документы `07`, `08`, `10`) | ⬜ Not started | — |
| P7.12 | Conformance-адаптер PHP, регистрация SDK, вычисленный уровень — гейт фазы | ⬜ Not started | — |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Скелет ядра: `dart/agentools/`, ре-верифицированные пины, QA-тулинг, механика lock и игнорирования | ⬜ Not started | — |
| P8.2 | Идентичность расширения и negotiation в Dart (документ `01`) | ⬜ Not started | — |
| P8.3 | Value objects, валидация против опубликованных схем, СОБСТВЕННАЯ JCS, raw-preserving | ⬜ Not started | — |
| P8.4 | Профиль инструмента: `descriptor_digest`, `descriptor_drift`, `content_origin` + межъязыковой якорь | ⬜ Not started | — |
| P8.5 | Журнал действия + контракты персистентности в Dart (§C1–§C3, §C5–§C7, D13) | ⬜ Not started | — |
| P8.6 | Реестр эффектов + fencing-эпоха исполнителя в Dart (§C4) | ⬜ Not started | — |
| P8.7 | Подтверждение в Dart: Policy/Protocol Layer, подписанный токен, TTL, dual control, событие решения | ⬜ Not started | — |
| P8.8 | Идемпотентность в Dart: ключ + `args_fingerprint`, три состояния, два таймера (§D1–§D3) | ⬜ Not started | — |
| P8.9 | Ошибки и расширяемость в Dart: строковые коды, открытые перечисления, must-ignore (§E1–§E3, §E7) | ⬜ Not started | — |
| P8.10 | Сценарии и уточнения в Dart: AST-вычислитель с лимитами, saga + `COMPENSATION_FAILED`, MRTR | ⬜ Not started | — |
| P8.11 | Длинные операции, аудит и наблюдаемость, безопасность в Dart (документы `07`, `08`, `10`) | ⬜ Not started | — |
| P8.12 | Транспорт: порт `McpTransport` + пакет-спутник `agentools_mcp_dart`, триггер T-1, находка F7 | ⬜ Not started | — |
| P8.13 | Conformance-адаптер Dart, регистрация SDK, вычисленный уровень — гейт фазы | ⬜ Not started | — |

## Phase P9

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P9.1 | Сверка живого состояния контура Flex на дату исполнения + пакет решения владельцу | ⬜ Not started | — |
| P9.2 | Интеграционный контракт INT-001: карта портов, развод трёх гейтов, реанкор `B-32` П1–П7 | ⬜ Not started | — |
| P9.3 | Адаптеры поведения: `LlmPort` поверх AiGateway, `ToolPort` поверх ToolRegistry, доставка протокольных объектов | ⬜ Not started | — |
| P9.4 | Тенантские сторы персистентности поверх БД + прогон векторов ядра на БД-реализациях | ⬜ Not started | — |
| P9.5 | Доменные инструменты + сценарий среза + продуктовые векторы | ⬜ Not started | — |
| P9.6 | UI-цикл: команда, уточнения, превью, подтверждение | ⬜ Not started | — |
| P9.7 | E2E на dev-стенде + отчёт среза — гейт фазы | ⬜ Not started | — |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status ⛔ Skipped by decision; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · blocked · Layout v2 current-state import from legacy status 🔴 Blocked; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/v2 | plan-design · in-progress · Layout v2 migration activation; source tree archived in artifacts/migration-source-v1. |
| 2026-09-18 | implementation/high | plan-run · in-progress · P6.5 formal reopen: deliver golden-negative adapters byte-drift.php and struct-drift.php |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reopen closure: byte-drift.php + struct-drift.php; golden 9/9 ref; byte/struct negative proofs. |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P6.9 gate close: rerun Validation after P6.5 reopen adapters |
| 2026-09-18 | implementation/high | plan-run · closed-green · ГЕЙТ P6: PASS; item 80cac19; 213/213 ref, 6/6 negative proofs, levels report deterministic |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/medium | plan-run · in-progress · P7.1 reconciliation: restore extra.agentools.spec_rev and re-verify pins |
| 2026-09-18 | implementation/medium | plan-run · closed-green · Reconciliation: spec_rev 2026-08; pins re-verified 2026-09-18; item 7282fbd |
| 2026-09-18 | implementation/medium | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/medium | plan-run · in-progress · P7.1 attempt 2: durable run_id for terminal close |
| 2026-09-18 | implementation/medium | plan-run · closed-green · Reconciliation: spec_rev 2026-08; pins re-verified 2026-09-18; item 7282fbd |
| 2026-09-18 | implementation/medium | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.2 reconciliation: per-item Validation on HEAD, anchor c690a67 |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor c690a67: Identity EXT-* docblocks; negative keys ForeignVendorKey/VersionedDataKey/InvalidVendorKey; vectors extension-001 extidentity-001/002 extnegotiation-001/002; phpunit default alias |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- P0.3: known deviation
- P0.4: known deviation
- P0.7: known deviation
- P0.8: known deviation
- P2.4: known deviation
- P2.5: known deviation
- P3.24: known deviation
- P3.25: known deviation
