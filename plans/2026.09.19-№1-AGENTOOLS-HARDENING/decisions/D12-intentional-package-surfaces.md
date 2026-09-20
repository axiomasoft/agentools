---
id: D12
date: 2026-09-19
status: accepted
item: P7
items: [P7, P7.1, P7.2, P7.3, P7.4]
supersedes: []
superseded_by: null
---
# D12 — Intentional package surfaces without speculative physical split

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository recon in `research/P7-design.md`; RAG:✅ official Composer and Dart sources in `research/P7-design-rag.md`

## Решение

P7 defines one normative package-boundary policy and one exact machine-readable target roster for
PHP core, Dart core/testkit and the Dart MCP companion. Reachability is candidate inventory, not a
stability promise. A stable entry requires intentional export/autoload classification, a production
consumer and behavioural proof; missing or extra actual entries fail the gate.

PHP remains one production Composer package by default. `AgenTools\\Reference\\` and
`AgenTools\\TestKit\\` are root-only development contours and are excluded from the production
archive. Process-local in-memory/fixed implementations are testkit, never production defaults.
Stable current FQNs remain compatible; an accidental candidate may move only with a recorded P1
compatibility disposition.

Dart core remains one package with two intentional libraries: `agentools.dart` for supported core
and `agentools_testkit.dart` for test doubles. `lib/src` is implementation and external imports are
unsupported and linted, but it is not described as technically inaccessible. `agentools_mcp_dart`
stays a separate optional package and imports only supported core libraries.

No public registry or hosted dependency is selected in P7. The companion’s path dependency is tested
only as a paired private artifact and stays an explicit P9/P10 public-release blocker. Every P7
artifact claim is proved from actual archives and disposable consumers; publication remains behind
D4/D5/G-RELEASE.

## Почему

A full physical package split would add manifests, versions and release ordering without a current
independent consumer or measured dependency reduction. Documentation alone cannot detect accidental
autoload/exports. Composer development mappings/archive rules and Dart explicit libraries give a
smaller observable boundary now, while preserving the already-real MCP companion split. Exact
manifests plus clean artifact consumers distinguish supported API from implementation without
rewriting P2–P6 semantics or pretending current private packages are public releases.

## Consequences

P7 has four items: shared contract/inventory, PHP boundary, Dart/MCP boundary and isolated artifact
consumer gate. P8 owns adversarial depth, P9 owns CI/matrices/SBOM/provenance and P10 owns final
docs/RC/publication decisions. A future physical split needs new measured evidence and a decision;
it is not an implicit cleanup permitted by D12.
