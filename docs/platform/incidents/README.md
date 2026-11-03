# Incident response and live qualification handoff

These are operator playbooks for the private local platform. They do not create
or change an external owner, authorize production deployment, or confer access.
All actions require the current host operator and tenant authority. `dry-run` is
the default where the operations facade supports it; record only opaque refs,
actor, reason, scope, status and receipt IDs. Never put prompts, raw payloads,
transcripts, credentials or private data in logs or incident artifacts. See
[`../operations.md`](../operations.md) and [`../security.md`](../security.md).

For every incident: establish exact tenant, deployment, task, data class and
current policy; inspect the affected run and owner status through authorized
ports; preserve pinned facts and unknowns; assign an operator and escalation
window; do not execute a blind retry. Current emergency blocks and revocations
apply immediately, including to pinned work. Only new admissions may use a
previously qualified compatible tuple after current permissions and data rules
are checked. Record local evidence separately from external owner/provider status.

| Trigger | Containment | Recovery and exit evidence |
| --- | --- | --- |
| Deployment disabled or incident-blocked | Block new admission and disallowed IO; do not assume pins bypass the block. | Inspect existing invocations and unresolved outcomes. Route only a new invocation through a currently permitted, qualified tuple; otherwise provide typed non-AI degradation. Record changed scope, authority, and status. |
| Provider outage or timeout | Stop new calls to the affected deployment and retain provider response as unknown if not proven. | Lookup the same provider request key when supported; unsupported lookup remains manual/unknown. A fallback is a new invocation, never a replay of an effect. After disclosure, interrupt and require a new explicitly disclosed segment. |
| Data/region ban or permission revoke | Apply current ban before provider or owner IO, invalidate unauthorized derivatives for new use. | Revalidate trusted host rights and region for every newly admitted request; no cross-region escape. Historical references remain annotated, never rewritten. |
| Cache revoke, stale evidence or source correction | Deny cache use and invalidate dependents, including summary/context derivatives for new work. | Check source/ACL version, consent and cache owner deletion/expiry receipt; rebuild only from currently authorized sources. Missing delete confirmation stays pending. |
| Reservation/admission/settlement unknown | Preserve pending reservation and correlated usage; no automatic release or zero cost. | Query the owner with the original idempotency key and exact request hash. Reconcile only proven status/usage; unsupported lookup is manual escalation. Never make another settlement on a new key. |
| Host, metering, effect or delivery owner down | Stop dependent IO; preserve pending outbox, leases, intent and unknown outcome. | Reconcile the same owner key with current rights. A delivery may replay only if owner dedup/lookup proves safety; unresolved effects remain visible. Resume only after durable owner evidence and without repeating a known applied effect. |
| Voice to text or interrupted playout | Stop banned/unknown audio output and preserve actual display/playout receipt; generated text is not heard audio. | Deliver a new text segment only under current host permission, with explicit disclosure after interruption. Do not execute actions from partial audio; retain unknown heard bytes and reconcile delivery first. |

## Live-provider handoff

Default is **not run**. `python3 conformance/platform/live/probe.py MANIFEST`
performs an offline shape/freshness check and makes no calls. It does not verify
that URL domains are provider-owned or content proves capabilities or price.
Before any later live run an authorized operator must independently verify
current official model, capability, pricing and expiry for the exact deployment,
account and region; approve synthetic request hash and cost/call/data bounds;
install the real trusted host bindings, current admission/emergency checks and
durable budget/status reconciliation. Consult
`fixtures/platform/v1/qualification/live-probe-recipe.json` and the opt-in
`conformance/platform/provider-fallback/probe.php`. A separate explicit owner
spend/data scope is required before using that live entrypoint. An unavailable
price, owner, credential, capability, quota or status lookup stays unknown or
not_run, never zero, success or qualification.

The recorded `../baseline.json` is produced by
`python3 -B conformance/platform/live/baseline.py > docs/platform/baseline.json`.
It measures one local run of existing evaluator golden/fault tests. Zero calls
and zero actual provider spend describe this offline run only; they are not
provider pricing or production SLOs. Re-run on the target environment before
using timings for any operator decision.
