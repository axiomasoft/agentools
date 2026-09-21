# Official MCP conformance (dev-only)

Pinned prerelease `@modelcontextprotocol/conformance@0.2.0-alpha.11` with npm integrity
`sha512-imPK9tx5gQsL6ZKQq4MrsyDYfSaIwpRmX6+ogjbeAXs9LGvxkBxWcY7KcS7TvwaBk/ZiVWl6b/naF4q83UwDRA==`.

This contour is separate from Agentools harness conformance. The honest summary phrase is
**official MCP scenario compatibility** — not a certification claim and not full MCP SDK conformance.

```bash
npm ci
python3 ../../tools/mcp_applicability.py --check
./bin/run
```

Applicable scenarios execute through `dart/agentools_mcp_dart/tool/conformance_client.dart`
and the public `McpDartTransport` boundary. Raw receipts live under
`conformance/reports/mcp-official/`.
