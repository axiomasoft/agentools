# P7 package gate

**Verdict:** private/local package receipt — **not** a public release claim.

- Schema: `package-gate/v1`
- Source commit: `4b7b40f86657bf5743c6c6c6cc7d1da417dc0ead`
- Deterministic repeat digest match: `True`
- Package surface roster: `d267428e4d9542530b6d0bc083aeaa53472a93cd797342175660773c8272326d`

## PHP archive (`agentools/sdk`)

- Files: 158
- Archive SHA-256: `c720fa78c1773d65583e8b98097ac1213544394d0ee79e5051fdcac9bb0a443e`
- Content digest: `ad02dc456001c3c623a450c5bdf9b311ed6c91c8886299e4b7d0d442502b0fd3`
- Deny violations: 0

## Dart core (`agentools`)

- Publication-preview files: 185
- Content digest: `7b53e6a65f0a23419d3b74df8e26fde3a5ed35e84227b29933b54790f82b41ce`
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

