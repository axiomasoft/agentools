# Direct Actions

`Composition` builds `ActionApplication` with the PostgreSQL `SubmissionLifecycle`,
the canonical code registry and explicit host ports. It creates no Assistant or
model. Submit durably accepts an action; an outbox event is a technical wakeup,
not proof that an owner ran. This example performs no owner IO.

After the host provisions a schema and applies `Migrator`, run
`php examples/platform/direct-action/run.php <schema>` with explicit isolated test
PostgreSQL credentials (`AGENTOOLS_TEST_PG_DSN`, `AGENTOOLS_TEST_PG_USER`,
`AGENTOOLS_TEST_PG_PASSWORD`). The CLI writes to that schema; the scoped test
runner provisions and verifies its own disposable PostgreSQL automatically.
No database or schema is dropped by the CLI.

Host authentication, business authorization, payload storage and operational
policy remain host responsibilities. `Host`, `Policy`, and the single-schema
validator here are synthetic adapters, not production integrations.

The stable tenant submission key binds the request hash, origin and optional
already-persisted proposal. A duplicate returns the original `created/1` receipt,
even when current status has advanced. A changed hash conflicts before dispatch.
Proposal submissions reuse `proposal_map.action_ref`; retries must reuse that saved
proposal rather than normalize a new proposal after fallback.

Status requires the original tenant, actor, session, purpose, authorization and
policy scope, plus current host permission and registry availability. It returns
dated status/version, pinned definition and a safe next step, without input,
effect payloads, owner operation keys or owner result refs. A refreshed scope
does not inherit disclosure rights automatically. Current revocation still wins.

`reconciliation_required` has typed `Outcome::Unknown`, with
`safeNextStep=manual_resolution`. The operator must use the saved owner operation
key through an authorized owner lookup; an absent/unknown lookup does not permit
a new write. This example performs no owner dispatch or reconciliation.
Cancellation persists an idempotent request independently of known effect outcomes;
reconciliation still fails closed. The sibling [confirmed write](../confirmed-write/README.md)
composes confirmation atop the pinned submission/definition without changing dated
wire statuses.

Focused checks: `php packages/agentools-core/tests/Actions/submit.php --only=action.outcomes`,
`python3 packages/agentools-postgres/tests/Actions/submit.py --filter testStatusScope`,
and `(cd dart/agentools && dart test test/platform/action_submit_test.dart)`.
