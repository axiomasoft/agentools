# Atomic Action storage

Run a focused case with `python3 tests/Actions/run.py --filter testDurableIntentBeforeEffect`.
The owning `pg-action-journal` gate requires the exact registered case set on
verified isolated PostgreSQL. The shared lifecycle checks database name, restricted
owner and allow marker before creating/cleaning any schema. No SQLite substitute
or required skip establishes this tier.

`ActionRepository` implements `TransactionBoundary`. It authorizes the current
host/snapshot outside SQL, fences the Run claim and session binding, then invokes
the dated `PdoPostgresEventStore.appendInTransaction` on the same PDO used for
projection, immutable effect intents and outbox. The common transaction commits
before returning. Caller transactions are rejected. The dated standalone append
API retains its original ownership; a participant AppendResult alone is not
commit proof. Explicit schema qualification and pinned trigger search paths avoid
reliance on a pooled connection's current search path.

The dated EventStore still assigns sequence/hash links and classifies identical
replay, collision, partial overlap and CAS conflicts. `ActionReducer` folds the
authoritative dated journal with chain verification; stored content hashes are
checked independently. Unknown kinds occupy sequence without lifecycle changes.
Run steps contain Action refs; Action events are never copied into Run checkpoints.
The projection digest is the SHA-256 of its canonical dated state. Rebuild folds
into fresh staging, checks the expected digest, then swaps only derived current
state under the stream lock. No provider/owner IO occurs during replay.

An effect_requested event must bind its effect key, owner namespace/operation key,
request hash and encrypted request ref to an EffectIntent. Current authorization
context and claim epoch are saved immutably. Older unqualified intents with null
context/epoch cannot pass committed lookup. Normal application updates cannot
rewrite journal evidence or immutable request fields. Deferred database guards
reject direct journal commits missing current projection, intent or outbox.

`committedIntent` is an idle-entry, fenced, current-authorized lookup that commits
its short read transaction before returning the persisted immutable request.
Unknown/resolved intents and former epochs cannot authorize a new owner dispatch.
The owner composition must still check business permissions, emergency/confirmation
guards, request bytes against the saved hash, and advertised idempotency/status
lookup capability immediately before IO. This storage slice supplies no effect
worker or retry loop and makes no exactly-once claim for arbitrary owners.

Tests use the existing EffectCommitter with a guarded test applier to show that an
in-memory request append cannot reach the owner. Trigger faults cover each SQL
write and COMMIT; a child exits after durable commit before owner IO. Independent
connections prove visibility and acquire rows NOWAIT at the owner boundary. Two
workers overlap at real locks and produce one CAS winner. Corruption tests disable
append-only triggers only inside their isolated schema to prove content/chain
verification, leaving production guards intact. The shared schema/no-dev checks
include additive migration 0003 and the production dated SDK dependency.

P3.5 must preserve unresolved intent/outbox retention and tenant/session boundaries.
ManualClock fractional truncation F1 is unchanged and is not used as timing proof.
