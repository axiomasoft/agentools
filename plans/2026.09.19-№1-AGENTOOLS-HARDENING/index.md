# Индекс плана — `2026.09.19-№1-AGENTOOLS-HARDENING`

**Обновлён:** 2026-09-19

## Карта директорий

| Путь | Назначение |
|:--|:--|
| `plan.md` | stable core: Meta, Context, Execution Rules, Routing и Contracts |
| `status.md` | generated status projection |
| `phases/<Pn>/<Pn>.md` | P1–P5 detailed; P6–P10 skeletons до своих design-сессий |
| `bundles/` | digest-qualified executor inputs; product-item bundles отложены до producer artifacts/execution stage D2 |
| `decisions/` | append-only decisions и generated current/archive/index views |
| `handoff.md` | rolling runnable Next; сейчас отдельная детализация P6 |
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

P1.2–P1.5 создадут `threat-model-v1`, `compatibility-support-v1`,
`hardening-guarantees-v1` и `resource-envelope-v1`; P2.1 создаст
`confirmation-security-v1`; P3.1 создаст `token-replay-v1`; P4.1 создаст
`idempotency-lease-v1`; P5.1 создаст `event-store-v1`. До их execution это
declared draft outputs, а не доказанные product guarantees.
