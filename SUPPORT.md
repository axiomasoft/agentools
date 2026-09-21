# Support policy

Support is defined as a **tuple** (artifact version, spec revision, platform range,
evidence date)—not an open-ended compatibility promise.

## Current distribution

| Dimension | Declared |
|:--|:--|
| Legal | Proprietary (`LICENSE`) |
| PHP package | `agentools/sdk`, PHP `>=8.3` |
| Dart packages | `agentools`, `agentools_mcp_dart`, SDK `^3.11.0`, `publish_to: none` |
| Spec | Candidate `2026-09`; frozen `2026-08` for regression |
| Publication | Private RC only; no Packagist/pub.dev URL |

There is **no** public support SLA. Enterprise response expectations are negotiated
outside this repository.

## What is supported

- Installing private RC subjects documented in [docs/installation.md](docs/installation.md)
- APIs marked `stable` in `api/package-surface-v1.json` with production consumer proof
- PostgreSQL-backed adapters when `ext-pdo_pgsql` / injected SQL executors are configured
- Forward-safe SQL migrations with backup and rehearsal (see [docs/migrations.md](docs/migrations.md))

## What is not supported

- Importing `AgenTools\TestKit\`, `AgenTools\Reference\`, or Dart testkit from production code
- Process-local in-memory stores as production defaults
- Claiming `release-supported` CI lanes without a named green carrier
- Universal down-migration of production databases
- pub.dev or Packagist publication until `[OWNER-GATE:G-RELEASE]`

## Lifecycle

Pre-1.0 (`0.0.1`) candidate surface may change with documented migration notes in
package `UPGRADING.md` files. Breaking stable entries after P7 closure requires
SemVer major planning and changelog entries.
