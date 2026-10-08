# P10 G-RELEASE decision packet (request only)

**Packet digest:** `78de29b06b276852…`
**Expires:** `2027-01-06T14:21:09Z`
**Authorized:** `False`
**Public eligible:** `red`
**Public blockers:** 18

## Proposed actions (all blocked until owner scope)

- `packagist.publish.php-sdk` → `packagist.org` (preconditions_met=False)
- `pub.dev.publish.dart-core` → `pub.dev` (preconditions_met=False)
- `pub.dev.publish.dart-mcp-companion` → `pub.dev` (preconditions_met=False)
- `github.release.spec-tag` → `github.com/axiomasoft/agentools` (preconditions_met=False)

## Rollback limits

- Published registry versions and git tags create durable consumer state.
- Deletion or retraction is channel-specific and never guaranteed.
- This packet does not promise rollback of any future public artifact.
