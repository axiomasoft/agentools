<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-20
inputs_sha256: c2a4867b1fbe1aa66f97070c05bdc85c9f9f654014c53115ada8a4ea4d1bbdd5
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-09-20 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Design lifecycle carrier | 0/1 | ⬜ Not started |
| P1 | Baseline, threat model и контракт guarantees | 5/5 | 🟢 Done |
| P10 | Production-grade release acceptance и owner gate | 0/5 | ⬜ Not started |
| P2 | Safe confirmation boundary и context-rich authorization | 4/4 | 🟢 Done |
| P3 | Durable anti-replay, token envelope и key lifecycle | 4/4 | 🟢 Done |
| P4 | Lease/fencing idempotency и external-effect guarantees | 4/4 | 🟢 Done |
| P5 | Atomic event store, audit envelope и observability | 4/4 | 🟢 Done |
| P6 | Cross-language semantics, errors, canonicalization и MCP conformance | 5/5 | 🟢 Done |
| P7 | Intentional public API и package boundaries | 4/4 | 🟢 Done |
| P8 | Adversarial verification и resource envelopes | 3/5 | 🟡 In progress |
| P9 | CI, supply chain и reproducible release artifacts | 0/5 | ⬜ Not started |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | Детализировать P1–P10 и получить GREEN whole-plan design audit | ⬜ Not started | — |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Зафиксировать воспроизводимый pre-refactor baseline | 🟢 Done | 2026-09-20 |
| P1.2 | Заморозить trust boundaries, data flows и threat/misuse model | 🟢 Done | 2026-09-20 |
| P1.3 | Определить compatibility/support policy и migration inventory | 🟢 Done | 2026-09-20 |
| P1.4 | Заморозить guarantees, non-guarantees и proof ownership | 🟢 Done | 2026-09-20 |
| P1.5 | Заморозить resource envelope и провести cross-contract gate P1 | 🟢 Done | 2026-09-20 |

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
| P2.1 | Заморозить confirmation-security contract, migration и adversarial cases | 🟢 Done | 2026-09-20 |
| P2.2 | Ввести typed trusted-context и binding model в PHP/Dart | 🟢 Done | 2026-09-20 |
| P2.3 | Реализовать safe ConfirmationService в PHP/Dart | 🟢 Done | 2026-09-20 |
| P2.4 | Мигрировать consumers и закрыть cross-language bypass gate | 🟢 Done | 2026-09-20 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Заморозить token-replay contract, migration и adversarial cases | 🟢 Done | 2026-09-20 |
| P3.2 | Ввести bounded at1 codec и TokenKeyProvider в PHP/Dart | 🟢 Done | 2026-09-20 |
| P3.3 | Реализовать atomic consume, PostgreSQL adapter и cleanup | 🟢 Done | 2026-09-20 |
| P3.4 | Встроить durable consume в supported path и закрыть replay gate | 🟢 Done | 2026-09-20 |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Заморозить lease/fencing contract, capability model и adversarial cases | 🟢 Done | 2026-09-20 |
| P4.2 | Ввести acquire/heartbeat/CAS API и in-memory adapter в PHP/Dart | 🟢 Done | 2026-09-20 |
| P4.3 | Реализовать PostgreSQL lease adapter, takeover и retention | 🟢 Done | 2026-09-20 |
| P4.4 | Встроить IdempotencyCoordinator, capability policy и crash/fencing gate | 🟢 Done | 2026-09-20 |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Заморозить EventStore/audit/observability contract, collision/redaction и adversarial cases | 🟢 Done | 2026-09-20 |
| P5.2 | Ввести EventStore API и in-memory adapter в PHP/Dart | 🟢 Done | 2026-09-20 |
| P5.3 | Реализовать PostgreSQL event adapter, IDENTITY position и keyset pagination | 🟢 Done | 2026-09-20 |
| P5.4 | Встроить journal/audit wiring, snapshot provenance и collision/cardinality gate | 🟢 Done | 2026-09-20 |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Зафиксировать successor `2026-09`, semantic/error contract и migration | 🟢 Done | 2026-09-20 |
| P6.2 | Собрать generated semantic manifest и exact-set PHP/Dart drift gate | 🟢 Done | 2026-09-20 |
| P6.3 | Доказать JCS по pinned independent oracle и differential corpus | 🟢 Done | 2026-09-20 |
| P6.4 | Запиннить official MCP suite и выпустить applicability/scenario receipts | 🟢 Done | 2026-09-20 |
| P6.5 | Перевести conformance на successor и выпустить integrated P6 gate | 🟢 Done | 2026-09-20 |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P7.1 | Заморозить package-boundary contract и target API manifests | 🟢 Done | 2026-09-20 |
| P7.2 | Отделить PHP core от testkit/reference и закрыть API drift | 🟢 Done | 2026-09-20 |
| P7.3 | Сузить Dart exports и закрепить testkit/MCP companion boundary | 🟢 Done | 2026-09-20 |
| P7.4 | Доказать archive allowlists, migrations и clean consumers | 🟢 Done | 2026-09-20 |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Заморозить adversarial verification contract и risk/proof manifest | 🟢 Done | 2026-09-20 |
| P8.2 | Построить deterministic property, differential и bounded fuzz corpus | 🟢 Done | 2026-09-20 |
| P8.3 | Убить critical PHP/Dart mutants и закрепить calibrated mutation gates | 🟢 Done | 2026-09-20 |
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
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Added tools/hardening_baseline.py (capture/verify), unittest checks, baseline capture+verify at 8ce43f4; inherited RED probes preserved (ci 3–4, dart analyze, publish dry-run, composer validate).
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Published root/contracts/threat-model-v1.md (TM-001–TM-014, F01–F12 owners, trust zones/data flows); tools/validate_threat_model_v1.py GREEN.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Published compatibility-support-v1.md and findings/P1-migration-inventory.md anchored to P1 baseline; no release-supported lanes claimed.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Published hardening-guarantees-v1.md (19 G-*, 9 NG-*, TM-001–TM-014 mapped); tools/validate_hardening_guarantees_v1.py GREEN.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Published resource-envelope-v1.md (RL-001–RL-025), findings/P1-contract-coverage.md PASS, benchmark protocol; tools/validate_resource_envelope_v1.py GREEN; plan.md exported paths updated.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Published confirmation-security-v1.md, P2-confirmation-migration.md, fixtures/hardening/confirmation-security-v1.json; validate_confirmation_security_v1.py GREEN. |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Typed PHP/Dart trusted-context model and ports; ConfirmationSecurityModelTest GREEN. |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · ConfirmationService PHP/Dart, structured P2 refusals, deprecated expert protocol; ConfirmationServiceTest happy path GREEN. |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Reference slice on ConfirmationService; validate_confirmation_boundary.py GREEN; P2-confirmation-parity.md. |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · Published token-replay-v1.md, P3-token-migration.md, fixtures/hardening/token-replay-v1.json (26 cases); validate_token_replay_v1.py GREEN; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · PHP/Dart at1 codec + TokenKeyProvider; TokenEnvelopeTest green; canonical JSON byte-matched; composer analyse / dart analyze green; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · ConfirmationTokenStore port, InMemory + PdoPostgres adapters, shared SQL SSOT; consume/collision/cleanup/restart postgres evidence; Dart in-memory + SqlConfirmationTokenStore.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · Supported ConfirmationService wired to at1 + durable consume; removed process-local spent sets; DurableReplayGateTest + findings/P3-replay-parity.md; reference TenantTokenKeyProvider.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · Published idempotency-lease-v1.md, P4-idempotency-migration.md, fixtures/hardening/idempotency-lease-v1.json (21 cases); validate_idempotency_lease_v1.py GREEN; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · PHP/Dart fenced acquire/heartbeat/complete(lease)/fail API + in-memory CAS; LeaseFenceTest and dart lease_fence_test green; composer analyse / dart analyze green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · PdoPostgresIdempotencyStore unique-arbiter + takeover/retention; shared SQL SSOT; Dart SqlIdempotencyStore injected SQL; SQLite reference CAS marked insufficient for durable close.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · IdempotencyCoordinator wired as supported ToolPort; capability policy; SliceToolCoordinator uses coordinator; FencingGateTest postgres takeover; findings/P4-fencing-parity.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · P4 closed 4/4: lease/fencing contract, acquire/CAS, PostgreSQL arbiter, IdempotencyCoordinator capability/crash gate. Exactly-once remains a non-guarantee.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · Published event-store-v1.md, P5-eventlog-migration.md, event-store-v1.json (21 cases); validate_event_store_v1.py GREEN; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · PHP/Dart EventStore API + in-memory classify-before-insert; EventStoreCollisionTest green; composer analyse / dart test green; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · PHP PDO + Dart injected-SQL EventStore; IDENTITY/keyset; 50-way PG one winner; SQLite insufficient. composer analyse / dart test green; spec/revisions/2026-08 unchanged.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · JournalAppender/AuditEventFactory CSPRNG EventStore path; redaction/cardinality; snapshot hash discard; EventStoreGateTest; P5-event-parity.md. composer test/analyse and dart test green.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · P5 closed 4/4: EventStore contract, in-memory API, PostgreSQL IDENTITY/keyset, JournalAppender/audit wiring. Exactly-once consumer delivery remains a non-guarantee.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · Candidate spec/revisions/2026-09 + fixtures/2026-09; HRD-*; IDE-31; parity contract. spec_lint/rules_index/close_schema/fixtures-check green; 2026-08 frozen.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · semantic_manifest.py; semantic-manifest.json; php/dart profiles; semantic-parity bin + report.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · JCS oracle six vectors; CanonicalizerOracleTest PHP/Dart; jcs-differential. Deviation: 10k ES6 corpus deferred.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · MCP pin 0.2.0-alpha.11; applicability matrix 89 rows; tools_call receipt; summary official MCP scenario compatibility.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · revision.lock.json; adapters 2026-09; p6-gate + ci_gate layer4; deviation: 2026-09 behavior corpus probe ~142/213.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · package-boundary-v1.md + tools/package_surface.py + api/package-surface-v1.json (386 entries). Inventory pending 0 after P7.2/P7.3 freeze. D16 Composer 2.5 vs this Grok 4.6 recorded as route overshoot, not a product block.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:42 |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:43 |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · package-boundary-v1.md + tools/package_surface.py + api/package-surface-v1.json (386 entries). Inventory pending 0 after P7.2/P7.3 freeze. D16 Composer 2.5 vs this Grok 4.6 recorded as route-deviation, not a product block.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:45 |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:46 |
| 2026-09-20 | composer-2.5/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:47 |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · AV contract+manifest+validator GREEN (22 AV, 81 dispositions).
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · Deterministic corpus+differential GREEN; CRP-JCS-10K/CRP-SEM-213 declared not proved.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | grok-4.6/high | plan-run · closed-green · 26/26 catalog kills; Infection 0.35.4 combined MSI 75.68 floor 70; enum file excluded.
write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- P6.3: known deviation
- P6.5: known deviation
- P7.3: known deviation
- P8.2: known deviation
- P8.3: known deviation
