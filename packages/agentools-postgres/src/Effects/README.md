# Effect recovery

`Ledger` stores the original input ref/hash, tenant/action/effect identity, owner
namespace/key, authorization expectation and epoch before `Service` calls the
owner. `Service` performs one bounded step and never runs an independent loop.
Owner execution and lookup run outside the local transaction. Acceptance locks
and verifies the journal/projection and the current epoch again. A lost local
receipt leaves unknown; `reconcile()` looks up the same operation key. Accepted
success and failure are immutable in both the repository and PostgreSQL.

`Strategy::takeoverDecision` preserves the dated private
`IdempotencyCoordinator::takeoverDecision` invariants without importing the SDK
into pure core: native keys allow the same key/hash; read-before-write requires
an authoritative absent result; no probe or unsupported capability denies
replay. The typed result distinguishes absent, unknown, unsupported and known
success/failure. Absence includes owner evidence and excludes an older in-flight
request; an ordinary eventually consistent missing row does not qualify.
Owners must enforce concurrent/stale callers' operation keys and request hashes
atomically. Local fencing cannot provide exactly-once behavior for arbitrary APIs.

Retry is explicit (`reconcile(true)`), capped by the registered definition and at
most three sends, with at least one-second database backoff before each retry. The
definition deadline, submission deadline, confirmation expiry, cancellation and
current registry/emergency policy all apply. The example's maxAttempts=1 still
forbids any automatic repeat.
Every retry keeps the original operation/hash. Capability changes fail closed.
`Ledger::takeover()` advances the local fence without changing the original
request. The host must coordinate ownership of this bounded step; stale local
acceptance fails and the owner key still prevents duplicate external effects.

`UnresolvedQueue` exposes bounded tenant/session candidates. Unknown preserves a
`reservation_disposition=retained` checkpoint, never a money-ledger update.
Accounting reconciliation and release remain external owner responsibilities.
`Service::resolve()` requires host `ManualAuthority` authentication and evidence
verification. Journal provenance records operator, evidence ref and known outcome
basis. The platform cannot verify an arbitrary owner's proof itself. P5.4 can
compose this primitive into the cancellation/operator workflow; compensation
still requires a separate authorized Action and confirmation.

Action projection repair reuses `Actions\ActionRepository::rebuild()` and its
canonical journal digest comparison. The effect ledger never rewrites history.
The suites use synthetic owner implementations and real isolated PostgreSQL;
they do not qualify external owners, providers, production or release readiness.
