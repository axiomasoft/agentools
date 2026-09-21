# Release candidate subject contract v1 — AgenTools hardening (PLAN1)

**Version:** v1  
**Status:** draft contract (P9.3)  
**Decision anchor:** D14 · **Depends on:** P7.4 package-gate inventories

## 1. Scope

This contract defines the repository-owned **exact private release subject** format and the
**reproducibility receipt** that binds three subjects:

| Subject ID | Package | Paired status |
|:--|:--|:--|
| `php-sdk` | PHP SDK (`php/`) | `standalone` |
| `dart-core` | Dart core (`dart/agentools/`) | `standalone` |
| `dart-mcp-companion` | Dart MCP (`dart/agentools_mcp_dart/`) | `private-paired` |

No public semver, registry upload, or tag is implied. The MCP companion remains
`public_release_blocked` while it carries a `path:` dependency on core.

## 2. Canonical archive format

Normative transport: **POSIX ustar/GNU tar** serialized with fixed metadata, wrapped in **gzip** with
`mtime=0` and no `FNAME`/`FCOMMENT` fields.

| Field | Normative value |
|:--|:--|
| Path order | Bytewise ascending relative path |
| Path rules | No `..`, absolute paths, symlinks, or special files |
| Owner / group | `0` / `root` |
| File mode | `0644` default; `0755` for `bin/**` and `bin/reference-slice-demo` |
| mtime | `SOURCE_DATE_EPOCH` from source commit |
| Build roots | Never embedded in archive paths |

Content selection MUST match the executed P7 package-gate allowlists exactly (no missing/extra files).

## 3. Receipt schema

Machine schema: `release-candidate-v1.schema.json` (`release-candidate-receipt/v1`).

Each builder block records:

- `source_commit`, `source_date_epoch`
- digests of this contract, JSON schema, and `.ci/supply-chain-lock.json`
- per-subject `content_manifest_digest` and `archive_digest` (`algorithm` = `sha256` only)

Two independent clean builders MUST produce identical fingerprints before P9.4 binds metadata.

## 4. Non-goals

- Composer `archive` / `dart pub publish --dry-run` byte identity
- SBOM, SLSA provenance, or GitHub attestation (P9.4)
- Workflow transport or aggregate RC receipt (P9.5)
