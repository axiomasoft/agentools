# AgenTools

Protocol-first agent + tools + scenarios for business applications: a normative
specification aligned with Model Context Protocol (MCP) ideas, plus native PHP and
Dart SDKs verified by shared conformance fixtures.

The repository is **private and proprietary** (`LICENSE`). Packages use Composer
`proprietary` and Dart `publish_to: none` until an owner `[OWNER-GATE:G-RELEASE]`
decision. There is no public registry installation URL in this distribution.

## Supported revision

| Artifact | Revision / pin |
|:--|:--|
| Agentools spec (candidate) | `2026-09` under `spec/revisions/2026-09/` |
| Frozen predecessor | `2026-08` (regression only; not the current development target) |
| MCP vendor pin | `2026-07-28` (distinct from Agentools spec revision) |

Cross-language semantics, JCS oracle results, and **official MCP scenario
compatibility** are separate evidence classes. Documentation describes
applicability; it does not claim upstream MCP certification.

## Repository layout

| Path | Role |
|:--|:--|
| `spec/` | Dated revisions, JSON Schema, glossary |
| `fixtures/` | Language-neutral vectors and golden files |
| `conformance/` | Runner and adapters exercising fixtures |
| `php/` | PHP SDK (`agentools/sdk`, `AgenTools\`) |
| `dart/agentools/` | Dart core SDK |
| `dart/agentools_mcp_dart/` | MCP companion (private-paired; path dependency on core) |
| `tools/` | Spec linters, CI gates, release acceptance helpers |

## Installation (private)

See [docs/installation.md](docs/installation.md) for Composer path/repo install and
Dart path dependencies. Production deployments require durable PostgreSQL adapters
for token replay, idempotency fencing, and event storage where those guarantees apply;
in-memory stores are testkit-only.

## Operations and migrations

- [docs/migrations.md](docs/migrations.md) — SQL artifacts, forward-safe rollout, recovery boundaries
- [docs/operations.md](docs/operations.md) — backup, token/key rotation, revocation runbooks

## Security and support

- [SECURITY.md](SECURITY.md) — vulnerability reporting (no public SLA)
- [SUPPORT.md](SUPPORT.md) — supported tuples and lifecycle limits
- [CHANGELOG.md](CHANGELOG.md) · [RELEASE_NOTES.md](RELEASE_NOTES.md)

Package API surfaces: [php/API.md](php/API.md), [dart/agentools/API.md](dart/agentools/API.md),
[dart/agentools_mcp_dart/API.md](dart/agentools_mcp_dart/API.md).

## Guarantees (summary)

Observable guarantees and explicit non-promises are catalogued in the hardening
contracts (for example confirmation gates, durable token consume, lease fencing,
event-store collision rules). **Exactly-once external side effects are not
promised**; integrators must cooperate for at-most-once semantics.

Release acceptance uses digest-qualified evidence (`tools/release_acceptance.py`);
private readiness does not authorize public publication.
