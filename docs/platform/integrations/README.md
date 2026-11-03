# Local host handoff v1

This is a private integration handoff, not a release or a request sent to an
external owner. `handoff.json` pins the source contracts and focused local test
logs by SHA-256. Run `python3 docs/platform/integrations/handoff.py --check`
to reject missing or stale contract files and the exported evidence index. The
index records hashes for separate log verification. Focused logs do not replace the
coordinator's aggregate acceptance receipt. The referenced stdout/stderr logs
are under `artifacts/implementation/P10.4/` in the plan's evidence bundle;
`focused-evidence.json` includes the hash of each log.

| Boundary | Executed here | Not established |
| --- | --- | --- |
| School-shaped support | Real core read runtime, intake/outbox, action and delivery on isolated PostgreSQL; scripted school context and destination | Edufy API, education report mutation, real child data or remote host E2E |
| Support-shaped | Same real runtime and PostgreSQL with a different scripted context provider; native destination ACK lookup | Remote support integration, production messenger, live provider |
| Confirmation/effect | P10.3 independent direct-action evidence; separate path | An education or support assistant write in this suite |

The school example is an education-helpdesk context attached to the existing
support read tool. It is not the `EducationApi.readReport` implementation and
is not a replacement for the P10.1 host boundary contract. Tests inject a
pre-dispatch destination outage, then retry the saved delivery; lost ACK is a
separate scenario after destination acceptance. The two cases are not claims
about a remote messaging implementation. Scripted external owners do not make
a provider, pilot, or production ready.

## Owner contract and unresolved work

- Host integration revision `spec/platform/v1/host`: the authenticated host
  resolves an opaque handle into tenant/session, source identity, payload hash,
  purpose, authorization and approved context slices. Current permission,
  consent, policy and emergency state must be rechecked; a pinned projection
  is not a grant. Owner returns minimal projections and separate owner refs.
- Intake identity is tenant + assistant instance + source kind/event id. Same
  identity and payload hash returns the original turn/run; a changed hash is an
  integrity conflict and creates no new work. Stable proposal and effect keys
  carry immutable request hashes to their respective owner boundaries.
- `OwnerPort.execute(Request): LookupResult` and `lookup(Request): LookupResult`
  use a tenant-scoped native operation key and hash. Owner authorization, epoch,
  cancellation, consent and expected business revision are enforced at the
  owner mutation boundary. Succeeded and failed require bound evidence; unknown
  is not failure, success, absence or permission to resend. Lookup must remain
  available after confirmation expiry subject to current permission.
- Delivery revision `extensions/chatom/contract.md` v1: the existing delivery
  intent binds destination, immutable response version/hash and stable key.
  A lost receipt stays unknown; lookup uses the same key/hash, and changed
  content needs a new response version. There is no model regeneration on retry.
  Chatom is optional, and no real Chatom client-key behavior was qualified.
- Calls have explicit per-definition deadlines and retry limits. The synthetic
  support read definition uses timeout 1000 ms, deadline 10000 ms and at most
  two attempts; these are example values, not a promised external SLA. Network
  timeouts or connection loss after dispatch are unknown unless the owner gives
  authoritative evidence. No timeout is converted to zero usage or success.

External Edufy, Chatom and other owners must implement and qualify their own
public ports, immutable version pins, native dedup/lookup, current permissions,
retention and error mapping before external E2E or rollout. The existing
`EDUFY-AGENTOOLS-HOST-001` revision 1 and `CHATOM-BRIDGE-PIN-001` revision 1
remain pending; no sibling repository has been changed.
