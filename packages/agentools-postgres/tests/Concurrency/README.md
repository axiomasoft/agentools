# Claims and wake transport

`python3 tests/Concurrency/run.py --filter testTwoWorkerStaleFence` runs one
focused case. The owning gate suite is `pg-claims-outbox`; it requires isolated
PostgreSQL and the exact registered case set. The shared lifecycle verifies the
database name, restricted owner and isolation comment before creating a schema.
There are no SQLite substitutes or required skips.

`ClaimRepository::claim` commits a short `FOR UPDATE SKIP LOCKED` transaction
before returning a random owner token, incremented epoch and version. Pass the
returned `RunClaim` to heartbeat/checkpoint and retain the new returned token
state, since each write advances version. Every write reauthorizes the host and
snapshot before SQL, then compares tenant, Run, session, version, epoch, token,
DB lease and recovery deadline. Terminal checkpoints release the lease. Accepted
identities come from the locked durable row, never a caller-supplied checkpoint.
Progress contains bounded technical refs/state; callers must keep payload bytes
and secrets in their owning encrypted storage.

`Scanner::scan` returns existing unstarted/expired Runs with version, age and
`recovery_required`. ClaimPolicy defaults are a 30-second lease, eight claims and
a one-day recovery deadline, calculated by PostgreSQL. Exhausted work remains
durable for manual reconciliation; it is not relabelled as a failed effect.
Scanning/claiming a waiting Run only grants coordination: its saved state must
be classified before advancing. This package supplies no provider/effect loop.

`Relay::once` publishes only committed `run.accepted` wake signals outside SQL.
The receiver loads and claims the referenced Run. The event key and payload hash
stay unchanged after receipt loss. The outbox persists unknown publish outcomes,
uses exponential backoff capped at one hour, and respects durable max attempts
and deadline. Its bounded deadletter scan waits for active leases and retains
unresolved rows, including crashes on the last attempt. It never relays effect
or provider intents. Receiver deduplication and owner operation idempotency are
separate requirements; claim expiry grants no permission to replay an effect.

The tests cover the eight named P3.3 cases plus bounded recovery, retry/current
permissions, and skip-locked/authoritative evidence. Two real PHP processes pause
at a committed-claim barrier, use actual PostgreSQL fractional leases, then prove
the former epoch cannot write. A separate connection acquires the Run/outbox row
at the external work boundary. Trigger faults prove claim/checkpoint/relay
rollback; process exits cover intake commit and publish-before-receipt loss.
ManualClock's deferred fractional truncation finding is not fixed or used here.

P3.4 must compose journal/projection/effect intent writes on a common transaction
boundary. These repositories own their transactions and reject caller nesting;
they must not be nested inside that future boundary. P3.5 must retain unresolved
transport evidence and tenant/session scoping. Neither live provider behavior,
owner exactly-once effects nor production readiness is established by this suite.
