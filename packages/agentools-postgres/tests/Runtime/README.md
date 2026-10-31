`python3 tests/Runtime/run.py --filter testName` runs a focused intake method on
the same proven disposable PostgreSQL lifecycle as pg-schema. The full pg-intake
partition owns six named contract cases and five repository boundary cases.
Run `composer install` first; core is a production dependency, while the dated
SDK supplies its existing JCS implementation only in the test composition.

Production composition supplies `Connection(PDO, applicationSchema)`, the core
`CanonicalHasher`, `HostAuthorization` and `ContextRevision` in a
`RepositoryContext`. `ContextRevision` is a host-owned authorized lookup of the
current snapshot revision. All host calls happen before a repository transaction;
caller transactions are refused. Repositories qualify every table by schema and
every row by tenant/session. Database keys do not grant read access.

`SessionRepository.bind(context, binding)` atomically binds immutable instance
code refs and session bundle/history pins. `IntakeRepository.accept(trustedInput,
snapshot)` computes the existing core source/payload/Turn/Run identities and
atomically inserts Turn, initial Run, completed inbox identity and pending wake
outbox. Input/context/snapshot checkpoints contain typed refs and hashes, not raw
transcript bytes. Instance scope is encoded in `source_event_key` with a length
prefix; `source` remains the original source kind. This internal SQL identity
encoding is independent of JCS, so changed hash derivation cannot silently create
new work on replay. It does not change any dated SDK identity scheme.

An identical retry returns the original acceptance receipt even after preparation
has advanced; current state comes from `RunRepository.get`. Different payload,
session binding or identity derivation raises the existing typed IntegrityConflict
without rewriting the winner. SQL constraint/concurrency errors are translated;
infrastructure failures do not expose raw SQL through exception chains. Retry an
ambiguous intake outcome using the same source identity.

Initial Run version/epoch are 0/0 and unclaimed. `beginPreparation` checks current
host policy and snapshot revision, then uses tenant/session-bound version CAS for
the accepted-to-preparing advance. It does not claim a worker, execute a provider
or complete an Action. Claim/fencing recovery and outbox scanner belong to P3.3.
Wake transport is deliberately outside this item: the committed outbox row is the
durable wake intent, and the crash test asserts its survival and stable replay.

The concurrent case observes both real PHP processes waiting at a PostgreSQL
advisory barrier before releasing them. Fault tests fail each transaction boundary
and terminate a process after commit before any client receipt/queue signal.
The grouped identity-positive case proves intake persistence and preserves existing
proposal/effect/delivery/usage/occurrence value forms through PostgreSQL JSON;
it does not qualify repositories or runtime owners for those later features.
ManualClock's unresolved fractional truncation finding remains outside this slice.
