# Confirmed direct write

This synthetic headless host composes the canonical Action registry, durable
submit/status, `ConfirmationService`, the dated PostgreSQL replay store and
`EffectCommitter`. It creates no Assistant or model and performs one bounded
owner operation. It is not a production owner integration.

The host provisions a schema with `Migrator` and calls `Owner::provision` for its
two synthetic domain tables. With explicit isolated PostgreSQL credentials and
separate base64 host keys in `AGENTOOLS_CONFIRMATION_SIGNING_KEY` and
`AGENTOOLS_CONFIRMATION_SEAL_KEY`, run:

```
php examples/platform/confirmed-write/run.php <provisioned-example-schema> synthetic-ui
```

`synthetic-ui` explicitly simulates an authenticated host event. Production UI
and voice adapters must supply `ConfirmationAuthority`; model text, a transcript
"yes", tool output and arbitrary callback data never populate that trusted port.
The CLI prints only safe action metadata. Tokens and presentations are encrypted
at rest through a host sealer; signing/encryption keys remain host-owned.

`Service::prepare(submission)`, `approve(token, submission)` and
`commit(token, submission)` have no caller `required`, `allow`, `GateDecision`,
actor, clock or evidence parameter. The submission context is host-created.
Identical retries restore the same persisted proposal/token, including spent or
expired tokens; they never mint another approval. A different input, definition,
scope or policy needs a new Action submission key and a fresh preview/decision.

The existing at1 signed tuple is unchanged. Its tenant/action/argument fingerprint,
descriptor digest, principal, proposal/version and expiry remain signed directly.
The descriptor digest additionally covers the platform binding hash and pinned
definition. That hash binds action version/epoch, tenant/actor, definition
version/hash, input/request/scope hashes, policy revision, expiry and the exact
presentation hash. Immutable preview and consent records are anchored in the
same dated journal, projection and outbox transaction. A proof row alone cannot
grant approval. at1 has whole-second expiry: preparation explicitly rounds down
before computing the binding, preserving the original request deadline.

The dated service durably consumes once and rechecks current policy, journal
approval and explicit authorization. Consumption is not an effect receipt.
The owner loads committed intent and checks the current action epoch/cancellation
and its own permission row inside its business transaction. Revocation before
that transaction denies the write. A crash after consume never permits token
reuse. A crash after owner commit requires lookup using the saved owner key;
this example does not implement automatic retry/reconciliation.

Cancellation persists a separate idempotent command and dated request where the
action is nonterminal. It does not erase a known success or reinterpret an
unresolved effect. Compensation is a separate registered Action with its own
current authorization and confirmation; cancellation never starts it.

Focused examples:
`python3 packages/agentools-core/tests/Actions/Confirmation/pg.py --filter testRevokeBeforeOwnerWrite`
and `php php/tests/Confirmation/platform-binding.php --only=recipient,caller-flags`.
The owning gate requires exact portable and isolated PostgreSQL case sets.
