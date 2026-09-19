# P6 design — successor semantics, parity, JCS and MCP applicability

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P6-design-rag.md`.

## Design question

How can Agentools turn the P2–P5 security and durability contracts into one versioned,
machine-checkable PHP/Dart semantic surface, prove RFC 8785 bytes against an independent oracle,
and use the official MCP conformance suite only where it actually applies, without rewriting the
frozen `2026-08` revision, reviving SDK source generation, claiming certification, or taking P7
package-export scope?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| The frozen `2026-08` error schema owns 17 core codes. PHP repeats all 17 in one enum; Dart literals are split by subsystem and three schema codes are absent from `lib/src/**`. | `schema/error.json`, `ToolErrorCode.php`, Dart `*_error_codes.dart`; exact-set probe on 2026-09-19 | A generated semantic manifest plus two SDK profile exporters must compare exact sets. Source generation remains forbidden; drift is detected, not hidden. |
| Error category, default retryability, producer rule and safe context currently live in different carriers. `retryable` is instance-authoritative under IDE-28. | `09_idempotency-errors.md` IDE-28…IDE-33; `ErrorCategory` in both SDKs | The successor revision keeps the normative table as owner. The derived manifest records category, default retryability, producer rules and allowed context keys, while case manifests retain instance retryability. |
| P2/P3 intentionally defer exact new code spellings to their P2.1/P3.1 outputs; P4/P5 already name additional code intents. | P2–P5 design research and item specs | P6.1 consumes executed contracts/case manifests; it must not guess codes before those predecessors exist. |
| `2026-08` is hard-coded across manifests, scenarios, adapters, runner helpers and reference paths. | repository `rg spec_rev` inventory | A successor is a real compatibility migration, not an enum-only patch. `2026-09` is self-contained and has an explicit base/compatibility record. |
| Both canonicalizers pass the current small RFC-derived corpus, but the current suite is five upstream files plus local edge cases and is not an exhaustive independent number oracle. | focused PHP/Dart tests: 10/10 and 7/7 green; current fixtures | P6.3 imports digest-pinned upstream oracle data, adds deterministic number coverage, invalid raw JSON cases and differential receipts. Existing green tests are baseline, not proof of the stronger gate. |
| PHP uses a runtime package plus local duplicate-name validator; Dart uses hand-written string and number serialization. | both `Canonicalizer` implementations | Cross-language equality alone can preserve the same bug. Expected bytes come from RFC/upstream oracle, never from one SDK generating the other's golden. |
| The repository's conformance runner judges Agentools rules. It is not the official MCP suite. | `conformance/**`; F08 | Keep two named report families and never merge their pass rates. |
| The only shipped MCP-specific package is `agentools_mcp_dart`, a bounded client transport over `mcp_dart`; PHP ships no MCP transport and this repository ships no MCP server. | manifests and Dart transport source | Official server requirements are non-applicable here. Client scenarios are classified against the wrapper's declared surface; underlying `mcp_dart` is not relabelled as Agentools evidence. |
| The current Dart transport is modern-only (`McpProtocol.require2026`) and exercises `server/discover`, `tools/list`, `tools/call`, task state and MRTR-shaped results. | transport tests and committed negative receipt | Official tools-client scenarios can be evidence when driven through the public wrapper. Auth, prompts, resources and a complete MCP-SDK tier remain outside its contract. |
| Stable official conformance `0.1.16` lacks `--requirements 2026-07-28`; prerelease `0.2.0-alpha.11` exposes frozen requirements and reports 69 scored scenarios. | live CLI probes; `P6-design-rag.md` | Pin `0.2.0-alpha.11` and its npm integrity in a committed dev-only lock. Never float `latest` or install from `main`. |
| D4 forbids public tags/releases before G-RELEASE. Existing lockstep assumes an annotated spec tag. | PLAN1 D4; `conformance/bin/lockstep` | P6 uses a committed candidate revision lock/digest. P9/P10 may bind a public tag only after the release gate; P6 does not mint or push one. |

## Accepted boundary

### 1. Successor revision and ownership

P6 creates self-contained candidate revision `spec/revisions/2026-09/` and corpus
`fixtures/2026-09/`. Frozen `2026-08` and tag `spec-2026-08` remain byte-identical. The new
revision copies unchanged norms only to provide a complete reader boundary, then integrates the
executed P2–P5 contracts with new rule IDs, schemas, cases and migration notes. It records:

- base revision and base tag/digest;
- compatibility class for every changed public semantic;
- the complete error table, state/transition vocabularies and capability claims;
- canonicalization profile and spec-revision negotiation;
- a candidate `revision.lock.json` containing source digests, generated deterministically;
- status `candidate`, never `frozen` or published by P6.

The normative Markdown/schema/case carriers remain the owners. A generated
`semantic-manifest.json` is a digest-qualified projection, not a second handwritten source.

### 2. Semantic profile and manual DTO freshness

`tools/semantic_manifest.py` extracts one deterministic `semantic-profile/v1` from the successor
revision and hardening case manifests. PHP and Dart each expose a test/CLI profile assembled from
their actual constants, state machines and capability declarations. The comparator checks exact
sets and values for:

- error code, category, default and case-level retryability, producer rule and safe-context keys;
- public states and allowed transitions;
- token/lease/event envelope versions and bounds;
- idempotency, replay, fencing, collision and pagination outcomes;
- canonicalization profile/version;
- supported spec revision and declared conformance level;
- capability identifiers and their supported/unsupported semantics.

No SDK source is generated. A new manifest row without a corresponding PHP and Dart row is red;
an extra SDK row is equally red. Report rows are sorted and carry source digests.

### 3. JCS oracle gate

The independent oracle is RFC 8785 plus the upstream `cyberphone/json-canonicalization` corpus at
commit `19d51d7fe467d4706a3ff08adf8a748f29fc21e0`. P6 vendors only the necessary Apache-2.0 test
data/reference helper with provenance and license notice. The mandatory bounded gate contains:

1. all six upstream object/string/array files and their expected UTF-8 bytes;
2. the deterministic first 10,000 ES6-number rows with upstream checksum
   `b9f7a8e75ef22a835685a52ccba7f7d6bdc99e34b010992cbc5864cd12be6892`;
3. UTF-16 key-order cases across BMP/non-BMP boundaries;
4. negative raw JSON for duplicate names, invalid Unicode/surrogates and malformed numbers;
5. programmatic rejection of NaN and infinities;
6. no Unicode normalization, BOM or trailing newline in canonical bytes.

The 100-million-row upstream corpus is optional stress evidence owned by P8, not a P6 close gate.
On mismatch, the report prints the case id, input digest, expected/actual byte digests and first
different byte; it never silently rewrites goldens from an SDK result.

### 4. Official MCP applicability

P6 pins `@modelcontextprotocol/conformance@0.2.0-alpha.11` exactly, with npm integrity
`sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA==`.
The checked-in applicability matrix is generated from
`list --requirements 2026-07-28` and classifies every scored/not-scored scenario as:

- `applicable` — exercised through an Agentools-owned public boundary;
- `non_applicable` — role/feature is not shipped, with a concrete contract reason;
- `blocked_dependency` — intended surface exists but the pinned dependency cannot exercise it;
- `failed` / `passed` — only for an actually executed applicable scenario.

The repository ships no MCP server, so server-role requirements are non-applicable, not green.
The Dart wrapper is not a full MCP SDK. Only scenarios reachable through its declared tools/MRTR/
task client boundary may be marked applicable. The runner executes those scenarios individually at
the `2026-07-28` wire and stores raw official receipts. A partial scenario set is labelled
`official MCP scenario compatibility`; it is never called certification, Tier 1, or full MCP
client/server conformance. Expected-failure baselines cannot turn non-applicability into a pass.

### 5. Integrated P6 gate

P6.5 produces one cross-language receipt with distinct sections:

- Agentools successor revision lint/coverage and candidate lock;
- semantic-profile exact-set parity;
- PHP and Dart successor corpus results;
- independent JCS oracle results;
- official MCP applicability and executed-scenario receipts;
- frozen `2026-08` immutability;
- migration residuals assigned to P7/P8/P9/P10.

The aggregate is green only when every applicable check is green and each non-applicable MCP row
has a reason. It does not combine Agentools and MCP pass counts into one percentage.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P6.1 | successor `2026-09` semantics/error contract and migration | executed P2–P5 contracts/cases | One normative owner before SDK drift machinery. |
| P6.2 | generated semantic manifest, PHP/Dart profile exporters and exact-set comparator | P6.1 and implemented P2–P5 SDK surfaces | Proves manual DTO/constants freshness without source generation. |
| P6.3 | pinned independent JCS corpus/oracle and differential gate | P6.1 | Canonical bytes need evidence independent of either SDK. |
| P6.4 | official MCP pin, applicability matrix, Dart wrapper driver and receipts | P6.1 | External protocol evidence remains distinct from Agentools conformance. |
| P6.5 | successor corpus/adapters, candidate lock and integrated phase receipt | P6.2–P6.4 | Only the final item joins already-owned evidence and hands residuals downstream. |

```text
P2–P5 execution -> P6.1 -> P6.2 ->\
                             P6.3 ---> P6.5
                             P6.4 ->/
```

`semantic-core` groups P6.1–P6.3 because revision, generated profile and JCS bytes share the
same schemas/fixtures and loaded cross-language context. `conformance-evidence` groups P6.4–P6.5
because the official applicability receipt and integrated gate share the pinned runner/reporting
surface. Both are `recommended:`; dependency-safe subsets remain executable after D2 opens stage 3.

## Alternatives

### A. Patch `2026-08` in place

Rejected. The revision and tag are frozen evidence for the historical AGENTOOLS plan. Mutating it
would invalidate current reports and make old consumers silently observe new semantics.

### B. Handwrite a parity manifest beside the schemas

Rejected. It would create a second owner for codes, states and retryability. The manifest is a
generated projection with source digests and a freshness check.

### C. Generate PHP/Dart source from schemas

Rejected. DR-02 already chose handwritten DTOs after measured generator loss/crashes. P6 supplies
the missing exact-set freshness gate; it does not reopen that decision.

### D. Compare PHP output only to Dart output

Rejected. The two implementations can agree on the same bug. JCS expected bytes come from RFC and
the pinned independent corpus.

### E. Run the entire official requirement set and baseline unsupported scenarios

Rejected. The repository ships neither a server nor a general MCP SDK. Baselines describe known
failures of an applicable implementation; they do not make absent roles/features conformant.

### F. Call the existing Agentools harness “official MCP conformance”

Rejected. They have different subjects, rule sources, transports and report semantics. Both remain
useful only under distinct names.

### G. Mint `spec-2026-09` during P6

Rejected. D4 reserves public tag/release actions for G-RELEASE. P6 emits a committed candidate lock;
P9/P10 bind release artifacts and any public tag after authorization.

## Validation strategy

1. Exact-set profile comparison rejects missing and extra rows in both SDKs.
2. Every P2–P5 adversarial case maps to a successor rule, result and semantic error row.
3. Both SDKs pass the same successor vectors; independent oracle bytes decide JCS.
4. A mutant code/category/retryability/state/capability and a JCS byte mutant each turn the relevant
   gate red with a named mismatch.
5. Official MCP execution is reproducible from the exact package lock and raw receipts; all 69
   scored requirement rows are accounted for even though only a bounded client subset is applicable.
6. `git diff --exit-code -- spec/revisions/2026-08 fixtures/2026-08` stays clean.
7. P6 produces no public package export decision, release tag, registry upload or runtime Node
   dependency.

## Risks retained by design

- The official MCP conformance package is prerelease. Exact pin/integrity makes the run
  reproducible but does not make its API stable; P9 owns dependency/supply-chain update policy.
- A bounded tools-client receipt does not establish auth, prompts, resources, server or SDK-tier
  conformance. The applicability report must keep those rows visibly non-applicable.
- The 10,000-number JCS gate is strong bounded evidence, not an exhaustive proof over all IEEE-754
  values; P8 may add the 100-million stress corpus or property/mutation depth.
- P6 can only publish semantics that P2–P5 actually delivered. Missing predecessor artifacts are
  dependency-not-ready, never permission to invent replacement contracts.
- `2026-09` remains a candidate until downstream package/release acceptance. Its committed lock is
  stable input, not a public release claim.
