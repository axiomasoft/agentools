# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `4b7b40f86657bf5743c6c6c6cc7d1da417dc0ead`
**SOURCE_DATE_EPOCH:** `1791468493`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `7b53e6a65f0a23419d3b74df8e26fde3a5ed35e84227b29933b54790f82b41ce` · archive `e19e99fdcf0a1200364f7772e89d2f079b72003ac5e6e6b8d034faf3df6c5568`
- `dart-mcp-companion`: manifest `ba182c594d99fcf354f4afad18262e899a82ef29e0ddc3fc0983a21e85c2a410` · archive `8a3a66a73dcd23330596a07b5bfef80b30138900a6d60e8732ac8f14e39d5f4d`
- `php-sdk`: manifest `ad02dc456001c3c623a450c5bdf9b311ed6c91c8886299e4b7d0d442502b0fd3` · archive `ed4e0875497b6276c136ec19aba03dc3cf91670205d7092c862fd26adf6d083f`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

