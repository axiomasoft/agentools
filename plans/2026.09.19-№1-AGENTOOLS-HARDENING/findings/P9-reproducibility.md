# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `1581ab407ef461bb6135fe841d3897ced5a5906d`
**SOURCE_DATE_EPOCH:** `1791289966`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `b7e2a60f7a438e5c663fd406c591a1a44faf261f2e8c828d32182ff3e4dd49c3` · archive `67ffde98164b5f34513245b6de22461a4a58c73b189d208f7a085b36477ace27`
- `dart-mcp-companion`: manifest `be070361aa2e6bf26d4acc94689ddc6098be1a1be286d3b58ab1047a21c59680` · archive `6b2e747a9411cd9821340086b345badb6b39cf505841cc350f0263ff5f881d93`
- `php-sdk`: manifest `9a015af4b52883b939919a60007743b3fed42ffefbeb08a812d5b3fae5b9bcff` · archive `ead9210232e16338fc17e6d754d7850673f6f8d6742de8c35f157ae0fbcef33e`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

