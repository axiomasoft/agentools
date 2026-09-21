# Dart packages

| Package | Path | Role |
|:--|:--|:--|
| `agentools` | `agentools/` | Core SDK (`agentools.dart`, optional testkit library) |
| `agentools_mcp_dart` | `agentools_mcp_dart/` | MCP transport companion (private-paired) |

Both packages declare SDK `^3.11.0` and `publish_to: none`. Spec candidate revision
**2026-09** applies to semantics exercised by conformance.

## Quick start

```bash
cd agentools && dart pub get && dart test
cd ../agentools_mcp_dart && dart pub get && dart test
```

See [agentools/README.md](agentools/README.md) and
[agentools_mcp_dart/README.md](agentools_mcp_dart/README.md) for API boundaries.
