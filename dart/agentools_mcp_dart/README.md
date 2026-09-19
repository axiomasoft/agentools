# agentools_mcp_dart

Satellite package implementing core [`McpTransport`](../agentools/lib/src/contracts/mcp_transport.dart)
on top of [`mcp_dart`](https://pub.dev/packages/mcp_dart).

## Version risk

Pinned to **stable `mcp_dart` ^2.4.0** (resolved **2.4.2** on 2026-09-19). Trigger **T-1** closed
on branch **(a)**: stable source contains both `McpProtocol.require2026` and `resultType`
enforcement (`lib/src/shared/protocol.dart:790`). The prerelease risk accepted under **D39**
(2026-07-27) is lifted for this pin.

## CI boundary

This package is **outside** the four-layer conformance CI gate: it has no adapter, no vectors, and
is not registered in `conformance/sdks.json` (D86). Run its tests locally:

```bash
dart pub get
dart analyze
dart test
```

## Evidence (F7)

Committed negative-run log for missing `resultType` lives in [`evidence/`](evidence/).
