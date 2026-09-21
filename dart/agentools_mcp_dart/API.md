# Dart MCP companion API

Package `agentools_mcp_dart` is an optional transport adapter. It is not part
of the Dart core package.

## Supported entry

`package:agentools_mcp_dart/agentools_mcp_dart.dart` exports `McpDartTransport`.

The companion imports only supported `package:agentools/agentools.dart`
libraries. It must not import `package:agentools/src/...`.

Core `agentools` has no `mcp_dart` or Flutter dependency. The companion path
dependency is a **private-paired** distribution constraint until owner publication
approval.

## Not supported

- Importing core `lib/src`
- Treating this package as required for PHP or pure-Dart core consumers
- pub.dev publication (private/proprietary until G-RELEASE)
