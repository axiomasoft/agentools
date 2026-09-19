---
id: D11
date: 2026-09-19
status: accepted
item: P6
items: [P6, P6.1, P6.2, P6.3, P6.4, P6.5]
supersedes: []
superseded_by: null
---
# D11 — Successor semantic projection, independent JCS oracle and bounded MCP applicability

**Actor:** plan-designer/gpt-5.6-sol/high
**Evidence:** RAG:— repository recon in `research/P6-design.md`; RAG:✅ primary-source and live pin checks in `research/P6-design-rag.md`

## Решение

P6 creates self-contained candidate revision `2026-09` and its corpus without changing frozen
`2026-08`. Normative Markdown, schemas and case manifests remain owners; one deterministic
`semantic-manifest.json` is generated from them with source digests. PHP and Dart keep handwritten
DTOs/constants under DR-02 and expose actual semantic profiles for exact-set comparison. P6 does
not generate SDK source.

JCS is proven against RFC 8785 and the Apache-2.0 upstream oracle corpus pinned at commit
`19d51d7fe467d4706a3ff08adf8a748f29fc21e0`, including the first 10,000 deterministic ES6-number
rows. One SDK never generates the other's expected bytes.

Official MCP evidence uses exact dev-only pin `@modelcontextprotocol/conformance@0.2.0-alpha.11`
and its npm integrity. Every `2026-07-28` requirement row is classified, but only scenarios
reachable through the public `agentools_mcp_dart` client boundary execute as applicable. This
repository ships no MCP server and no PHP MCP transport. The result is named “official MCP scenario
compatibility”, never certification, Tier 1 or full client/server conformance. Agentools conformance
and official MCP reports stay separate.

P6 commits a candidate revision lock, not a public git tag. Publication/tagging remains behind
G-RELEASE in P9/P10.

## Почему

Patching `2026-08` would rewrite historical evidence. A handwritten parity manifest would create a
second owner, while PHP↔Dart comparison alone can preserve the same defect. Stable official MCP
conformance `0.1.16` cannot select the target wire requirements; the pinned prerelease can, but the
package boundary is narrower than a complete MCP SDK. Full-suite baselining would turn absent roles
into misleading green output. A candidate digest is reproducible without crossing D4's release/tag
authority boundary.

## Consequences

P6 has five items: successor contract, semantic drift gate, JCS oracle, official MCP applicability,
and integrated receipt. P7 consumes the semantic manifest but still owns exports/package layout. P8
owns deeper fuzz/mutation and optional 100-million-number stress. P9 owns dependency pin policy and
supply-chain automation. P10 accepts the candidate and may request G-RELEASE for public tags/uploads.
