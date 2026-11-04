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

## Private platform packages

The platform is distributed from the same private checkout, not a public registry.
A consuming project's root Composer manifest must declare path or private repository
entries for `agentools/platform-core`, `agentools/platform-postgres`,
`agentools/platform-model-adapter`, `agentools/platform-laravel`, and `agentools/sdk`;
repositories in dependencies are not inherited. The current source packages use
`dev-main`. Declare the private packages required by the closure explicitly at
the consumer root with `dev-main`, including transitive private dependencies, so
Composer permits their development stability. Pin reviewed private revisions and
commit the consumer's lock file.
Laravel composition requires PHP >=8.3, Laravel 12, PDO PostgreSQL and curl; the
core package itself requires only PHP. Install with `composer install --no-dev`
from the consumer root. Filament, Chatom, Loqum, scheduler and testing packages
are optional and must be explicitly installed when used. A successful installation
does not configure host authority, persistence, credentials, or routes.

See [platform quickstart](platform/quickstart.md) for entrypoints and a runnable
isolated example. The `package-consumer` gate creates a fresh Composer root and
checks the production closure; it is not a release or live integration proof.

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
