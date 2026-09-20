---
id: D13
date: 2026-09-19
status: accepted
item: P8
items: [P8, P8.1, P8.2, P8.3, P8.4, P8.5]
supersedes: []
superseded_by: null
---
# D13 — Failure-oriented evidence without cross-language vanity scores

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository recon in `research/P8-design.md`; Perplexity synthesis plus RAG:✅ primary sources in `research/P8-design-rag.md`

## Решение

P8 introduces one normative adversarial-verification contract and exact `AV-###` manifest. Every
critical P1–P7 guarantee is bound to its upstream owner, independent oracle, finite workload tier,
named property/mutant/schedule/resource proofs and digest-qualified receipt. P8 cannot change an
upstream semantic contract to make a failing proof green; a confirmed defect returns to its exact
owner through the plan repair/readiness protocol.

Generated property/fuzz evidence is language-neutral and deterministic. Reproduction identity
includes PRNG/generator/corpus/manifest versions and digests, seed, case index, runtime environment
and input digest. Minimized failures are promoted to immutable regressions; neither SDK is allowed
to rewrite expected data from its own output.

Mutation evidence is intentionally asymmetric. PHP uses scoped, locked Infection with fresh
coverage/JUnit evidence, calibrated MSI/covered-MSI floors and timeouts treated as escaped. PHP and
Dart both must kill the same applicable curated critical semantic mutant catalog. Dart mutants are
exact digest-bound patches in a disposable tree; a generic regex/text mutation percentage is not
called equivalent to Infection or used as a release claim. Invalid, unapplied, skipped, timed-out or
harness-error mutants are non-green.

PostgreSQL durability/concurrency evidence uses separate worker processes/connections, explicit
controller barriers and observed backend/blocker state. SQLite, in-memory stores, sleeps or a single
process do not close PostgreSQL rows. Client crash, query cancel and backend termination are separate
fault classes; server/power-loss WAL certification and exactly-once external effects remain excluded.

Resource evidence separates portable bounds/growth/resource ceilings from host-bound timing.
Timing can block only against a reviewed baseline with matching environment and workload fingerprint;
otherwise it is informational or `baseline_pending`. PHP and Dart speed is never compared. P8 defines
workload tiers and receipts; P9 alone maps them to CI, and P10 accepts the release candidate.

## Почему

Coverage and pass counts can stay green when a security check is removed. A seed without generator
identity is not durable replay evidence, many workers plus sleeps do not prove a database
interleaving, and an absolute latency threshold on an arbitrary runner produces false verdicts.
The current PHP and Dart mutation ecosystems also expose different mutant semantics, so a combined
score would be precise-looking misinformation. Exact faults, schedules, corpora and fingerprints
make each claim reviewable and reproducible.

## Consequences

P8 has five items: shared contract/manifest; deterministic property/differential/fuzz corpus; PHP
Infection plus cross-language critical semantic mutants; isolated PostgreSQL crash schedules; and
resource calibration/aggregate receipt. P9 consumes the tiered commands and receipts without
redefining evidence. P10 consumes the aggregate P8 receipt. Any RED against terminal P2–P7 semantics
is routed to its owner rather than patched opportunistically inside P8.
