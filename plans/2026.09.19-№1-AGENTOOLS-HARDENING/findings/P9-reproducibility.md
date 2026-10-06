# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `8100ad3c51008551fa1da668190fdd309d34c52e`
**SOURCE_DATE_EPOCH:** `1791294244`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `b7e2a60f7a438e5c663fd406c591a1a44faf261f2e8c828d32182ff3e4dd49c3` · archive `44c781033fe05651b1a95902bca9c8656467d8634ca929de7c9c0591e4939446`
- `dart-mcp-companion`: manifest `be070361aa2e6bf26d4acc94689ddc6098be1a1be286d3b58ab1047a21c59680` · archive `f7b500e7d88eb82cd67fab1d5d0079d8b0fde4be801849a7ce37bda80e5e85f2`
- `php-sdk`: manifest `9f1eb525480b30d3d492978fbca7b646962c811060a1e54fdf0a5cf45399eb05` · archive `d33616bb9eb7c8cc27ca6235b1c1c76e1c911cba44e10288a00ef3e3f77b1deb`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

