# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `5b03b666f9b346852a2bf05f26835d13f13d92ba`
**SOURCE_DATE_EPOCH:** `1791469469`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `7b53e6a65f0a23419d3b74df8e26fde3a5ed35e84227b29933b54790f82b41ce` · archive `629a9a42eb8ce4a188cdc75d222c47dc0bd15bc93d6418be4744811408cc2cd6`
- `dart-mcp-companion`: manifest `ba182c594d99fcf354f4afad18262e899a82ef29e0ddc3fc0983a21e85c2a410` · archive `9dc7e94820d988cdbdc2e352b32c4c17e5a1007ac8bf1adeded798cd6b1290c5`
- `php-sdk`: manifest `ad02dc456001c3c623a450c5bdf9b311ed6c91c8886299e4b7d0d442502b0fd3` · archive `63ede61c576583dafbf0cf0e5e09cb4799ba097a26cab46ca3a6019de054fa22`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

