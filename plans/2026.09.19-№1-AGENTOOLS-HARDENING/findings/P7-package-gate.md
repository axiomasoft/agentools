# P7 package gate

**Verdict:** private/local package receipt — **not** a public release claim.

- Schema: `package-gate/v1`
- Source commit: `c540a47737bc8b2c43ad0d971546e7a94c1f599d`
- Deterministic repeat digest match: `True`
- Package surface roster: `d267428e4d9542530b6d0bc083aeaa53472a93cd797342175660773c8272326d`

## PHP archive (`agentools/sdk`)

- Files: 158
- Archive SHA-256: `528d2091b9d3a182c164ae89149bd7c7b12c701b3d2a60e982033fd0dc80e285`
- Content digest: `6957c83d0d69b175dc6dc2b5b8a30a8a2ec8d794c99cdf3cb3e84df3685ef92a`
- Deny violations: 0

## Dart core (`agentools`)

- Publication-preview files: 185
- Content digest: `932bb4fae147f4d166cc6619162e32c51699b125a4918e896576d597edbd25de`
- Deny violations: 0

## Dart MCP companion (`agentools_mcp_dart`)

- Publication-preview files: 8
- Path dependency on core: `public-release-blocked`
- Deny violations: 0

## Clean consumers

- PHP production (`--no-dev`): exit 0
- PHP dev (TestKit contour): exit 0
- Dart core / dev / MCP groups: see artifact JSON
- Workspace leak scan: 0 hit(s)

## P8–P10 residuals

- P9 owns deterministic normalized bytes, SBOM and provenance.
- P10 owns RC consumer acceptance against P9 subjects.
- MCP `path:` dependency remains `public-release-blocked` until P9/P10.

