# Independent JCS oracle (pinned upstream)

| Field | Value |
|:--|:--|
| Upstream | [cyberphone/json-canonicalization](https://github.com/cyberphone/json-canonicalization) |
| Commit | `19d51d7fe467d4706a3ff08adf8a748f29fc21e0` |
| License | Apache-2.0 (`LICENSE`) |
| Vendored paths | `input/*.json`, `output/*.json` |

Expected bytes in `output/` are authoritative for P6.3 differential gates; SDK output MUST NOT
be used to regenerate them.
