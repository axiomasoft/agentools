<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-20
inputs_sha256: a8649248af7f6ed23abfc5719a9174105350125c78f0071b1d0bc07ecfb50844
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | — |
| Status | ⬜ Not started |
| Last Updated | 2026-09-20 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Design lifecycle carrier | 0/1 | ⬜ Not started |
| P1 | Baseline, threat model и контракт guarantees | 0/5 | ⬜ Not started |
| P10 | Production-grade release acceptance и owner gate | 0/5 | ⬜ Not started |
| P2 | Safe confirmation boundary и context-rich authorization | 0/4 | ⬜ Not started |
| P3 | Durable anti-replay, token envelope и key lifecycle | 0/4 | ⬜ Not started |
| P4 | Lease/fencing idempotency и external-effect guarantees | 0/4 | ⬜ Not started |
| P5 | Atomic event store, audit envelope и observability | 0/4 | ⬜ Not started |
| P6 | Cross-language semantics, errors, canonicalization и MCP conformance | 0/5 | ⬜ Not started |
| P7 | Intentional public API и package boundaries | 0/4 | ⬜ Not started |
| P8 | Adversarial verification и resource envelopes | 0/5 | ⬜ Not started |
| P9 | CI, supply chain и reproducible release artifacts | 0/5 | ⬜ Not started |

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
| P10.1 | Заморозить release-acceptance contract и exact claim/evidence matrix | ⬜ Not started | — |
| P10.2 | Согласовать документацию, support/security/versioning и migration/recovery guidance | ⬜ Not started | — |
| P10.3 | Перепроверить exact RC в clean consumers и отрепетировать migration/recovery paths | ⬜ Not started | — |
| P10.4 | Собрать final evidence dossier и адъюдицировать residual risk | ⬜ Not started | — |
| P10.5 | Принять private RC и выпустить non-authorizing G-RELEASE decision packet | ⬜ Not started | — |

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
| P7.1 | Заморозить package-boundary contract и target API manifests | ⬜ Not started | — |
| P7.2 | Отделить PHP core от testkit/reference и закрыть API drift | ⬜ Not started | — |
| P7.3 | Сузить Dart exports и закрепить testkit/MCP companion boundary | ⬜ Not started | — |
| P7.4 | Доказать archive allowlists, migrations и clean consumers | ⬜ Not started | — |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Заморозить adversarial verification contract и risk/proof manifest | ⬜ Not started | — |
| P8.2 | Построить deterministic property, differential и bounded fuzz corpus | ⬜ Not started | — |
| P8.3 | Убить critical PHP/Dart mutants и закрепить calibrated mutation gates | ⬜ Not started | — |
| P8.4 | Доказать PostgreSQL invariants multi-process crash schedules | ⬜ Not started | — |
| P8.5 | Калибровать resource envelopes и выпустить aggregate P8 receipt | ⬜ Not started | — |

## Phase P9

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P9.1 | Зафиксировать CI/supply-chain contract, immutable tool lock и capability inventory | ⬜ Not started | — |
| P9.2 | Реализовать hardened CI matrices и точное отображение P8 evidence tiers | ⬜ Not started | — |
| P9.3 | Собрать канонические воспроизводимые private RC subjects и receipt schema | ⬜ Not started | — |
| P9.4 | Сгенерировать и проверить SPDX SBOM, SLSA provenance и conditional GitHub attestation | ⬜ Not started | — |
| P9.5 | Собрать trusted private-RC workflow и aggregate verification receipt | ⬜ Not started | — |

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
| 2026-09-19 | plan-designer/gpt-5.6-sol/high | plan-design · no-op · P7 детализирована в P7.1–P7.4: exact package/API contract, PHP dev-only testkit/reference, Dart core/testkit/MCP boundaries и isolated built-artifact consumers; добавлены D12, primary-source RAG и api-boundaries/solo batches; implementation не выполнялась. |
| 2026-09-19 | plan-designer/gpt-5.6-sol/high | plan-design · no-op · note сокращена; полный текст: journal.jsonl:9 |
| 2026-09-19 | plan-designer/gpt-5.6-sol/high | plan-design · no-op · P9 детализирована в P9.1–P9.5: trust-plane CI, dependency matrices, deterministic private RC, SPDX/SLSA и capability-qualified attestation; добавлены D14, Perplexity+primary-source RAG и три batches; implementation/external mutations не выполнялись. |
| 2026-09-19 | gpt-5.6-sol/high | plan-design · no-op · P10 детализирована в P10.1–P10.5: exact acceptance matrix, truthful docs, artifact-only consumers, final dossier и private-RC acceptance с non-authorizing G-RELEASE packet; добавлены D15, primary-source RAG и три batches; implementation/publication не выполнялись. |
| 2026-09-20 | gpt-5.6-sol/xhigh | plan-design · no-op · Execution Routing refined to 19 single-model batches: P2–P5 merged; exact plan-run commands and Composer 2.5/Grok 4.6 assignments recorded in D16/roadmap; implementation not run. |

## Owner Gates

- —
