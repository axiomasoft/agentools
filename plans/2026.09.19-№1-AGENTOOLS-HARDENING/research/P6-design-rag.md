# P6 design RAG — JCS and official MCP conformance

**Verified:** 2026-09-19  
**Boundary:** External sources constrain JCS bytes and the official MCP suite's runnable interface.
Agentools revision naming, applicability classifications, error spellings, parity fields and phase
ownership are repository decisions (`RAG:—`).

The configured Perplexity MCP server is enabled locally, but its tool was not exported to this
Codex turn. No Perplexity result is claimed. Broad web search and direct CLI/registry probes were
used; every load-bearing external claim below is tied to a primary source.

| Evidence | Verified claim | P6 consequence |
|:--|:--|:--|
| RAG:✅ [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785.html), accessed 2026-09-19 | JCS is deterministic JSON under I-JSON constraints. Object names are duplicate-free; strings preserve Unicode without normalization; object names sort recursively by raw UTF-16 code units; number serialization follows ECMAScript/IEEE-754; NaN and infinities are not JSON. | P6.3 tests raw duplicate detection, no normalization, UTF-16 ordering, ECMAScript number boundaries and non-finite rejection. |
| RAG:✅ [RFC 8785 status/errata entry](https://www.rfc-editor.org/info/rfc8785), accessed 2026-09-19 | RFC 8785 is Informational and its current status/errata are discoverable from the RFC Editor. | The contract says “RFC 8785-compatible” and pins evidence; it does not mislabel JCS as an Internet Standards Track certification. |
| RAG:✅ [`cyberphone/json-canonicalization`](https://github.com/cyberphone/json-canonicalization), commit `19d51d7fe467d4706a3ff08adf8a748f29fc21e0`, accessed 2026-09-19 | The upstream Apache-2.0 repository carries six canonical input/output files and a deterministic ES6-number generator. Its testdata records checksum `b9f7a8e75ef22a835685a52ccba7f7d6bdc99e34b010992cbc5864cd12be6892` for the first 10,000 rows. | Vendor only bounded oracle data/helper plus provenance/license; 10,000 rows are mandatory, 100 million belongs to optional P8 stress. |
| RAG:✅ [official MCP conformance repository](https://github.com/modelcontextprotocol/conformance), accessed 2026-09-19 | The suite separately tests client and server implementations, writes check receipts, validates wire schemas, supports expected-failure baselines, and distinguishes frozen revision requirements from a moving suite. Non-scored extension/added-after-release/pending scenarios remain visible. | P6 keeps role/scenario applicability explicit and stores raw receipts. A non-applicable role is not a pass; an expected failure remains a failure against the requirement set. |
| RAG:✅ [official conformance requirements documentation](https://github.com/modelcontextprotocol/conformance#conformance-requirements), accessed 2026-09-19 | `--requirements <revision>` selects the frozen set for that wire revision. Passing one wire says nothing about another, and only required scenarios affect the scored pass rate. | P6 uses `2026-07-28` explicitly and never substitutes default/current-suite behavior for a revision claim. |
| RAG:✅ [npm package](https://www.npmjs.com/package/@modelcontextprotocol/conformance), registry probed 2026-09-19 | Stable `0.1.16` does not accept `list --requirements 2026-07-28`. Prerelease `0.2.0-alpha.11` does; it reports 69 scored scenarios plus 16 extension, 1 added-after-release and 3 pending rows. Integrity is `sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA==`. | Pin the prerelease exactly in a dev-only lock. `latest`, the stable package, and repository `main` are forbidden substitutions for the P6 receipt. |

## Facts intentionally not promoted

- The official suite is evidence for MCP wire behavior only. It does not validate Agentools
  confirmation, replay, fencing, event-store or JCS semantics.
- `agentools_mcp_dart` wraps one tools/MRTR/task client boundary; it is not automatically a complete
  MCP client SDK because its dependency may implement more features internally.
- The 69-scenario requirement set is an inventory to classify, not a demand to fake absent server,
  auth, prompt or resource products in P6.
- Upstream oracle code/data is not a runtime dependency of PHP or Dart packages.
