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


### October 2026 corrections

`ScenarioRunner.run` accepts an optional persisted run identity (`runId`; named in
Dart, fourth argument in PHP). Reusing it preserves step/compensation keys; omitting
it creates a fresh run. `ActionReducer` and `JournalAppender` default to `2026-09`;
use an explicit `2026-08` reducer for predecessor records. `ActionJournal` infers
revision from its first event. Audit persistence rejects nested arbitrary data and
unsafe numeric amounts; Dart value-object validation also runs without assertions.
See [migration and verification notes](../docs/audit-fixes-2026-10.md).

`ScenarioRunner.run(..., runKey: $key)` also accepts the existing `IdempotencyKey`
and preserves its length-prefixed key derivation from main. A string fourth
argument or named `runId` selects the shared JCS identity scheme; do not supply both.
