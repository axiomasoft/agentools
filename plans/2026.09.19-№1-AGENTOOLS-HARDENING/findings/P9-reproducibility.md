# P9 reproducibility — private RC subjects

**Status:** `green`
**Source commit:** `c540a47737bc8b2c43ad0d971546e7a94c1f599d`
**SOURCE_DATE_EPOCH:** `1789993505`
**Independent builders match:** `True`

## Subject fingerprints (builder A)

- `dart-core`: manifest `932bb4fae147f4d166cc6619162e32c51699b125a4918e896576d597edbd25de` · archive `f60e9f1c5f98fc8fad314ab2a2ff924841b813a85670a71e3577bc641a2674d4`
- `dart-mcp-companion`: manifest `66b40ec64d8053eb005c5f84b007d72a4f35177c94b73e2246a42ccb572c026b` · archive `35015fa9c67d3b5aacc8049b4fd885f67938763a9fd06d2032e98710b9a146f6`
- `php-sdk`: manifest `6957c83d0d69b175dc6dc2b5b8a30a8a2ec8d794c99cdf3cb3e84df3685ef92a` · archive `5cd7ef896988acc2033eb5403f6d0273ae90ebc64ebe0564d7dacb76af37ef96`

## Notes

- Archive format: repository-owned normalized GNU tar + deterministic gzip (mtime=0 header).
- P7 package-gate inventories are the content oracle; Composer/Dart native archives are not byte producers.
- MCP companion remains `private-paired` / `public-release-blocked` (path dependency).

