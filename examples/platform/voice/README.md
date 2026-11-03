# Synthetic voice/text host journey

This opt-in example feeds scripted noise, partial/final STT and reconnect events
from `events.json` through the existing Loqum ingress, one core Context Compiler,
ordinary PostgreSQL intake and the real `AdvanceRun` journal. Scripted model IO
has a durable attempt counter, not just a unique-row count. Native response,
proposal/Action and usage identities survive replay. The optional proposal path
submits through `ActionApplication`; it never executes an owner effect itself.

`php examples/platform/voice/run.php` requires the repository's explicit isolated
test PostgreSQL environment. It verifies restricted database ownership and the
allow marker before creating a unique test schema, then cleans up only that
schema. For disposable Docker setup use the focused conformance PG runner.
It does not select development/production databases or call live providers.

The default runnable path accepts one final, compiles current host state, runs
one scripted model exchange, persists three checked speech segments, client-acks
one, displays all three, and interrupts speech. The printed heard summary is
materialized only from the receipt-bounded UTF-8 prefix through current owner
access, never from the entire generated response. Client acknowledgement does
not prove cognitive understanding. The printed tuple is the actual synthetic
deployment/profile/prompt/tool/corpus tuple used by that run.

The host/media adapters are synthetic, separately authorized ports backed by
test tables, with an ephemeral encrypted payload vault. They prove local mapping,
deduplication and policy boundaries. They do not implement a Loqum wire driver,
real Chatom transport, audio alignment, recording, memory/export systems or a
production key/retention owner. The fixtures contain invented text only.

TTS receipt loss stays unknown until same-key status lookup; it does not request
another model response or resend acknowledged speech. TTS outage preserves the
same Turn/Run/response and explicit text path. Reconnect changes media epoch,
not utterance/source identity. Locale/subject/policy/context changes are saved
as host checkpoints and require a fresh authorized input/snapshot; vault payloads
also bind the full execution scope, so a current actor cannot fetch an old
subject's payload through a remembered ref. An old context cannot silently
continue under the new subject. Owner epochs/revisions
cannot move backwards.

Barge-in stops new media/generation and revokes pending commands; an independent
Action remains independent. Explicit Action cancellation acknowledges a durable
request, not a terminal cancellation or compensation. The conformance journey
also completes a real confirmed owner write and proves interruption leaves it
completed. Expired UI commands use a host-facing `expired` projection of the
existing refusal; no dated wire enum is extended.

The `conformance/platform/voice/qualification-fixture.json` matrix names external
owners, contracts, approved data and gates for live transport/acoustic/child
speech/retention/pilot evidence. There are no invented language-quality scores,
latency thresholds or educational qualification claims. Installation of this
example creates no routes, service, second effect loop or public release.
