# Installation (private distribution)

AgenTools is **not** published to Packagist or pub.dev. Install from a private
path, VCS URL, or staged RC archive whose digest matches `P9-private-rc.json`.

## PHP SDK

Production install (no testkit/reference autoload):

```bash
composer install --working-dir=php --no-dev --classmap-authoritative
```

For local development with tests:

```bash
composer install --working-dir=php
composer test --working-dir=php
composer analyse --working-dir=php
```

Require durable PostgreSQL adapters in production when replay, fencing, or event
durability guarantees apply (`PdoPostgresConfirmationTokenStore`,
`PdoPostgresIdempotencyStore`, `PdoPostgresEventStore`).

## Dart core

```bash
cd dart/agentools
dart pub get
dart analyze --fatal-infos
dart test
```

Consumers depend on `package:agentools/agentools.dart` only. Do not import
`lib/src` paths.

## Dart MCP companion

The companion depends on core via `path: ../agentools`. It is a **private-paired**
subject: install core and companion together from the same RC build.

```bash
cd dart/agentools_mcp_dart
dart pub get
dart analyze --fatal-infos
dart test
```

Official MCP scenario compatibility is evaluated through the conformance pin
documented in `conformance/mcp/`; this is not an upstream MCP certification claim.

## Spec and fixtures

Conformance uses revision `2026-09` fixtures (`fixtures/2026-09`). Frozen `2026-08`
vectors remain for regression. Run layers via `python3 tools/ci_gate.py --layer N`.
