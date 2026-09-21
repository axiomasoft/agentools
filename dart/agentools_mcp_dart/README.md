# agentools_mcp_dart

MCP transport companion for the Dart core SDK. Depends on `agentools` via
`path: ../agentools` and is distributed only as a **private-paired** RC subject.

## Install and test

```bash
dart pub get
dart analyze --fatal-infos
dart test
```

## Boundary

- Implements the core `McpTransport` port; does not replace core confirmation,
  idempotency, or event-store semantics.
- Official MCP **scenario compatibility** evidence is recorded separately in
  conformance reports; this package does not claim upstream MCP certification.
- Not registered in the main four-layer SDK conformance registry; validate via
  this package's tests and the MCP applicability summary.

## Publication

`publish_to: none` until owner `[OWNER-GATE:G-RELEASE]`. Requires matching
`dart-core` subject digest from the private RC aggregate.

See [API.md](API.md).
