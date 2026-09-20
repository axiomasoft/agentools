# P8 design — adversarial verification, deterministic schedules and resource evidence

**Date:** 2026-09-19  
**Tree anchor:** `445a8b29769fb918c846545190304494b1483981` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P8-design-rag.md`.

## Design question

How can Agentools prove that the P2–P7 gates actually distinguish unsafe implementations across
PHP and Dart, real PostgreSQL concurrency and bounded hostile inputs, without inventing one vanity
score, depending on unreproducible randomness or timing, or taking P9 CI/release ownership?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| Current PHP/Dart tests are deterministic examples; no Infection config, mutation manifest, PBT/fuzz harness or benchmark suite exists. | `php/composer.json`, Dart pubspecs, repository scan | P8 must create proof infrastructure, not merely invoke a hidden existing tool. |
| Current CI runs four conformance layers on PHP 8.4 only. | `.github/workflows/ci.yml` | P8 defines portable commands/tier semantics; P9 later owns workflows, matrices and permissions. |
| P2–P7 designs already require negative mutants, PostgreSQL races, parity and archive defects to turn red. | item Validation fields P2.1–P7.4 | P8 must inventory and deepen these requirements rather than duplicate them under new IDs. |
| P3 consume uses unique `(tenant_id, jti)` insertion, P4 uses lease-token CAS/takeover, P5 locks stream headers and classifies event replay/collision. | D8–D10 and P3–P5 specs | Storage proof needs three different schedules/oracles; a generic “50 workers passed” result is insufficient. |
| P6 supplies an independent pinned JCS oracle and a 10k number corpus; 100M stress and deeper fuzz remain explicitly P8-owned. | P6.3, D11 | Reuse P6 bytes/oracle. Do not let either SDK generate expected output or rerun the same conceptual implementation as “independent”. |
| P1.5 forbids absolute cross-machine timing gates and requires environment/raw-sample provenance. | P1.5 | Timing can block only on matching fingerprint; portable limits/complexity/resource ceilings carry the broader gate. |
| Audit F10 accepts mutation/property/fuzz/concurrency/performance only with baseline-first qualification. | `findings/audit-verdicts.md` | Thresholds and case budgets need measured, versioned evidence rather than copied industry percentages. |
| PHP has AST-aware Infection; the current Dart package found by retrieval performs regex/text replacement and is from an unverified uploader. | `research/P8-design-rag.md` | Use Infection for broad PHP signal. For Dart, gate only a curated digest-bound semantic mutant catalog; never compare a Dart percentage with PHP MSI. |
| `benchmark_harness` is maintained by the Dart tools publisher but warns that timing comparison needs the same benchmark, machine and OS. | `research/P8-design-rag.md` | It is suitable pinned dev tooling, not a universal latency oracle. |
| Existing conformance adapters already use fixed clock/seed and a line protocol. | `conformance/adapters/**`, PHP/Dart conformance engines | Extend the process pattern for shared corpus execution; keep storage workers isolated because transaction/process failure is a different seam. |

## Accepted verification model

### 1. One exact risk/proof manifest

P8.1 creates an `AV-###` manifest. Each row points to one upstream guarantee/resource/API owner and
declares applicable SDK/storage surfaces, criticality, independent oracle, required proof classes,
named faults, finite workload tiers and exact failure ownership. This is the set owner; reports are
digest-qualified projections, not new handwritten coverage lists.

Four workload tiers describe evidence cost, not CI implementation:

1. `focused` — one changed seam or one exact replay;
2. `bounded` — fixed corpus/seeds plus all promoted regressions;
3. `extended` — expanded deterministic corpus, full mutation and schedule set;
4. `release-candidate` — frozen inputs/dependencies plus all applicable critical evidence.

P9 may map these tiers to pull request, merge, scheduled or release jobs. P8 does not edit workflow
files or assume runner properties.

### 2. Deterministic property/fuzz evidence is portable data

P8.2 uses a repository-owned stable PRNG and generator version so PHP and Dart consume identical
bytes. A seed alone is insufficient: every run records generator/corpus/manifest digests, case
index, runtimes, locale/timezone and input digest. Valid typed values and hostile raw bytes are
separate domains so duplicate keys, malformed Unicode or numbers cannot disappear in a permissive
pre-parser.

Required properties include:

- JCS byte idempotence, formatting invariance, UTF-16 order, raw-invalid rejection and no Unicode
  normalization, with P6’s independent RFC/upstream bytes as oracle;
- token sign/verify and one-bit corruption; exact time/binding/tenant/purpose/key-state partitions;
- fingerprint separation and collision fixtures;
- legal state transitions and refusal of illegal transition/error-code drift;
- P1 hard bounds at `limit-1`, `limit`, `limit+1`, plus bounded growth-series inputs.

Shrinking is deterministic and property-preserving. A failure is promoted as a minimized immutable
regression with origin metadata; neither SDK output can overwrite its expected bytes automatically.

### 3. Mutation evidence is deliberately asymmetric

PHP uses Infection against named critical non-generated namespaces. Coverage and JUnit inputs are
fresh and digest-bound; timeouts count as escaped. The initial green run and reviewed equivalent
mutants establish scope-specific MSI/covered-MSI floors. Floors can rise; lowering needs an explicit
new decision/evidence delta.

The release-bearing layer is the shared semantic mutant catalog, not a percentage. It contains
faults such as authorization bypass, token integrity/time/binding omission, consume replay, stale
fence acceptance, unknown-capability allow, event collision overwrite, batch partial commit,
redaction omission, JCS sort/number error and hard-bound removal. Every applicable critical mutant
must be validly applied and killed by ordinary tests.

For Dart, P8 uses digest/anchor-qualified patches in a disposable tree. The available generic Dart
tool is active but performs regex/text replacement; its score is not semantically comparable to
Infection. A repository-owned exact catalog is smaller but auditable and aligned to the guarantees.
Compile-invalid, unapplied, timed-out and harness-error mutants are non-green, never convenient kills.

### 4. PostgreSQL schedules prove state, not probability

P8.4 runs independent PHP/Dart worker processes and physical connections. The controller advances a
versioned schedule only after an acknowledged barrier and, for blocking steps, an observed PostgreSQL
backend/lock relation. `application_name`, backend PID, `pg_stat_activity` and `pg_blocking_pids`
identify the exact actor. Sleeps may bound polling backoff but cannot appear as a schedule proof.

The minimum scenario families are:

- replay consume: conflicting uncommitted insert followed by commit and rollback, restart, tenant
  separation and collision;
- lease/fence: concurrent first acquire, expiry/takeover, stale heartbeat/complete/fail, ambiguous
  post-effect outcome, capability branches and declared clock-skew bounds;
- event store: concurrent expected-version append, full batch rollback, identical replay, content
  collision, partial overlap and keyset pagination with concurrent committed growth.

Client `SIGKILL`, query cancel and backend termination remain distinct receipt values. After every
fault, a fresh verifier connection checks durable state. This proves client/transaction boundaries,
not power-loss/WAL behavior and never exactly-once external effects.

### 5. Resource evidence separates portable and host-bound claims

Portable gates cover bytes, depth, element/page/query/row counts, peak resource ceilings supported
by the runtime, output amplification and geometric growth envelopes. They may run anywhere with the
same product inputs. Timing baselines additionally bind the exact host/runtime/dependency fingerprint,
workload digest, warmup, raw samples and statistics. A mismatched fingerprint yields
`baseline_pending`/informational evidence, not pass or fail.

P8.5 aggregates separate namespaces—property/fuzz, PHP mutation, semantic mutants, storage schedules
and resources—by exact `AV-###` sets and child digests. It does not calculate one combined quality
percentage. Critical correctness evidence cannot be quarantined. A noncritical quarantine needs an
owner, lost-coverage statement, reason and expiry.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P8.1 | normative contract, exact AV manifest and validator | terminal P1–P7 inputs | One owner before generators, mutants and schedules diverge. |
| P8.2 | deterministic corpus, shrink/promotion and differential receipt | P8.1 + P6 oracle | Shared ordinary tests must exist before measuring mutant sensitivity. |
| P8.3 | PHP Infection baseline and cross-language critical mutant receipt | P8.2 | Mutation evaluates the settled ordinary suite, not a moving harness. |
| P8.4 | real PostgreSQL worker schedules and crash traces | P8.1 + terminal P3–P5 | DB/process isolation and safety preflight are a distinct execution contour. |
| P8.5 | resource baselines, quarantine policy and aggregate receipt | P8.2–P8.4 | Calibration and aggregation consume all child evidence after it stabilizes. |

```text
P1–P7 -> P8.1 -> P8.2 -> P8.3 --\
               \-> P8.4 ---------> P8.5 -> P9/P10
```

`adversarial-core` groups P8.1–P8.3 because the risk manifest, generated corpus and critical mutant
catalog share the same source/test graph. P8.4 is `solo` because isolated real-PostgreSQL processes,
fault cleanup and backend observation are a different safety boundary. P8.5 is `solo` because it
requires all immutable child receipts and a stable measurement fingerprint.

## Alternatives

### A. Use line/branch coverage as the only quality gate

Rejected. Coverage proves execution, not assertion sensitivity or concurrency semantics. Named
mutants and schedules demonstrate that dangerous behavior turns red.

### B. Require one global MSI for PHP and Dart

Rejected. Infection is AST-aware; the evaluated Dart tool is regex/text based. Their mutant spaces,
invalid-mutant rates and percentages do not represent the same evidence.

### C. Adopt young PBT libraries in both SDKs

Rejected as the shared evidence owner. Current candidates have compatibility/maturity uncertainty
and would generate different inputs. They may be evaluated later as local helpers, but the normative
corpus, replay and shrink format remains language-neutral and repository-owned.

### D. Use many workers plus sleeps

Rejected. Probability is not an interleaving proof. Explicit barriers and PostgreSQL wait/blocker
observation establish which transaction state was reached.

### E. Reuse SQLite/in-memory storage for speed

Rejected for P3–P5 guarantees. It may test a port contract, but not PostgreSQL unique-index,
visibility, locking, transaction or backend-failure semantics.

### F. Gate a universal p95 latency

Rejected. Shared runner load and machine/runtime differences create false verdicts. Portable
limits/growth gate everywhere; timing blocks only on a matching reviewed fingerprint.

### G. Let P8 repair upstream semantics when a test fails

Rejected. P8 owns evidence. A confirmed product/contract defect returns to the exact owner through
the plan repair/readiness protocol; tests and guarantees are not weakened to preserve phase order.

## Validation strategy

1. Validate exact risk/proof coverage and prove the validator catches malformed/unowned rows.
2. Replay identical generated cases twice; inject one-sided PHP and Dart defects and require red.
3. Apply every critical mutant in a disposable tree; require a valid application and ordinary-test kill.
4. Execute explicit PostgreSQL schedules and verify durable invariants from a fresh connection.
5. Verify safety preflight/cleanup against non-test DSNs and missing run namespaces.
6. Calibrate portable resource gates and refuse timing comparison on fingerprint mismatch.
7. Aggregate exact child IDs/digests with no stale/missing/extra rows and no combined pass percentage.
8. Rerun existing semantic, package and frozen-revision gates; keep P9/P10 ownership intact.

## Risks retained by design

- Curated Dart mutants cover named dangerous faults, not every possible AST mutation. The manifest
  makes that finite claim visible; property/differential and review remain complementary.
- Client/backend failure schedules do not certify PostgreSQL against power loss or filesystem/WAL
  faults. P8 states this limitation instead of overclaiming durability.
- A benchmark is only comparable under its recorded fingerprint and workload digest. P9 may need a
  stable runner to operationalize timing gates.
- The optional 100M JCS number stress run may exceed available execution budget. P8 can retain it as
  `extended` evidence while the P6 10k oracle remains the mandatory semantic baseline.
- P8 execution occurs after upstream phases; a newly exposed defect may require a justified return
  to its owning item. That is evidence recovery, not permission to patch product behavior in P8.
