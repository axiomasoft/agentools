# 2026.09.19-№1-AGENTOOLS-HARDENING — План №1 — Production hardening Agentools

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.09.19-№1-AGENTOOLS-HARDENING |
| Short ID | PLAN1 |
| Title | План №1 — Production hardening Agentools |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | gpt-5.6-sol/high (Codex, provider-attested, 2026-09-19) |
| Repository | `/home/vostrikov/projects/packages/agentools` |
| Related Packages | `php/` · `dart/agentools/` · `dart/agentools_mcp_dart/` · `spec/` · `fixtures/` · `conformance/` |
| Execution Mode | phase-first: P1–P6 detailed + P7–P10 skeletons; каждая фаза детализируется отдельным `task:plan-design` |
| Target Operator Classes | frontier/high (Sol или Grok 4.6 для детализации/аудита) · implementation/high (исполнение security/public-contract items) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:agentools |
| visibility | private; release candidate обязателен, внешняя публикация только по G-RELEASE |
| supersedes | —; `AGENTOOLS` остаётся неизменяемым историческим планом |
| Paused By | — |

## 1. Context

Цель PLAN1 — довести Agentools от conformance-ready протокольного ядра до
production-grade PHP/Dart package с safe-by-construction confirmation API, durable replay
protection, lease/fencing idempotency, atomic event persistence, намеренной
публичной поверхностью пакетов и воспроизводимым release candidate.

Вход — `audits/2026-09-19-audit.md`. Аудит не нормативен: его утверждения
разобраны в `findings/audit-verdicts.md`, а меняющиеся внешние основания — в
`research/external-foundations-2026-09-19.md`. Четыре несущие P0-находки
подтверждены кодом: caller-controlled confirmation policy/security context,
process-local spent-token state, idempotency без lease/fencing и EventLog без
atomic batch/collision semantics. `RAG:—`.

План не переоткрывает фазы `AGENTOOLS` и не исполняет его P11. Прежний план,
его frozen revision `2026-08`, conformance reports и integration slice — только
доказательные входы. Вся новая норма, миграция и release evidence принадлежат
PLAN1. Это исключает неявное изменение закрытой истории и двойное владение
одним release-гейтом.

Итоговая граница — не абстрактное «качество 1.0», а воспроизводимый кандидат,
который проходит PHP/Dart consumer smoke из пустых проектов, contract/concurrency/
mutation/property suites, official-applicable MCP conformance, archive inspection, SBOM,
checksums и provenance. Публикация в registry/tag остаётся отдельным
необратимым owner-gate.

## 2. Execution Rules

- **Жизненный цикл — три стадии (D2):** (1) каждая P1–P10 детализируется
  отдельным `task:plan-design`; (2) после всех детализаций идёт независимый
  `task:plan-audit <ID> design`; (3) только после GREEN аудита начинается
  `task:plan-exec`. Готовый item до стадии 3 не даёт права на исполнение.
- P0.1 — административный committed-input carrier стадии дизайна, не product item и не
  разрешение на реализацию. Он закрывается после детализации P1–P10 и GREEN design audit.
- Детализация фазы пишет `research/Pn-design.md`, `research/Pn-design-rag.md`, item-спеки
  и построчный Routing. Несущие внешние тезисы проверяются по первичным
  источникам; `RAG:✅` для external, `RAG:—` для repository evidence, `[UNVERIFIED]`
  до добора доказательств.
- Perplexity предпочтителен для retrieval/synthesis, но его выводы не являются
  proof: load-bearing тезис всегда перепроверяется в primary source. Если connector
  недоступен, проход использует широкий web search и явно фиксирует границу.
- Аудит — evidence, не исполняемая спека. Предложенные в нём SQL/API примеры —
  design sketches; детализация обязана сверить их с портами, spec `2026-08`, PHP/Dart
  parity и миграционной ценой.
- Каждая security/durability гарантия получает negative/concurrency/crash-boundary test;
  счастливый unit-test не заменяет доказательство атомарности между процессами.
- Гарантия `exactly-once external effect` запрещена. Допустимы только точно названные
  admission/replay/fencing гарантии и условие сотрудничества downstream system.
- PHP и Dart не обязаны иметь буквально одинаковые классы, но обязаны совпадать
  по machine-readable semantics: state transitions, token/event/error codes, canonicalization,
  fingerprints, durability promises и supported spec revision.
- In-memory adapters остаются testkit/reference-only и не могут быть production preset.
- Переносы каталогов не являются целью: package/publication boundary сначала доказывается
  manifest/archive/consumer гейтами; physical split делается только при измеримой выгоде.
- Новые runtime dependencies, storage schemas и breaking public contracts решаются в D-логе
  детализируемой фазы с compatibility/migration plan.
- Публикация, тег, внешний registry upload и смена legal identity запрещены до
  `[OWNER-GATE:G-RELEASE]`; локальный/private release candidate и dry-run разрешены.
- После каждой детализации фазы перезаписывать `handoff.md`, виды и проверять
  план. Journal append-only; generated carriers вручную не правятся.

## 3. Routing

На стадии 1 исполнимы не items, а отдельные design-проходы фаз. Каждый проход
обязан превратить скелет в item-спеки и добавить их построчно в каноническую
таблицу Routing. Выбор Sol/Grok ниже — часть контракта верхнего слоя.

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| solo | P0.1 | frontier/high | manual | full | design-stage lifecycle carrier: объединяет P1–P10 detail и независимый audit, не исполняет product code |
| foundation | P1.1 | implementation/high | plan-exec | full | recommended: clean-worktree baseline создаёт общий anchor/inventory для двух следующих контрактов; full защищает от false baseline |
| foundation | P1.2 | implementation/high | plan-exec | full | recommended: threat/trust model переиспользует baseline и current security seams; нормативный вход P2–P5 |
| foundation | P1.3 | implementation/high | plan-exec | full | recommended: compatibility/migration inventory переиспользует тот же baseline/manifests и может идти после P1.1 в dependency-safe order |
| contracts | P1.4 | implementation/high | plan-exec | full | recommended: guarantee catalog сводит threat и compatibility contracts; неверное обещание меняет downstream acceptance |
| contracts | P1.5 | implementation/high | plan-exec | full | recommended: resource envelope и coverage gate переиспользуют все P1 contracts; full проверяет нормативный вход P2–P10 |
| confirmation-contract | P2.1 | implementation/high | plan-exec | full | recommended: P1 trust/compatibility/guarantee contracts превращаются в один binding/error/case owner до изменения SDK |
| confirmation-contract | P2.2 | implementation/high | plan-exec | full | recommended: PHP/Dart typed model и ports переиспользуют contract/case manifest P2.1 и требуют cross-language security review |
| confirmation-service | P2.3 | implementation/high | plan-exec | full | recommended: оба SDK реализуют один safe facade и общий adversarial corpus; full защищает authorization/public-contract semantics |
| confirmation-service | P2.4 | implementation/high | plan-exec | full | recommended: consumer migration, bypass scan и full package regression переиспользуют P2.3 service/test context |
| token-envelope | P3.1 | implementation/high | plan-exec | full | recommended: P2 binding и P1 guarantees превращаются в один envelope/consume/key owner до codec и SQL |
| token-envelope | P3.2 | implementation/high | plan-exec | full | recommended: PHP/Dart parser и key provider реализуют P3.1 без PostgreSQL; full из-за crypto/parser mutants |
| durable-replay | P3.3 | implementation/high | plan-exec | full | recommended: atomic consume и cleanup переиспользуют frozen contract; concurrent PG evidence обязательна |
| durable-replay | P3.4 | implementation/high | plan-exec | full | recommended: wiring в P2 service/protocol и restart gate переиспользуют store/codec; full защищает CNF-20 |
| lease-contract | P4.1 | implementation/high | plan-exec | full | recommended: P1 guarantees превращаются в один lease/fence/capability owner до смены store API |
| lease-contract | P4.2 | implementation/high | plan-exec | full | recommended: PHP/Dart acquire/heartbeat/CAS и in-memory adapter реализуют P4.1 без PostgreSQL; full из-за fencing mutants |
| lease-durable | P4.3 | implementation/high | plan-exec | full | recommended: durable adapter и takeover SQL переиспользуют frozen contract; concurrent PG evidence обязательна |
| lease-durable | P4.4 | implementation/high | plan-exec | full | recommended: coordinator, capability policy и crash/fencing gate переиспользуют store; full защищает F04 |
| event-contract | P5.1 | implementation/high | plan-exec | full | recommended: P1 guarantees превращаются в один EventStore/audit/observability owner до смены journal API |
| event-contract | P5.2 | implementation/high | plan-exec | full | recommended: PHP/Dart UncommittedEvent/append/load и in-memory adapter реализуют P5.1 без PostgreSQL; full из-за collision mutants |
| event-durable | P5.3 | implementation/high | plan-exec | full | recommended: durable adapter, IDENTITY position и keyset load переиспользуют frozen contract; concurrent PG evidence обязательна |
| event-durable | P5.4 | implementation/high | plan-exec | full | recommended: journal/audit wiring, redaction/cardinality и collision gate переиспользуют store; full защищает F05 |
| semantic-core | P6.1 | implementation/high | plan-exec | full | recommended: successor revision сводит executed P2–P5 contracts в один normative owner до SDK drift gates |
| semantic-core | P6.2 | implementation/high | plan-exec | full | recommended: generated semantic profile и PHP/Dart exporters переиспользуют successor schemas/cases; full защищает public semantic contract |
| semantic-core | P6.3 | implementation/high | plan-exec | full | recommended: JCS oracle переиспользует successor corpus и проверяет оба SDK одними independent bytes |
| conformance-evidence | P6.4 | implementation/high | plan-exec | full | recommended: official MCP pin, applicability и Dart wrapper driver образуют один отдельный evidence contour |
| conformance-evidence | P6.5 | implementation/high | plan-exec | full | recommended: successor adapters, candidate lock и integrated receipt переиспользуют P6.2–P6.4 reports; full проверяет cross-language gate |

### 3.1 Стадия 1 — детализация фаз

| Фаза | Модель | Review | Почему |
|:--|:--|:--|:--|
| P1 | Codex `gpt-5.6-sol/high` | full | Нормативный baseline, threat model, compatibility и развод проверенных/непроверенных тезисов требуют синтеза всего дерева. |
| P2 | Codex `gpt-5.6-sol/high` | full | Security API, policy/approval binding и migration public contracts — открытый дизайн с высокой ценой ошибки. |
| P3 | Cursor `Grok 4.6/high` | full | Адверсариальный проход по replay, rotation, parser bounds и multi-process consume полезен как независимая вторая линза. |
| P4 | Cursor `Grok 4.6/high` | full | Lease/fencing и crash windows — distributed-systems предмет с многими межпроцессными failure modes. |
| P5 | Cursor `Grok 4.6/high` | full | Event ordering, collision, paging, audit/redaction и storage concurrency выигрывают от адверсариальной декомпозиции. |
| P6 | Codex `gpt-5.6-sol/high` | full | PHP/Dart semantic parity, error registry, JCS и distinction official MCP/Agentools conformance нуждаются в сквозном синтезе. |
| P7 | Codex `gpt-5.6-sol/high` | full | Package boundaries и intentional API меняют consumer contract; нужны compatibility и migration decisions до переносов. |
| P8 | Cursor `Grok 4.6/high` | full | Mutation/property/fuzz/concurrency план должен искать ложно-зелёные гейты, а не дублировать happy paths. |
| P9 | Codex `gpt-5.6-sol/high` | full | CI, archive, dependency matrices, SBOM/provenance и permissions образуют один supply-chain контракт. |
| P10 | Codex `gpt-5.6-sol/high` | full | Приёмка guarantees, docs, clean consumers и release decision должна связать все receipts без добавления функций. |

### 3.2 Стадия 2 — общий аудит дизайна

`task:plan-audit 2026.09.19-№1-AGENTOOLS-HARDENING design` — Codex `gpt-5.6-sol/high`,
`cold-start-root: whole-plan-independent-audit`. Проверяются сквозные инварианты,
миграции, непересекающееся владение артефактами, полнота `findings/audit-verdicts.md`
и доказуемость каждого release-гейта.

### 3.3 Объявленный route будущих items

| Фаза | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|
| P1 | implementation/high | plan-exec | full | Baseline и нормативные guarantees; детализация может снизить чисто механические items. |
| P2 | implementation/high | plan-exec | full | Security/public-contract поверхность. |
| P3 | implementation/high | plan-exec | full | Crypto/replay/key lifecycle и durable storage. |
| P4 | implementation/high | plan-exec | full | Concurrent leases, fencing и ambiguous external effects. |
| P5 | implementation/high | plan-exec | full | Event ordering, persistence, audit/redaction. |
| P6 | implementation/high | plan-exec | full | Shared semantic contract и cross-language drift. |
| P7 | implementation/high | plan-exec | full | Public API/package compatibility и migrations. |
| P8 | implementation/high | plan-exec | full | Адверсариальные test gates; отдельные benchmark plumbing items могут быть light. |
| P9 | implementation/high | plan-exec | full | Supply-chain/release automation и permission boundaries. |
| P10 | implementation/high | plan-exec | full | 1.0 acceptance; publication action дополнительно защищен G-RELEASE. |

## Audit-to-phase coverage

| Блок аудита | Фаза-владелец |
|:--|:--|
| Baseline, guarantees, threat model, resource bounds | P1 |
| Caller-controlled confirmation/security context, dual control, policy binding, authorization | P2 |
| Durable replay consume, token envelope, key rotation | P3 |
| Idempotency crash window, lease/fencing, downstream capability | P4 |
| Atomic event store, collision semantics, audit envelope, metrics | P5 |
| Error taxonomy, JCS, PHP/Dart semantic parity, official MCP distinction | P6 |
| Intentional API, core/testkit/adapters, in-memory boundary | P7 |
| Storage contract suites, concurrency, mutation, property, fuzz, benchmarks | P8 |
| CI hardening, dependency matrices, archives, SBOM, provenance | P9 |
| Documentation, consumer smoke, migration/release candidate, final DoD | P10 |

## 5. Decision Log

Каталог решений: `decisions/`; generated views собираются `plan-views.py decisions`.

## 7. Contracts

### Consumed

| Контракт | Донор-план | Pinned | Замечено | Реакция |
|:--|:--|:--|:--|:--|
| `spec/revisions/2026-08/` | AGENTOOLS | frozen repository bytes | 2026-09-19 | Изменение только через явную revision/migration decision PLAN1 |
| `root/contracts/package-quality-baseline.md` | AGENTOOLS P5.3/P11 | repository bytes | 2026-09-19 | Вход P1/P7/P9/P10; не владеет новым release-гейтом |

### Exported

| Контракт | Версия | Статус | Потребители | Уведомлены |
|:--|:--|:--|:--|:--|
| `root/contracts/threat-model-v1.md` | v1 draft | создаётся P1.2 | P2–P10 | — |
| `root/contracts/compatibility-support-v1.md` | v1 draft | создаётся P1.3 | P2–P10 | — |
| `root/contracts/hardening-guarantees-v1.md` | v1 draft | создаётся P1.4 | P2–P10 | — |
| `root/contracts/resource-envelope-v1.md` | v1 draft | создаётся P1.5 | P2–P10 | — |
| `root/contracts/confirmation-security-v1.md` | v1 draft | создаётся P2.1 | P2.2–P10 | — |
| `root/contracts/token-replay-v1.md` | v1 draft | создаётся P3.1 | P3.2–P10 | — |
| `root/contracts/idempotency-lease-v1.md` | v1 draft | создаётся P4.1 | P4.2–P10 | — |
| `root/contracts/event-store-v1.md` | v1 draft | создаётся P5.1 | P5.2–P10 | — |
| `spec/revisions/2026-09/semantic-manifest.json` | semantic-profile/v1 | generated candidate; создаётся P6.2 | PHP, Dart, conformance, P7–P10 | — |
