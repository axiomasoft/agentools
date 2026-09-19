---
id: D6
date: 2026-09-19
status: accepted
item: P1
items: [P1, P1.1, P1.2, P1.3, P1.4, P1.5]
supersedes: []
superseded_by: null
---
# D6 — P1 separates measured baseline from normative hardening contracts

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository recon in `research/P1-design.md`; RAG:✅ changing premises in `research/P1-design-rag.md`

## Решение

P1 состоит из machine-readable clean-worktree baseline и четырёх versioned contract
layers: trust/threat, compatibility/migration, guarantees/non-guarantees и resource/benchmark.
Baseline RED не чинится в P1 и не считается regression. Reachable PHP/Dart symbols не
считаются stable API до P7, а support не заявляется без именного green carrier.

## Почему

Альтернатива «один prose-report» не даёт freshness/digest checks и смешивает
наблюдение с обещанием. Альтернатива «чинить по мере baseline» уничтожает
дорефакторинговую точку и присваивает P1 API/schema решения owning phases.
Разделение сохраняет reproducibility и одного владельца каждого факта.

## Consequences

P1 не меняет runtime behavior. P2–P10 должны ссылаться на stable IDs/contracts,
а не на audit sketches. P1.5 обязан выявить любой unowned finding/threat/guarantee/
migration/resource gap до закрытия фазы.
