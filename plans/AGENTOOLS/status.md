<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-19
inputs_sha256: f22d6377cb50d5e83b1e428cfaafc0159cc5cdeb409896e20db80b27c1dc971c
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | 0.41.5 |
| Status | 🟡 In progress |
| Last Updated | 2026-09-19 |

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
| P7 | PHP SDK `agentools/sdk` — зелёный на векторах уровня ядра | 12/12 | 🟢 Done |
| P8 | Dart SDK `agentools` — зелёный на тех же векторах | 13/13 | 🟢 Done |
| P9 | Референсный вертикальный срез: сквозной сценарий с записью (автономный контур) | 6/7 | 🟡 In progress |

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
| P7.3 | Value objects, валидация против опубликованных схем, JCS, raw-preserving | 🟢 Done | 2026-09-18 |
| P7.4 | Профиль инструмента: дескриптор, `descriptor_digest`, `descriptor_drift`, `content_origin` | 🟢 Done | 2026-09-18 |
| P7.5 | Журнал действия + контракты персистентности (§C1–§C3, §C5–§C7, D13) | 🟢 Done | 2026-09-18 |
| P7.6 | Реестр эффектов + fencing-эпоха исполнителя (§C4) | 🟢 Done | 2026-09-18 |
| P7.7 | Подтверждение: Policy/Protocol Layer, подписанный токен, TTL, dual control, событие решения | 🟢 Done | 2026-09-18 |
| P7.8 | Идемпотентность: ключ + `args_fingerprint`, три состояния, два таймера (§D1–§D3) | 🟢 Done | 2026-09-18 |
| P7.9 | Ошибки и расширяемость: строковые коды, открытые перечисления, must-ignore (§E1–§E3, §E7) | 🟢 Done | 2026-09-18 |
| P7.10 | Сценарии и уточнения: AST-вычислитель с лимитами, saga + `COMPENSATION_FAILED`, MRTR | 🟢 Done | 2026-09-18 |
| P7.11 | Длинные операции, аудит и наблюдаемость, безопасность (документы `07`, `08`, `10`) | 🟢 Done | 2026-09-18 |
| P7.12 | Conformance-адаптер PHP, регистрация SDK, вычисленный уровень — гейт фазы | 🟢 Done | 2026-09-18 |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Скелет ядра: `dart/agentools/`, ре-верифицированные пины, QA-тулинг, механика lock и игнорирования | 🟢 Done | 2026-09-18 |
| P8.2 | Идентичность расширения и negotiation в Dart (документ `01`) | 🟢 Done | 2026-09-18 |
| P8.3 | Value objects, валидация против опубликованных схем, СОБСТВЕННАЯ JCS, raw-preserving | 🟢 Done | 2026-09-19 |
| P8.4 | Профиль инструмента: `descriptor_digest`, `descriptor_drift`, `content_origin` + межъязыковой якорь | 🟢 Done | 2026-09-19 |
| P8.5 | Журнал действия + контракты персистентности в Dart (§C1–§C3, §C5–§C7, D13) | 🟢 Done | 2026-09-19 |
| P8.6 | Реестр эффектов + fencing-эпоха исполнителя в Dart (§C4) | 🟢 Done | 2026-09-19 |
| P8.7 | Подтверждение в Dart: Policy/Protocol Layer, подписанный токен, TTL, dual control, событие решения | 🟢 Done | 2026-09-19 |
| P8.8 | Идемпотентность в Dart: ключ + `args_fingerprint`, три состояния, два таймера (§D1–§D3) | 🟢 Done | 2026-09-19 |
| P8.9 | Ошибки и расширяемость в Dart: строковые коды, открытые перечисления, must-ignore (§E1–§E3, §E7) | 🟢 Done | 2026-09-19 |
| P8.10 | Сценарии и уточнения в Dart: AST-вычислитель с лимитами, saga + `COMPENSATION_FAILED`, MRTR | 🟢 Done | 2026-09-19 |
| P8.11 | Длинные операции, аудит и наблюдаемость, безопасность в Dart (документы `07`, `08`, `10`) | 🟢 Done | 2026-09-19 |
| P8.12 | Транспорт: порт `McpTransport` + пакет-спутник `agentools_mcp_dart`, триггер T-1, находка F7 | 🟢 Done | 2026-09-19 |
| P8.13 | Conformance-адаптер Dart, регистрация SDK, вычисленный уровень — гейт фазы | 🟢 Done | 2026-09-19 |

## Phase P9

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P9.1 | Сверка живого состояния контура Flex на дату исполнения + пакет решения владельцу | 🟢 Done | 2026-09-19 |
| P9.2 | Интеграционный контракт INT-001: карта портов, развод трёх гейтов, реанкор `B-32` П1–П7 | 🟢 Done | 2026-09-19 |
| P9.3 | Референсные адаптеры поведения: `ScriptedLlmPort`, `RegistryToolPort`, доставка протокольных объектов | 🟢 Done | 2026-09-19 |
| P9.4 | SQLite-сторы персистентности + прогон векторов ядра на БД-реализациях | 🟢 Done | 2026-09-19 |
| P9.5 | Референсные доменные инструменты + сценарий + продуктовые векторы | 🟢 Done | 2026-09-19 |
| P9.6 | CLI-цикл: потребитель доставляемых протокольных объектов | 🟢 Done | 2026-09-19 |
| P9.7 | Отчёт референсного среза — гейт фазы | ⬜ Not started | — |

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
| 2026-09-18 | implementation/high | plan-exec · in-progress · P7.4 reconciliation: per-item Validation on HEAD, anchor 35afb53 |
| 2026-09-18 | implementation/high | plan-exec · closed-green · Reconciliation anchor 35afb53+d5bb077: ToolDescriptor/digest/drift/content_origin; golden sha-256:4471625dc88e78398a8abd15fabb1bbee8691336c1ae4c43df293eca141ff43e; vectors tooldigest-001 tooldescription-001/002 toolcontract-001 profileuntrusted-001; phpunit 66/66 |
| 2026-09-18 | implementation/high | plan-exec · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.5 reconciliation: per-item Validation on HEAD, anchor 62e31c3 |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor 62e31c3: EventLog/SessionStore/Clock ports + in-memory refs; negative ALC-21/ALC-31/ALC-3; Known Deviation Action/ not Journal/; phpunit 73/73 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.6 reconciliation: per-item Validation on HEAD, anchor db8f6d1 |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor db8f6d1: Effects/ epoch fencing; ALC-24..27; zombie StaleExecutorEpoch; commit registry-first; phpunit 73/73 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.7 reconciliation: per-item Validation on HEAD, anchor 23be310+working-tree |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor 23be310: Confirmation Policy/Protocol; CNF-18..22/29/38; journal supersede; phpunit 75/75 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.8 reconciliation: per-item Validation on HEAD, anchor f8419f2 |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor f8419f2: Contracts/IdempotencyStore + in-memory ref; IDE-3/6/7/9/10/12..16/18/19/21/23; enum-count test; phpunit 76/76 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.9 reconciliation: per-item Validation on HEAD, anchor 6e1ec1d+working-tree |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor 6e1ec1d+working-tree: Error/ not Errors/; OpenEnum/MustIgnoreEnvelope/ValueLifecycle; GREASE grease-001..004 unit replay; IDE-22..29/39..43/45..47; phpunit 92/92 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.10 reconciliation: per-item Validation on HEAD, anchor e232fc5+working-tree |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor e232fc5+working-tree: AST/MRTR/saga; SCN-16..19 limit-red tests; LlmPort/ToolPort; phpunit 95/95 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.11 implementation: php/src/Ops/**, AuditSink, LOP/AOB/SEC tests |
| 2026-09-18 | implementation/high | plan-run · closed-green · Ops/Audit/Security: LongOperationCoordinator, AuditEventFactory, CredentialGuard, PrincipalTriple; phpunit 123/123; vectors LOP-1..27,AOB-1..16,SEC-1..16 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · Scaffold: php/conformance adapter+engine, sdks.json; L1 2/126; GATE BLOCKED |
| 2026-09-18 | implementation/high | plan-run · closed-green · Conformance 213/213; declared_level L3; owner-gate branch C; ГЕЙТ P7 PASS; projection-catalog deviation documented |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P7.3 reconciliation: per-item Validation on HEAD, anchor 1f5eaf8 |
| 2026-09-18 | implementation/high | plan-run · closed-green · Reconciliation anchor 1f5eaf8: Schema/Canonicalizer+RawDocument+PublishedSchemaValidator; RFC8785 vectors×3; AOB-32..37 IDE-41; self-hosted JCS (no mmccook bypass); phpunit Schema 12/12 |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/medium | plan-run · in-progress · P8.1: scaffold dart/agentools core package |
| 2026-09-18 | implementation/medium | plan-run · closed-green · spec_rev 2026-08; pins re-verified 2026-09-18; item b3d9760; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/medium | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · in-progress · P8.2: Dart extension identity and negotiation |
| 2026-09-18 | implementation/high | plan-run · closed-green · vectors extension-001 extidentity-001/002 extnegotiation-001/002; PHP reconciliation aligned; item dc534dd; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-18 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.3: schema module — JCS, RawDocument, PublishedSchemaValidator, ToolDescriptor/ToolError DTOs |
| 2026-09-19 | implementation/high | plan-run · closed-green · RFC8785×5; BMP anchor; self-hosted Canonicalizer; PublishedSchemaValidator draft2020_12; vectors AOB-32..37 IDE-41; item pending-commit; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.4: tool profile module — descriptor_digest, descriptor_drift, content_origin + PHP anchor |
| 2026-09-19 | implementation/high | plan-run · closed-green · golden sha-256:4471625dc88e78398a8abd15fabb1bbee8691336c1ae4c43df293eca141ff43e; vectors tooldigest-001 tooldescription-001/002 toolcontract-001 profileuntrusted-001; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.5: action journal + persistence ports — fold, hash chain, PHP anchor |
| 2026-09-19 | implementation/high | plan-run · closed-green · hash-chain sha-256:e1a9de57217a9919206628b0a4e1afd3a009f17b7c667afc98061d9e0936dfd0 + sha-256:ef22613a6bf20d817d3a3d9e37484a1796673e75c8d8b1e4d11a9c7119ae8d07; ALC-3/7/21/31/32/33/36; 55 tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.6: effects registry + executor epoch fencing in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · ALC-24..27; zombie StaleExecutorEpoch; commit registry-first; 58 tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.7: confirmation Policy/Protocol layers + signed token in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · CNF-2..39; Policy/Protocol split; HMAC via TokenSigner port (package:crypto, pre-pinned D91); token coverage 8 fields match PHP; 71 tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.8: idempotency dedup store + two timers in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · IDE-3/6/7/9/10/12..16/18/19/21/23; Contracts/IdempotencyStore + in-memory ref; three states no-wait; two error codes; two timers; PHP fingerprint anchor; 79 tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.9: errors + open enums + must-ignore in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · IDE-22..29/35..47/39..43; errors module: OpenEnum sealed+unknown, ToolExecutionResult/ProtocolException, MustIgnoreEnvelope; GREASE grease-001..004 unit replay; 99 tests; enum grep=1 ValueLifecycle closed only; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.10: scenarios AST evaluator saga MRTR in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:152 |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.11: long operations, audit/observability, security in Dart |
| 2026-09-19 | implementation/high | plan-run · closed-green · LOP-1..27/AOB-1..31/SEC-1..16; ops module: LongOperationCoordinator task+sync paths, AuditEventFactory+AuditSink, CredentialGuard, PrincipalTriple, CostRecorder, TraceCorrelation, BaggageRefs, TaskPort; 136 tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.12: McpTransport + agentools_mcp_dart satellite |
| 2026-09-19 | implementation/high | plan-run · closed-green · P8.12: McpTransport port + agentools_mcp_dart on mcp_dart 2.4.2 stable; T-1 branch (a) D39 prerelease risk lifted; F7 evidence committed; 3 satellite + 136 core tests; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P8.13: Dart conformance adapter + SDK registration + phase gate |
| 2026-09-19 | implementation/high | plan-run · closed-green · Conformance 213/213; declared_level L3; owner-gate branch C; ГЕЙТ P8 PASS; projection-catalog deviation documented; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.1: Flex/CoreX recon + owner decision package (document-only) |
| 2026-09-19 | implementation/high | plan-run · closed-green · findings/P9-flex-state-2026-09-19.md; пересечение пусто; рекомендация code-home (C2); Q-15/Q-18 обновлены; owner gate code-home A/B/C
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | owner | owner-gate · closed-green · Q-18 code-home branch (C2): thin bridge over corex/core+flex/crm+app; D184; P9.2 unblocked |
| 2026-09-19 | frontier/high | plan-run · in-progress · P9.2: INT-001 integration contract (document-only) |
| 2026-09-19 | frontier/high | plan-run · closed-green · INT-001 published; 8-port map; 3-gate table; B-32 P1-P7 reanchor; journal branch (a); D185; Q-19/Q-11; Exported SS7
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.3: behavior adapters (LlmPort/ToolPort/Delivery/Clock) in flexcrm C2 bridge |
| 2026-09-19 | implementation/high | plan-run · blocked · 🔴 Blocked: AiGateway facade absent; findings/P9.3-blocker-aigateway-2026-09-19.md; Возобновление: owner + FLEX-2 P6.15 corex/ai AiGateway (ADR-020); own gateway forbidden
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.3 attempt 2: re-verify AiGateway blocker before adapter scaffold |
| 2026-09-19 | implementation/high | plan-run · blocked · 🔴 Blocked: AiGateway facade absent (attempt 2 re-confirmed); findings/P9.3-blocker-aigateway-2026-09-19.md; Возобновление: owner + FLEX-2 P6.15 |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.3 attempt 3: re-verify AiGateway blocker before adapter scaffold |
| 2026-09-19 | implementation/high | plan-run · blocked · 🔴 Blocked: AiGateway facade absent (attempt 3 re-confirmed); findings/P9.3-blocker-aigateway-2026-09-19.md; Возобновление: owner + FLEX-2 P6.15 |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | owner | owner-gate · closed-green · D186: Agentools decoupled from CoreX/Flex; INT-001 deferred; INT-002 active; P9.3-P9.7 rebaselined to standalone reference slice in agentools repo |
| 2026-09-19 | owner | plan-design · in-progress · D186 rebaseline: P9.3 spec rewritten (INT-002); prior AiGateway blocked attempts superseded; item reopened |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.3 attempt 4: INT-002 reference behavior adapters in php/reference |
| 2026-09-19 | implementation/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:178 |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.4 attempt 1: INT-002 SQLite persistence ports in php/reference |
| 2026-09-19 | implementation/high | plan-run · closed-green · INT-002 §4: SqliteEventLog, SqliteSessionStore, SqliteIdempotencyStore, TenantHmacTokenSigner, CollectingAuditSink; adapter php/reference/conformance/adapter.php; L1 126/126; 4 invariants green; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.5 attempt 1: reference domain tools, day-end scenario, product-slice vectors |
| 2026-09-19 | implementation/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:184 |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · in-progress · P9.6 attempt 1: reference-slice CLI consumer over DeliveryCollector |
| 2026-09-19 | implementation/high | plan-run · closed-green · INT-002 §6: php/bin/reference-slice-demo + php/reference/Cli consumer; confirm/edit/reject via coordinator without gate policy in CLI; write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-19 | implementation/high | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- P0.3: known deviation
- P0.4: known deviation
- P0.7: known deviation
- P0.8: known deviation
- P2.4: known deviation
- P2.5: known deviation
- P3.24: known deviation
- P3.25: known deviation
- P7.12: known deviation
