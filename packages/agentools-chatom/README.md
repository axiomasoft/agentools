# Chatom text composition

Optional `agentools/platform-chatom` consumes the platform's existing intake,
session, context snapshot, invocation, budget, encrypted payload and delivery
owners. It installs no worker, scheduler, default host binding or public grant.

`NativeHistory` implements `HistorySource::recent`, `manifest` and `read` through
Chatom's real viewer projection. References bind the exact message revision and
content hash. Host authority and native binding are rechecked after owner IO;
raw snapshots only recheck an already authorized version. `currentMany` checks
all pinned input/history versions in one authorized batch after the last owner
IO; it does not cache an authorization across operations. Coverage is bounded,
and an unavailable or revoked read never becomes an empty successful message.

`TextProfile` hashes the complete explicit code configuration and persists its
immutable pins through `SessionRepository`. It does not publish a managed
profile or supply its own assistant authorization. The host supplies a current
`HostAuthorization`, private channel binding, capability evidence, transport,
credential reference, numeric retention and real budget owner.

The existing Chatom `PostgresInbox` calls `TextComposition::prepare` before the
real `IntakeRepository` commits inbox/Turn/Run/wake and returns its ACK. Source
identities and native tenant keys are unchanged. A host needing a different
platform tenant encoding must supply an exact scoped `TenantMapping`; there is
no alias lookup or fallback tenant.

`advance` performs one bounded native `AdvanceRun`. It saves compiled input and
request bytes through the existing encrypted payload owner and uses an explicit
native `ClaimPolicy`. `TextResponsePublisher` admits only completed plain text,
checks the current disclosure/moderation policy, and creates the existing typed
response node from the actual runtime response identity. `InvocationRepository`
commits that checked export and delivery intent under the same response identity
before destination IO. Completed Run recovery reads the saved export and settles
the real budget correlation without another model call. Unknown provider work
keeps the native recovery semantics.

An explicit `citationHistory` binding on the same `TextResponsePublisher` adds
one `agentools.ai-citation` v3 for the actual admitted Chatom input. `NativeHistory::citation`
uses the current owning viewer read and exact source revision, tenant, space,
channel and assistant binding. It constructs the reference before the immutable
intent/hash and encrypted export are stored. Model refs/proposals remain denied;
no model/client JSON becomes a trusted node. Completed response retry reuses the
saved typed export. The stored `source_revision` must still match the actual
Chatom message at send and display, alongside the current owning viewer grant.
Edit, hide or deletion redacts the citation; a revision pin supplies no authority.
Hosts must pin this export choice in their explicit profile version. The Edufy
`citeInputSource` setting defaults to false; enabling it pins `+input-source-citation.v1`.

The optional `OwnerReferenceProducer` is another trusted code binding on that same
publisher. It returns at most eight typed `OwnerReference` values: a currently
authorized published knowledge section with its positive owning revision, or a
currently authorized published business artifact ID. Its `pin()` belongs in the
host's profile version. The owning host verifies subject, audience and publication
before export and rechecks grants at send/display. These refs enter the immutable
intent and encrypted export before hashing; completed-response recovery reuses
them. Producer output is checked for type, bound and duplicate identities. Model
JSON and proposals cannot supply these values. No owner passage/artifact body is
copied into the transcript by this binding.

For Chatom's transactional Reauthorize, `DeliveryRepository::prepareCommit`
returns a nonserializable, same-operation SQL check after current host/source
preflight. The check re-reads immutable export bindings, source watermark,
payload availability, live lease and fencing token. A host must also recheck its
local binding, native revision, current request identity and credential expiry
inside that transaction. This is not a queued authority token or a replacement
for fresh remote preflight on the next operation. The Edufy consumer is
`Messenger\Infrastructure\Chatom\TranscriptResponseAuthority`.

Only final buffered model text and explicitly bound owning references are composed here. Tools/Actions, owning
viewer policy, background principals, voice, managed profile publication and
deployment cleanup/retention authority are separate contracts. The synthetic
provider transport in the isolated host test is a boundary fixture, not a live
model qualification. No production activation follows from installing this
package.
