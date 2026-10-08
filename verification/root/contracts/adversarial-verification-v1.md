# Adversarial verification policy v1 — AgenTools (PLAN1)

**Version:** v1  
**Status:** P8.1 executable contract  
**Evidence date:** 2026-09-20  
**Machine roster:** `fixtures/hardening/adversarial/manifest.v1.json`  
**Decisions:** D13  
**Validator:** `tools/validate_adversarial_contract.py`

This policy names how P1–P7 guarantees become failure-oriented proofs. Exact IDs,
oracles, mutants, schedules, corpora and dispositions live only in the manifest.
Markdown must not duplicate those sets. `RAG:—` for repository ownership. Tooling
premises are `RAG:✅` in `research/P8-design-rag.md`.

## 1. Ownership

P8 owns evidence. It cannot rewrite an upstream semantic contract to make a red
proof green. A confirmed product defect returns to the exact owning item through
the plan repair/readiness protocol.

The manifest is the only exact `AV-###` set. Reports are digest-qualified
projections of that set.

## 2. Proof classes

| Class | Meaning |
|:--|:--|
| `example` | Named deterministic unit/integration case |
| `property` | Generated, replayable predicate over a typed or raw domain |
| `differential` | Cross-language byte/code/state comparison |
| `mutation` | Named semantic fault that ordinary tests must kill |
| `schedule` | Multi-process PostgreSQL interleaving with observed barriers |
| `resource` | Portable bound or fingerprint-bound timing envelope |

Critical security/durability rows require at least one independent or external
oracle **and** one demonstrated red fault (mutant or schedule). Duplicated
implementation assertions in both SDKs do not satisfy independence.

## 3. Workload tiers

Tiers are workload semantics, not CI jobs. P9 maps them later.

| Tier | Meaning |
|:--|:--|
| `focused` | One changed seam or one exact replay |
| `bounded` | Fixed corpus/seeds plus all promoted regressions |
| `extended` | Expanded deterministic corpus, full mutation and schedule set |
| `release-candidate` | Frozen inputs/dependencies plus all applicable critical evidence |

## 4. Oracles and replay

- JCS expected bytes come from the pinned independent oracle under
  `conformance/oracles/jcs/`. Neither SDK may rewrite them from its output.
- Confirmation/token/lease/event case oracles are the frozen
  `fixtures/hardening/*-v1.json` manifests plus P6 semantic profile.
- Package reachability uses `api/package-surface-v1.json`.
- Generated property/fuzz identity includes generator/corpus/manifest versions
  and digests, seed, case index, runtime, locale/timezone and input SHA-256.
- Valid typed generators and hostile raw-byte mutators are separate domains.

## 5. Mutation asymmetry

PHP may use scoped Infection for calibrated MSI/covered-MSI **signal**. The
release-bearing layer is the shared critical semantic mutant catalog. Dart
mutants are digest-bound patches in a disposable tree. A generic regex mutation
percentage is not comparable to Infection and is not a release claim.
Compile-invalid, unapplied, skipped, timeout and harness-error outcomes are
non-green.

## 6. PostgreSQL and resources

SQLite, in-memory stores, sleeps or a single process do not close PostgreSQL
rows. Those proofs are declared here as `schedule` IDs and executed by P8.4.
Resource timing may block only against a matching reviewed fingerprint; portable
limits gate everywhere. PHP/Dart speed is never compared. P8.5 owns calibration.

## 7. Gate

`python3 tools/validate_adversarial_contract.py` is red when:

- an `AV-###` id is duplicated or unsorted
- a row owner, proof class, tier or criticality is unknown
- a referenced mutant, schedule, property, corpus or oracle id is missing
- a catalog proof id is unreferenced (orphan)
- a critical row lacks an independent/external oracle or a named red fault
- a randomized row lacks algorithm, generator version, seed list, budget and
  promotion rule
- a terminal P1–P7 `G-*`/`NG-*`/`RL-*` or recorded residual lacks exactly one
  disposition
- the on-disk manifest is not canonical sorted JSON with matching source digests
