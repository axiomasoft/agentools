# Release notes (private RC)

This file describes the **private release candidate** assembled in-repo. It is not
a public GitHub Release or registry publication.

## Candidate identity

Subjects are normalized tar+gzip archives with digests recorded in
`plans/2026.09.19-№1-AGENTOOLS-HARDENING/artifacts/P9-private-rc.json`:

| Subject | Package |
|:--|:--|
| `php-sdk` | PHP SDK production archive |
| `dart-core` | Dart `agentools` core |
| `dart-mcp-companion` | `agentools_mcp_dart` (private-paired) |

Install only from these digests or a fresh `tools/release_candidate.py` rebuild
that matches the reproducibility receipt.

## Spec and conformance

- Normative candidate: `spec/revisions/2026-09`
- Cross-language semantic profile and JCS oracle reports under `conformance/reports/`
- Official MCP scenario compatibility: summarized separately; not a certification claim

## Upgrade path

See package-specific `UPGRADING.md` files and [docs/migrations.md](docs/migrations.md).
Forward rollout requires backup; down-migration is not guaranteed.

## Publication

`public_authorized` remains false until owner `[OWNER-GATE:G-RELEASE]`. No tag,
registry upload, or credential use is performed by documentation or acceptance gates.
