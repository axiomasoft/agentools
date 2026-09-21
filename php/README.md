# AgenTools PHP SDK

`agentools/sdk` implements the AgenTools protocol for PHP 8.3+. The candidate spec
revision is **2026-09** (frozen **2026-08** remains for regression vectors only).

Thin contracts library: not a Laravel integration or second agent runtime.

## Install and test

```bash
composer install --working-dir=php
composer test --working-dir=php
composer analyse --working-dir=php
```

Production archives exclude `AgenTools\TestKit\` and `AgenTools\Reference\` (dev autoload only).

## API

See [API.md](API.md) and [UPGRADING.md](UPGRADING.md). Stable surface is defined in
`api/package-surface-v1.json`.

## Durable adapters

Use `PdoPostgres*` stores in production when guarantees require cross-process replay
protection, lease fencing, or event collision handling. In-memory implementations are
testkit-only.
