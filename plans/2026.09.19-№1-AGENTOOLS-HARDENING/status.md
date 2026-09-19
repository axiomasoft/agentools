<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-19
inputs_sha256: 49b5b8fe0aba322fd04ac8affd69a59ccdfed480f57f86d98d748dfa9bc5929f
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | — |
| Status | ⬜ Not started |
| Last Updated | 2026-09-19 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Design lifecycle carrier | 0/1 | ⬜ Not started |
| P1 | Baseline, threat model и контракт guarantees | 0/5 | ⬜ Not started |
| P10 | Production-grade release acceptance и owner gate | 0/0 | ⬜ Not started |
| P2 | Safe confirmation boundary и context-rich authorization | 0/4 | ⬜ Not started |
| P3 | Durable anti-replay, token envelope и key lifecycle | 0/4 | ⬜ Not started |
| P4 | Lease/fencing idempotency и external-effect guarantees | 0/4 | ⬜ Not started |
| P5 | Atomic event store, audit envelope и observability | 0/4 | ⬜ Not started |
| P6 | Cross-language semantics, errors, canonicalization и MCP conformance | 0/5 | ⬜ Not started |
| P7 | Intentional public API и package boundaries | 0/0 | ⬜ Not started |
| P8 | Adversarial verification и resource envelopes | 0/0 | ⬜ Not started |
| P9 | CI, supply chain и reproducible release artifacts | 0/0 | ⬜ Not started |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Детализировать P1–P10 и получить GREEN whole-plan design audit | ⬜ Not started | — |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Зафиксировать воспроизводимый pre-refactor baseline | ⬜ Not started | — |
| P1.2 | Заморозить trust boundaries, data flows и threat/misuse model | ⬜ Not started | — |
| P1.3 | Определить compatibility/support policy и migration inventory | ⬜ Not started | — |
| P1.4 | Заморозить guarantees, non-guarantees и proof ownership | ⬜ Not started | — |
| P1.5 | Заморозить resource envelope и провести cross-contract gate P1 | ⬜ Not started | — |

## Phase P10

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Заморозить confirmation-security contract, migration и adversarial cases | ⬜ Not started | — |
| P2.2 | Ввести typed trusted-context и binding model в PHP/Dart | ⬜ Not started | — |
| P2.3 | Реализовать safe ConfirmationService в PHP/Dart | ⬜ Not started | — |
| P2.4 | Мигрировать consumers и закрыть cross-language bypass gate | ⬜ Not started | — |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Заморозить token-replay contract, migration и adversarial cases | ⬜ Not started | — |
| P3.2 | Ввести bounded at1 codec и TokenKeyProvider в PHP/Dart | ⬜ Not started | — |
| P3.3 | Реализовать atomic consume, PostgreSQL adapter и cleanup | ⬜ Not started | — |
| P3.4 | Встроить durable consume в supported path и закрыть replay gate | ⬜ Not started | — |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Заморозить lease/fencing contract, capability model и adversarial cases | ⬜ Not started | — |
| P4.2 | Ввести acquire/heartbeat/CAS API и in-memory adapter в PHP/Dart | ⬜ Not started | — |
| P4.3 | Реализовать PostgreSQL lease adapter, takeover и retention | ⬜ Not started | — |
| P4.4 | Встроить IdempotencyCoordinator, capability policy и crash/fencing gate | ⬜ Not started | — |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Заморозить EventStore/audit/observability contract, collision/redaction и adversarial cases | ⬜ Not started | — |
| P5.2 | Ввести EventStore API и in-memory adapter в PHP/Dart | ⬜ Not started | — |
| P5.3 | Реализовать PostgreSQL event adapter, IDENTITY position и keyset pagination | ⬜ Not started | — |
| P5.4 | Встроить journal/audit wiring, snapshot provenance и collision/cardinality gate | ⬜ Not started | — |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Зафиксировать successor `2026-09`, semantic/error contract и migration | ⬜ Not started | — |
| P6.2 | Собрать generated semantic manifest и exact-set PHP/Dart drift gate | ⬜ Not started | — |
| P6.3 | Доказать JCS по pinned independent oracle и differential corpus | ⬜ Not started | — |
| P6.4 | Запиннить official MCP suite и выпустить applicability/scenario receipts | ⬜ Not started | — |
| P6.5 | Перевести conformance на successor и выпустить integrated P6 gate | ⬜ Not started | — |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P9

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-09-19 | plan-designer/gpt-5.6-sol/high | plan-design · no-op · Создан верхний слой PLAN1: проверен аудит, собраны primary-source foundations, зафиксированы решения/routing и десять фаз-скелетов; implementation не выполнялась. |
| 2026-09-19 | gpt-5.6-sol/high | plan-design · no-op · P1 детализирована в P1.1–P1.5: baseline, threat/trust, compatibility/migrations, guarantees и resource envelope; добавлены D6, RAG/recon и два recommended batches; implementation не выполнялась. |
| 2026-09-19 | gpt-5.6-sol/high | plan-design · no-op · P2 детализирована в P2.1–P2.4: confirmation-security contract/cases, typed trusted context, safe PHP/Dart service и consumer bypass gate; добавлены D7, Perplexity+primary-source RAG и два recommended batches; implementation не выполнялась. |
| 2026-09-19 | plan-designer/cursor-grok-4.6/high | plan-design · no-op · P3 детализирована в P3.1–P3.4: token envelope/key lifecycle, atomic consume/PostgreSQL adapter, service wiring и replay gate; добавлены D8, Perplexity+primary-source RAG и два recommended batches; implementation не выполнялась. |
| 2026-09-19 | plan-designer/cursor-grok-4.6/high | plan-design · no-op · P4 детализирована в P4.1–P4.4: lease/fencing contract, acquire/heartbeat/CAS, PostgreSQL adapter и coordinator capability/crash gate; добавлены D9, Perplexity+primary-source RAG и два recommended batches; implementation не выполнялась. |
| 2026-09-19 | plan-designer/cursor-grok-4.6/high | plan-design · no-op · P5 детализирована в P5.1–P5.4: EventStore/collision/pagination, in-memory API, PostgreSQL adapter и journal/audit gate; добавлены D10, Perplexity+primary-source RAG и два recommended batches; implementation не выполнялась. |
| 2026-09-19 | plan-designer/gpt-5.6-sol/high | plan-design · no-op · P6 детализирована в P6.1–P6.5: successor 2026-09, generated exact-set semantic profile, independent JCS oracle, official MCP applicability и integrated conformance gate; добавлены D11, primary-source RAG и два recommended batches; implementation не выполнялась. |

## Owner Gates

- —
