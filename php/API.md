# PHP SDK API

Production package `agentools/sdk` exposes `AgenTools\\` from `src/` only.

## Supported entry

Composer production autoload maps `AgenTools\\` → `src/`. Consumers should depend on:

- ports under `AgenTools\\Contracts\\`
- `ConfirmationService`, `IdempotencyCoordinator`, `EventStore` and the value objects those facades require
- optional PostgreSQL adapters (`PdoPostgres*`) when `ext-pdo_pgsql` is available

Exact identities and tiers live in `api/package-surface-v1.json`. Reachability is not a stability promise; `stable` requires a production consumer and a behavioural test.

## Development contours (root-only)

These namespaces are `autoload-dev` and are excluded from the production archive:

| Namespace | Role |
|:--|:--|
| `AgenTools\\TestKit\\` | In-memory/fixed doubles (`FixedClock`, `InMemory*`, `StaticTokenKeyProvider`) |
| `AgenTools\\Reference\\` | Product-slice / example contour |
| `AgenTools\\Tests\\` | Package tests |

A production `composer install --no-dev --classmap-authoritative` consumer cannot autoload testkit or reference FQNs.

## Not supported

- Importing testkit/reference types from production code
- Treating every PSR-4-reachable `src` class as SemVer-stable
- Packagist publication (private/proprietary until G-RELEASE)
