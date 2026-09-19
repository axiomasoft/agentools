# P1 design — baseline, trust, compatibility, guarantees, resources

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P1-design-rag.md`.

## Design question

How can P1 preserve the observable pre-hardening state and give P2–P10 one coherent
security/durability/compatibility vocabulary without implementing any remediation or mistaking
current package reachability for a stable public API?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| CI invokes four `tools/ci_gate.py` layers only. | `.github/workflows/ci.yml`; `tools/ci_gate.py` | P1.1 captures native PHP/Dart/package probes separately; P9 later decides the release matrix. |
| Layer 3 executes registered PHP/Dart core tests; the MCP Dart companion is absent. | `conformance/sdks.json`; `dart/README.md` | Baseline must name companion analyze/test as an independent carrier. |
| Composer exposes all `AgenTools\` classes under `php/src/` and also maps `reference/`. | `php/composer.json` | P1 records reachable symbols as candidates, never as stable API; P7 owns intentional API/archive boundaries. |
| Dart's public barrel transitively exports confirmation, contracts, in-memory persistence and idempotency stores. | `dart/agentools/lib/agentools.dart` and sub-barrels | P1 records exports; P7 decides core/testkit separation and migrations. |
| PHP declares `>=8.3`; Dart core and companion declare `^3.11.0`; packages are proprietary/private. | package manifests and `LICENSE` | Support contract distinguishes declared, observed, tested and release-supported values. |
| Current host is PHP 8.4.1, Composer 2.8.3 and Dart 3.13.4. | version probes on 2026-09-19 | A local green run proves only one observed tuple, never the declared floor. |
| Confirmation accepts caller-supplied time/principal/authorizer/descriptor/dual-control data and keeps spent tokens in process memory in both SDKs. | F01–F03; PHP/Dart confirmation code | P1.2 treats application integration as partially trusted and routes remediation to P2/P3. |
| Idempotency has `resolve`/`complete` without lease/fence; EventLog has single append and unbounded `readFrom`. | F04/F05; PHP/Dart ports | Guarantees catalog must explicitly deny exactly-once effects and name P4/P5 proof. |
| Implemented limits cover expression depth/length/collection/visits, 64 scenario steps and baggage 64 items/8192 units; several ingress/output limits are absent. | PHP/Dart scenario and ops code | P1.5 records unit semantics and `missing`; P8 owns calibration and adversarial proof. |
| Historical status uses `1970-01-01` migration sentinels and consumer READMEs are stale relative to completed SDK phases. | `plans/AGENTOOLS/status.md`; root/package READMEs | Baseline labels known anomalies; P1 does not rewrite historical evidence or public docs. |

## Alternatives

### A. One prose audit addendum

Rejected. It cannot distinguish an inherited RED from a new regression, cannot be checked for
freshness and would make public symbols, platform support and package archives manual claims.

### B. Implement fixes while collecting baseline

Rejected. The first fix changes the state being measured and steals API/schema choices from P2–P7.
P1.1 therefore treats a completed non-zero probe as valid baseline evidence.

### C. Machine baseline plus versioned contracts

Accepted in D6. One canonical JSON manifest owns measurements; Markdown projects them for humans.
Separate stable contracts own threat boundaries, compatibility, guarantees and resources. This
keeps measurement, normative promises and future proof receipts distinct.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P1.1 | collector + JSON/report baseline | current tree | Measurement must precede normative reinterpretation and code changes. |
| P1.2 | `threat-model-v1` | P1.1 | Trust claims bind to the observed call/storage boundaries. |
| P1.3 | compatibility/support + migration inventory | P1.1 | Platform/API migration facts use the same baseline but can proceed alongside P1.2. |
| P1.4 | guarantees/non-guarantees catalog | P1.2 + P1.3 | Promise wording needs both threats and compatibility assumptions. |
| P1.5 | resource envelope + cross-contract coverage | P1.1–P1.4 | Final integration gate detects unowned findings/contracts before downstream execution. |

## Dependency and execution shape

```text
P1.1 ─┬─> P1.2 ─┐
      └─> P1.3 ─┴─> P1.4 ─> P1.5
```

`foundation` groups P1.1–P1.3 because baseline setup and inventory are shared; P1.2 and P1.3
may run in dependency-safe order after P1.1. `contracts` groups P1.4–P1.5 because both consume
the same frozen P1 contract set. Both groups are `recommended:`, not atomic.

D2 remains stronger than item readiness: none of these items executes until P1–P10 detail and the
whole-plan design audit are GREEN.

## Contract boundaries

- Measurement owner: `artifacts/P1-baseline.json`; the Markdown report is its human projection.
- Threat owner: `root/contracts/threat-model-v1.md`.
- Compatibility owner: `root/contracts/compatibility-support-v1.md`; migrations are findings.
- Promise owner: `root/contracts/hardening-guarantees-v1.md`.
- Resource owner: `root/contracts/resource-envelope-v1.md`.
- Cross-contract completeness owner: `findings/P1-contract-coverage.md`.
- Current implementation status never upgrades because a contract says `designed`; only later
  immutable execution evidence can make a guarantee `proved`.

## Validation strategy

1. Capture every declared probe to terminal `green|red|unavailable`; only missing/incomplete
   capture or unexplained product mutation makes P1.1 RED.
2. Check stable IDs, closed statuses and exact owner cardinality in all contracts.
3. Require all F01–F14, threats, migrations, guarantees and resource gaps in the P1.5 coverage map.
4. Compare PHP/Dart semantics and units explicitly; equal-looking numeric defaults are insufficient.
5. Run generated-view checks and `plan-lint --gate all`; do not persist future bundles whose
   predecessor artifacts do not yet exist.

## Risks retained by design

- Network/tool availability can yield an honest `unavailable` baseline probe; P1.1 cannot invent it.
- Current package dry-runs may be intentionally RED because identity remains private/proprietary.
- Current public reachability is broader than intentional API; P7 must reconcile it before 1.0.
- P1 can define proof obligations but cannot prove the P2–P10 future guarantees.
- Perplexity was initially absent from the active client tool surface, then successfully rechecked
  through the configured local MCP server. Its output remains discovery/corroboration evidence,
  while the linked official sources are the proof boundary. The native web connector remained
  transiently unstable.
