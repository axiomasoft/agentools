# P7 design — intentional API, testkit/reference boundaries and artifact consumers

**Date:** 2026-09-19  
**Tree anchor:** `3a0fc4de2c57e2d350ffa9ef252eb2162a7579bc` (`main`)  
**Evidence class:** `RAG:—` unless a row points to `P7-design-rag.md`.

## Design question

How can Agentools turn broad PHP autoload reachability and Dart transitive barrels into an
intentional, testable consumer contract, remove process-local/reference implementations from the
production default, and prove package artifacts in clean consumers without a massive directory
rewrite, an invented public registry decision, or a second owner for P2–P6 semantics?

## Repository observations

| Observation | Evidence | Design consequence |
|:--|:--|:--|
| PHP production autoload maps both `AgenTools\\` to `src/` and `AgenTools\\Reference\\` to `reference/`. | `php/composer.json` | Reference slice is reachable by every production dependency today; move its mapping to the root-only development contour and exclude it from the production archive. |
| PHP in-memory stores and `FixedClock` live below `src/`, while reference SQLite/product-slice code lives below `reference/`. | `php/src/Persistence/**`, `php/src/Idempotency/InMemoryDeduplicationStore.php`, `php/reference/**` | Test doubles need an explicit testkit classification; reference and testkit are distinct tiers even if both are dev-only. |
| Composer package metadata has no archive exclusions and no API allowlist. | `php/composer.json` | PSR-4 reachability alone cannot define stability. P7 needs a target manifest plus actual classmap/token inventory and real archive listing. |
| Dart core has one top-level `agentools.dart`, which transitively exports nearly every domain barrel, including `InMemoryEventLog`, `InMemorySessionStore`, `InMemoryDeduplicationStore` and `FixedClock`. | `dart/agentools/lib/agentools.dart`, nested barrels | Keep a narrow core library and expose supported test doubles only from `agentools_testkit.dart`. |
| Files under Dart `lib/src` can technically be imported, and current package tests sometimes do so for duplicate descriptor DTOs. | `dart/agentools/test/schema/*`, official Dart package guidance | `lib/src` is implementation by contract, not by technical impossibility. External consumer fixtures and analyzer checks must reject implementation imports. |
| Dart MCP transport is already a separate package and core has no `mcp_dart` dependency. | both Dart pubspecs and `agentools_mcp_dart/lib/**` | Preserve the split. P7 narrows its public wrapper and core import edge; it does not merge MCP into core or grow a full SDK. |
| The MCP companion depends on core by relative path and both packages use `publish_to: none`. | Dart pubspecs | Paired local artifact testing is valid private evidence, but a path dependency cannot prove pub.dev readiness. P9/P10 retain hosted-source/identity ownership. |
| P1 explicitly calls current PHP classmap and Dart barrels candidate surface until P7; stable API requires export, production consumer and behavioural proof. | P1.1/P1.3 specs; historical package-quality contract | P7 may reclassify accidental candidates without pretending every reachable class was stable, but may not remove a proved stable entry silently. |
| P2–P6 will add safe facades, durable ports/adapters and semantic profiles before P7 executes. | PLAN1 dependencies and P2–P6 item specs | Target manifests are finalized from executed predecessor outputs, not today’s source snapshot. Missing predecessor artifacts remain dependency-not-ready. |
| Historical P11 and the new audit both demand API manifests, archive inspection and clean consumers, but identity/release decisions remain unresolved. | `plans/AGENTOOLS/phases/P11/**`, F09/F12, D4/D5 | P7 owns package boundaries and private artifact consumers. P9 owns automation/supply chain; P10 owns final RC/publication decision. |

## Accepted boundary

### 1. One target contract, three package surfaces

P7.1 creates one policy contract and one exact machine contract covering:

1. PHP `agentools/sdk` core;
2. Dart `agentools` core plus its separate `agentools_testkit.dart` library;
3. Dart `agentools_mcp_dart` optional companion.

Each identity has a tier (`stable`, `advanced`, `testkit`, `reference`, `internal`), owning package,
supported import/FQN, behavioural consumer, migration state and P6 semantic link where applicable.
The tool records current, target and delta separately. It never turns visibility into stability.

### 2. PHP uses Composer contours, not a new package graph by default

The default target is one Composer core package:

- production `autoload` exposes the supported core namespaces;
- `AgenTools\\Reference\\` and `AgenTools\\TestKit\\` are root-only development mappings;
- reference/testkit/tests/conformance internals are excluded from the production archive;
- `src` has no dependency on development contours;
- in-memory/fixed implementations are testkit, not production presets;
- stable current FQNs stay compatible; accidental candidates move only under P1 policy with a
  recorded migration.

A separate published PHP testkit or adapter package is not created unless P7.1 proves a real
consumer and dependency reduction that Composer contours cannot provide. No such proof exists in
the current tree.

### 3. Dart uses explicit libraries inside core and keeps MCP separate

`agentools.dart` becomes the core supported entrypoint. A new `agentools_testkit.dart` is the only
supported test-double entrypoint. Implementation stays under `lib/src`; direct cross-package
`src` imports are rejected by lint/consumer fixtures. The bytes may remain in the same package,
because an extra physical package would create version/publication coupling without reducing core
runtime dependencies.

`agentools_mcp_dart` stays separate because it has a real optional runtime dependency on
`mcp_dart`. It imports only supported Agentools libraries. Its local path dependency is preserved
for private development and called out as a public-release blocker, never hidden by P7.

### 4. Stability and migration are evidence-based

An entry is stable only when all three are present: intentional export/autoload classification,
a production consumer and a behavioural test. Exact manifests reject missing and extra actual
entries. A proved stable removal requires a compatibility shim/deprecation window or a new finite
owner-gate. Candidate accidental reachability can become internal/testkit only when the P1
compatibility record and actual consumer scan support that disposition.

P7 changes packaging/ownership only. P2–P6 behaviour and the semantic manifest remain upstream
owners; their receipts rerun after moves.

### 5. Built artifacts, not source trees, decide the boundary

P7.4 stages artifacts in a disposable directory, records their sorted file lists and digests, then
installs/extracts them into clean PHP, Dart and paired Dart-MCP consumers. Consumers may not import
workspace paths or implementation libraries. Mutants prove that a forbidden file, missing stable
symbol and direct `src` import each turn the gate red.

The result is explicitly a private/local package receipt. P9 may wire the gate into CI and add
matrices/SBOM/provenance; P10 may form the final RC and request G-RELEASE. P7 does neither.

## Item decomposition

| Item | Product | Depends on | Why separate |
|:--|:--|:--|:--|
| P7.1 | policy, exact target roster and inventory/diff tool | terminal P1/P6 evidence | One owner before either SDK moves. |
| P7.2 | PHP core/dev autoload and archive boundary | P7.1 | Composer namespaces/classmaps and compatibility form one ecosystem delta. |
| P7.3 | Dart core/testkit exports and MCP companion boundary | P7.1 | Dart library privacy/exports and pub dependency rules are distinct from Composer. |
| P7.4 | built artifacts, migration compilation and clean consumers | P7.2–P7.3 | Evidence must run after both package surfaces settle and outside their source trees. |

```text
P1 + P6 -> P7.1 -> P7.2 --\
                    P7.3 ---> P7.4 -> P8/P9/P10
```

`api-boundaries` groups P7.1–P7.3 because inventory, target roster and both ecosystem deltas reuse
the same semantic/compatibility inputs. P7.4 is `solo`: isolation from the source tree is the
evidence boundary, so reusing an in-tree implementation session would weaken the claimed proof.

## Alternatives

### A. Physically split every core/testkit/storage package now

Rejected. It creates manifests, versions and release ordering without current independent consumers.
The observable boundary can be achieved through Composer dev autoload/archive rules and Dart public
libraries. A future split needs measured dependency or lifecycle value.

### B. Keep all reachable classes/exports public and document warnings

Rejected. Documentation cannot stop accidental production use or detect drift. Exact manifests,
dependency rules and built-artifact consumers are required.

### C. Treat all current reachability as stable API

Rejected. P1 intentionally labels the current classmap/barrels candidate surface, and many entries
are process-local test doubles or reference-project code without production consumers.

### D. Hide Dart implementation only by moving files under `lib/src`

Rejected. `lib/src` is an ecosystem convention, not a technical access barrier. P7 also needs
explicit public libraries, analyzer rules and external consumer tests.

### E. Merge `agentools_mcp_dart` into core

Rejected. It would add an optional protocol dependency to pure core and contradict P6’s bounded MCP
applicability decision.

### F. Fix the companion path dependency by selecting pub.dev now

Rejected. Registry, legal identity and publication remain D5/P9/P10 decisions. P7 records the
blocker and tests paired private artifacts.

## Validation strategy

1. Inventory every actual reachable FQN/export and require one target disposition.
2. Compare target and actual exact sets after each ecosystem move; missing and extra are red.
3. Prove zero production core→testkit/reference/optional-adapter edges.
4. Rerun PHP/Dart P6 semantics and package-native suites after ownership-only moves.
5. Build and inspect actual package artifacts twice for deterministic file/content digests.
6. Install artifacts into clean consumers and compile both supported usage and migration snippets.
7. Run negative mutants for undeclared API, forbidden archive file and external `src` import.
8. Keep public publication/hosted dependency checks visibly unresolved rather than manufacturing
   release readiness.

## Risks retained by design

- PHP visibility cannot make every internal class unreachable under a broad namespace; the stable
  contract is the exact manifest plus consumer tests, not a false language-level privacy claim.
- Dart `lib/src` remains technically importable. Analyzer and consumer policy make it unsupported,
  while stable entrypoints remain explicit.
- A same-package Dart testkit still ships bytes with core. It is acceptable because it adds no core
  dependency/default export; P9 archive evidence measures actual cost and may justify a later split.
- The MCP companion cannot be publicly published with its current path dependency. P7 labels this
  blocker; only D5/P9/P10 may choose hosted identity/version.
- P7 target entries depend on executed P2–P6 outputs. Design does not guess future class names or
  turn declared predecessor artifacts into evidence.
