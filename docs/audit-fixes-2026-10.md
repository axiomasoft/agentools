# SDK and verification corrections, October 2026

The repository remains private. These changes do not authorize publication.

## PostgreSQL event timestamp migration

New installations use `fixtures/hardening/sql/event-store.v1.sql`, which now stores
both `ts TIMESTAMPTZ` for queries and `ts_text TEXT NOT NULL` for the exact timestamp
bytes of the event envelope. Both SDKs hash and hydrate `ts_text`; a different
connection time zone cannot change the event or its hash chain.

Existing installations must stop old writers and explicitly apply
`fixtures/hardening/sql/event-store.timestamp-v2-expand.sql`. Backfill `ts_text`
from trusted original envelopes, verify every existing content hash and journal
chain, then set the column NOT NULL before deploying the new writers. Do not
reconstruct it by formatting `ts`: PostgreSQL has already discarded the original
zone spelling and may have rounded precision. Without trusted original envelopes,
mark affected streams for recovery; do not silently rewrite their hashes or claim
that the original chain has been recovered. The SDK performs no automatic DDL or
unverifiable backfill, and refuses rows without the required exact timestamp.

## API and data behavior

- `ScenarioRunner.run` in PHP and `ScenarioRunner.runWithId` in Dart accept optional `runId`. Persist it before executing work
  that must be retried across process restarts. Reuse it only for the same logical
  run; omit it for a fresh independent invocation. Keys use the shared JCS/SHA-256
  derivation described in specification 06. Compensation retains original context.
- `ActionReducer` and new `JournalAppender` default to revision `2026-09`.
  `ActionJournal` infers its revision from the first event. Explicit `2026-08`
  reducers remain supported; journals mixing revisions are rejected. Invalid
  transitions are checked before EventStore CAS append.
- Snapshots must match the authoritative journal checkpoint, revision, action,
  digest and complete tail; inconsistent snapshots fall back to journal replay.
- RFC 8785 numbers use IEEE-754 binary64 in both SDKs, including native integers.
  Thus JSON `9007199254740993` canonicalizes as `9007199254740992`, matching
  ECMAScript. Values needing exact larger integers must use strings. Audit amounts
  and counters reject values beyond the safe integer range before hashing.
- Audit fields are type-checked before storage/export. Arbitrary nested maps/lists
  are refused; trace context has a closed key set. Calendar-invalid signed token
  timestamps are refused at both issue and parse. Dart constructor guards also
  run with assertions disabled.
- Completed idempotency rows expire before fingerprint comparison; live completed
  and in-progress rows retain key-reuse protection. Expiry does not delete an
  in-progress lease.
- PHP runtime requires `ext-mbstring`. The checked dependency graph resolves for
  PHP 8.3, enforced with Composer's platform setting rather than ignored checks.

## Verification ownership

Operational gate contracts, historical migration inputs and regenerated receipts
live in `verification/`, independent of plan JSON/Markdown representation. See
`verification/provenance.json` for byte-checked extraction sources. Historical
findings remain dated baselines; run owner commands for current evidence.

`ci-bootstrap.sh` installs all PHP/Dart/MCP dependencies for extended and private
RC workloads. `ci-postgres.sh` creates a job-local database. Extended/RC jobs run
live storage schedules. Compatibility jobs resolve, analyze and test complete
package layouts in temporary copies. Mutation tests require a passing baseline
and structured assertion failures in an isolated copy; infrastructure failures
are non-green. Gitleaks scans Git history and current tracked/non-ignored source,
with exact reviewed synthetic-history fingerprints and digest-shaped exceptions.

The agent entrypoint generator owns the prefix of AGENTS.md/CLAUDE.md and preserves
trailing swissknifeman/mAInd managed blocks byte-for-byte.


Dart dependency floors also cover the executable lowest graph: the test toolchain
requires `frontend_server_client >=4.0.0` (AOT snapshot layout), and the core runtime
graph requires `quiver >=3.2.1` (the old BidirectionalIterator API was removed from
Dart). These are explicit constraints, without dependency overrides. Each
compatibility execution now records resolved versions, lock digests and command
results under `verification/artifacts/P9-compatibility-*.json`.

After merging current main, both SDKs also retain `run(..., IdempotencyKey runKey)`
and its established length-prefixed/base64url key derivation. Reuse the same API
and identity type when retrying an existing run; switching derivation schemes
creates different tool keys. Dart uses `runWithId` for arbitrary string identities.
