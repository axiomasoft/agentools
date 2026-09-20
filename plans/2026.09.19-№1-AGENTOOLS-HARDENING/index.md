# Индекс плана — `2026.09.19-№1-AGENTOOLS-HARDENING`

**Обновлён:** 2026-09-20

## Карта директорий

| Путь | Назначение |
|:--|:--|
| `plan.md` | stable core: Meta, Context, Execution Rules, Routing и Contracts |
| `status.md` | generated status projection |
| `phases/<Pn>/<Pn>.md` | P1–P10 detailed; item specs are the stable execution contracts |
| `bundles/` | digest-qualified executor inputs; P0.1 и первый ready product item P1.1 материализованы, predecessor-dependent bundles отложены до появления producer artifacts |
| `decisions/` | append-only decisions и generated current/archive/index views |
| `handoff.md` | rolling runnable Next; сейчас P0.1 whole-plan design audit после закрытия P1 |
| `roadmap.md` | dependency graph, design routes и owner gates |
| `journal.jsonl` | append-only история task-plan запусков |
| `brief/00-brief.md` | owner request, boundaries и success criteria |
| `findings/audit-verdicts.md` | проверка и phase ownership находок аудита |
| `research/external-foundations-2026-09-19.md` | primary-source external evidence и retrieval boundary |
| `research/P1-design.md` | repository-grounded P1 decomposition, alternatives и contract boundaries |
| `research/P1-design-rag.md` | P1 changing external premises и retrieval limitations |
| `research/P2-design.md` | P2 repository recon, binding tuple, alternatives и item decomposition |
| `research/P2-design-rag.md` | P2 Perplexity + primary-source authorization/transaction evidence |
| `research/P3-design.md` | P3 repository recon, envelope/consume/key boundary и item decomposition |
| `research/P3-design-rag.md` | P3 Perplexity + primary-source RFC/PostgreSQL/NIST evidence |
| `research/P4-design.md` | P4 repository recon, lease/fence/capability boundary и item decomposition |
| `research/P4-design-rag.md` | P4 Perplexity + primary-source Powertools/Kleppmann/PostgreSQL/IETF evidence |
| `research/P5-design.md` | P5 repository recon, EventStore/collision/pagination boundary и item decomposition |
| `research/P5-design-rag.md` | P5 Perplexity + primary-source Kurrent/PostgreSQL/RFC 8785/Prometheus/OTel evidence |
| `research/P6-design.md` | P6 successor semantics, exact-set profile, JCS oracle и MCP applicability |
| `research/P6-design-rag.md` | P6 primary-source RFC/JCS/official MCP evidence |
| `research/P7-design.md` | P7 package/API inventory, boundary decision, alternatives и item decomposition |
| `research/P7-design-rag.md` | P7 official Composer/Dart package and archive rules |
| `research/P8-design.md` | P8 risk/proof manifest, deterministic corpus, mutation/storage/resource boundaries |
| `research/P8-design-rag.md` | P8 Perplexity synthesis + primary-source Infection/Dart/PostgreSQL/benchmark evidence |
| `research/P9-design.md` | P9 trust planes, CI tier mapping, exact private subjects и capability model |
| `research/P9-design-rag.md` | P9 maximum Perplexity retrieval + primary-source GitHub/Composer/Dart/SPDX/SLSA evidence |
| `research/P10-design.md` | P10 exact acceptance graph, docs/consumer/dossier boundaries and private/public verdict split |
| `research/P10-design-rag.md` | P10 official Composer/Dart/Packagist/SemVer/GitHub publication constraints |
| `open-questions.md` | generated proposed decisions; сейчас publication/legal identity |

## Провенанс

| Источник | Слой | Питает | Суть |
|:--|:--|:--|:--|
| `audits/2026-09-19-audit.md` | external input | P1–P10 | исходный backlog, проверенный, но не нормативный |
| `findings/audit-verdicts.md` | findings | P1–P10 | verdict/поправка и ровно одна owning phase на substantive finding |
| `research/external-foundations-2026-09-19.md` | research | P1–P10 | первичные основания security/durability/conformance/release constraints |
| `plans/AGENTOOLS/` | historical plan | P1, P6, P7, P9, P10 | frozen spec/evidence без переоткрытия status или P11 |
| `plans/AGENTOOLS/root/contracts/package-quality-baseline.md` | historical contract | P1, P7, P9, P10 | существующий package quality baseline как pinned input |

## Ключевые артефакты `root/`

P1 опубликовал `threat-model-v1`, `compatibility-support-v1`,
`hardening-guarantees-v1`, `resource-envelope-v1` и `findings/P1-contract-coverage.md`;
P2.1 создаст
`confirmation-security-v1`; P3.1 создаст `token-replay-v1`; P4.1 создаст
`idempotency-lease-v1`; P5.1 создаст `event-store-v1`. До их execution это
declared draft outputs, а не доказанные product guarantees. P7.1 создаст
`package-boundary-v1`; P8.1 — `adversarial-verification-v1` и exact proof manifest.
P9.1 создаст `ci-supply-chain-v1` и immutable tool/action lock; P9.3 —
`release-candidate-v1` с exact subject/receipt schema. P10.1 создаст
`release-acceptance-v1` с exact claim/evidence and private/public verdict schema.
