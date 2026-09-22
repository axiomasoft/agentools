# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `29482e1c6b2bcd19d63d5f3e2ead0e6ffa07665a`
**SOURCE_DATE_EPOCH:** `1790011340`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `9dcee5babb73086f0c0830c4628b7497ee8c5ef2babb8edf5cd630bb8653d2ae` · archive `b4af3bd312c8ebc380a33a8f9b0b5f936255627c3dc694be9b6577a5da28c9da`
- `dart-mcp-companion`: manifest `be070361aa2e6bf26d4acc94689ddc6098be1a1be286d3b58ab1047a21c59680` · archive `2e75c28bb91f9b66591b97ddb2a4b8bc6cf6870001f5bbed9f58ed4dff7358ec`
- `php-sdk`: manifest `a679ff0f53cc3516b1563e8b737759e607093a32ef5015cafdf367dd269abba8` · archive `e43bb369802948f398c9034600018df73813b1fbfb71f8e7806d28a13a5cc787`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

