# Official MCP conformance (dev-only)

Pinned prerelease `@modelcontextprotocol/conformance@0.2.0-alpha.11` with npm integrity
`sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA==`.

This contour is separate from Agentools harness conformance. The honest summary phrase is
**official MCP scenario compatibility** — not a certification claim and not full MCP SDK conformance.

```bash
npm ci --ignore-scripts
python3 ../../tools/mcp_applicability.py --check
./bin/run
```

The runner executes only the pinned local
`conformance/mcp/node_modules/.bin/conformance` binary. If it is absent, install the
lockfile with the command above; the runner never falls back to a remote npm package.

Seven frozen required client scenarios execute through
`dart/agentools_mcp_dart/tool/conformance_client.dart` and the public
`connect`, `discoverTools`, `invokeTool`, and clarification-resume boundaries.
OAuth remains non-applicable because the wrapper exposes no auth configuration;
server scenarios remain non-applicable because this repository ships no MCP server.
Unscored and added-after-release rows are excluded from the frozen denominator.

The committed summary keeps applicability and execution status as independent axes
and reports `7/7 applicable; 7/32 frozen required client scenarios applicable`.
This is a bounded compatibility result, not a full certification. Raw receipts live
under `conformance/reports/mcp-official/`.
