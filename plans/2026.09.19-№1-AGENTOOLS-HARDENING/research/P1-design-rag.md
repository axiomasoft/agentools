# P1 design RAG — external foundations

**Verified:** 2026-09-19  
**Boundary:** P1 uses external evidence only for changing compatibility/version premises and
guarantee wording. Current Agentools behavior/status is repository evidence (`RAG:—`).

Perplexity was rechecked through the configured local `perplexity-web-mcp` server after its tools
were found not to be surfaced in the active client session. One broad query and two narrow repair
queries returned the primary URLs and the omitted PHP/OWASP details. Claims below were retained
only where the cited official page was also reachable directly in this or the preceding bounded
retrieval pass; a Perplexity summary alone is not treated as proof. The native web connector
remained transiently unavailable.

| Evidence | Verified claim | P1 consequence |
|:--|:--|:--|
| RAG:✅ [PHP supported versions](https://www.php.net/supported-versions.php), accessed 2026-09-19 | The official table lists PHP 8.2–8.5. Active/security end dates are: 8.2 `2024-12-31`/`2026-12-31`; 8.3 `2025-12-31`/`2027-12-31`; 8.4 `2026-12-31`/`2028-12-31`; 8.5 `2027-12-31`/`2029-12-31`. | P1.3 records an evidence date and separates manifest floor `>=8.3` from actually tested/release-supported runtimes. |
| RAG:✅ [Dart `pub downgrade`](https://dart.dev/tools/pub/cmd/pub-downgrade), accessed 2026-09-19 | The command resolves the lowest versions permitted by the declared constraints **as one compatible direct/transitive graph** and rebuilds the lockfile; it does not promise every dependency's independent raw lower bound in an unsatisfiable combination. | P1.3 names this as the future compatible-lowest-dependency proof lane; it does not claim that lane is currently green. |
| RAG:✅ [MCP specification `2026-07-28`](https://modelcontextprotocol.io/specification/2026-07-28), [official release](https://github.com/modelcontextprotocol/modelcontextprotocol/releases/tag/2026-07-28), accessed 2026-09-19 | The official specification UI marks `2026-07-28` as latest and the upstream repository publishes the matching release tag. | P1.3 keeps the MCP revision pin distinct from Agentools spec revision `2026-08` and from official conformance scope. |
| RAG:✅ [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html), accessed 2026-09-19 | SemVer requires a declared public API; incompatible, backward-compatible feature and compatible-fix changes map to major, minor and patch versions after that declaration. | P1.3 does not label accidental PHP/Dart reachability stable; P7 must declare the 1.0 public surface and migration rules. |
| RAG:✅ OWASP Authorization Cheat Sheet: [Deny by Default](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html#deny-by-default), [Validate the Permissions on Every Request](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html#validate-the-permissions-on-every-request), rechecked 2026-09-19 | Deny-by-default and validation on every request constrain authorization boundaries. | P1.2 models caller/application adapters as partially trusted; P2 must obtain actor/resource/action/environment context from trusted seams. |

## Facts intentionally not promoted

- PHP support windows do not prove Agentools works on every officially supported PHP version.
- Dart `pub downgrade` does not prove the current package's compatible-lowest-version test passes,
  nor that every independently lowest declared version forms a satisfiable graph.
- An MCP release tag does not make Agentools officially MCP-conformant.
- SemVer does not decide which existing symbols are public; that is P7's package contract.
- No external benchmark supplies a portable absolute timing threshold for P1. P8 must calibrate
  regression budgets on controlled runners before making performance a gate.
