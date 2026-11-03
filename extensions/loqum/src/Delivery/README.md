# Speech delivery over existing runtime output

Inject one `Coordinator`, `Ledger` and authenticated `Owner` into the host's
existing output path. Install `Schema` explicitly on the same PostgreSQL schema
as the existing sessions/turns/runs. No routes, model execution, Actions execution
or Action cancellation are installed. `Ledger::register` verifies that the
supplied assistant/session/turn/run relation exists in the ordinary runtime.

`Response` binds tenant, assistant instance, AssistantSession, Turn, Run,
response ref/version, destination, current context revision and disclosure
policy. Its logical delivery key is tenant+assistant+turn+version+destination.
Changed response/payload under that key is a conflict, never an overwrite.
`Segment` has a stable ID/sequence, separate display and speakable refs/hashes,
byte lengths and conservative UTF-8 boundaries. An edit needs a new explicit
response version/replacement relation from the host, not regeneration on retry.

## Independent facts and retry

Registration records generation metadata only. Synthesis records an artifact
ref, not delivery. Display has its own receipt/confirmed prefix and
pending/unknown/displayed state. It never advances speech history. Missing
playout evidence remains sending/unknown even if the Run completed, synthesis
succeeded, or all display text is visible.

`PlayoutReceipt` maps to the fixture schema: response ref/version, segment and
attempt refs, media epoch, delivered offset, completed/interrupted/failed status,
and server_estimate/client_ack evidence. Only an authenticated media owner may
produce it; incoming calls resolve an opaque receipt handle. Offsets are UTF-8
text bytes at the segment's declared boundaries, not audio milliseconds. A
real provider adapter must conservatively map audio playout to those immutable
text boundaries. Without trustworthy alignment use whole atomic segments.
Boundary/payload UTF-8 validity is checked before TTS.

Server estimates stay separate and leave delivery unknown. Only client-ack
prefixes enter `history()`, ordered contiguously by segment sequence. History
returns bounded `{text_ref, through_bytes}` slices. Its consumer must materialize
only that prefix through the current authorized owner; passing the entire text
ref to a summary/compiler would violate this contract. No receipt proves
cognitive understanding. Unsynthesized, undisclosed and displayed-only suffixes
are excluded. Consent revoke blocks history projection too.

Playback intent is committed before media IO. A crash or lost acknowledgement
requires `reconcile()` before another send. Mere absence of a receipt is unknown.
`Lookup(not_found)` requires owner fencing that proves the old request cannot
arrive/play later. It is not inferred from an empty query. Final client receipts
must fence completed/interrupted attempts too, so a confirmed prefix plus a
known undelivered suffix is safe to retry. Owner media APIs enforce these fences
at actual IO, beyond the local guard/checkpoint boundary.

Retry uses the same segment identity/hash and a stable suffix operation key
derived from segment+confirmed offset. Synthesize/play only that suffix; already
confirmed bytes are never resent. An unchanged suffix retains its key and
artifact across retry/reconnect. Reconnect explicitly advances the ledger epoch,
preserves confirmed progress, and does not turn unknown into failed/zero.
Old-epoch receipts are refused. Reconciliation across reconnect requires the
authenticated owner to issue current-epoch evidence for the same operation;
do not relabel an old immutable receipt or manufacture a new source event.

## Disclosure, interruption and consent

`Owner::payload` supplies output from the existing guarded `DisclosureStream`,
not raw generated text. The coordinator verifies response/version/sequence,
hash, size and checked/live policy evidence before synthesis and playback.
Buffered output is one complete checked segment; checked_segments releases
only closed checked segments. Sensitive policy remains buffered. Live output
requires the existing explicit risk approval. Host ports recheck current
membership, source permissions, consent, policy and epoch at actual IO.

`interrupt()` commits a stop fence and revokes pending commands before calling
the capability-aware media/TTS/generation stop port. Confirmed prefix remains;
in-flight uncertain playback remains unknown. Stop is idempotent and retryable
even after policy pins become stale. The explicit `authorizeStop` port must
authenticate current scoped termination authority when ordinary purpose or
consent permission has been revoked; it grants no disclosure capability.
It does not mutate Run/Action status or
claim that an owner effect was cancelled. Actual Action cancellation uses the
existing separate application API.

The closed UI allowlist is presentation-only `highlight`/`scroll`, version 1,
schema `voice-ui/v1`. Commands bind context revision, expiry, payload ref/hash
and a fully client-confirmed playback anchor. Same-ID replay is idempotent;
changed payload, stale/expired/revoked commands are refused. A claimed command
with a lost receipt remains executing/unknown until host reconciliation. The
client owner enforces payload schema, current policy and same-ID dedup at actual
execution. Business reads/writes, grading, send and money use Actions/owners.

`revoke()` commits the disclosure/history stop first, then stops media and calls
the owner to stop prohibited recording/memory/export and apply retention policy.
It records the owner's durable retention receipt; retries preserve the stopped
state. This ledger stores refs/hashes/offsets only, no audio or transcript bytes.
External recording/export shutdown and retention cannot be claimed from a
synthetic owner. TTS outage leaves the same response available through the
guarded, idempotent display/text path; it never requests another model response.

## Voice confirmation

Inject `VoiceConfirmation` as the existing platform `ConfirmationAuthority`.
It delegates presentation and consumes only an authenticated immutable
`ApprovalEvent`. It verifies tenant, actor, session, exact proposal/binding,
preview hash, actual input hash, policy, current media/context revision and expiry.
The host must verify a complete immutable preview display/playout receipt.
Transcript “yes”, model text, incomplete presentation and missing proof return
no approval or fail closed. No browser-provided allow flag is supported.

The adapter returns the existing voice `Decision`. The hardened confirmation
service still owns journal-backed approval, current authorization, signature,
single-use token and durable consumption. Signed at1 and dated ActionStatus
remain unchanged. The owner still rechecks business invariants at effect commit.

Tests use real isolated PostgreSQL, a real confirmation/effect lifecycle, and
scripted media/host ports. P11.3 must compose these with P11.1 into the combined
voice/text fault journey. Live provider, acoustic alignment, browser playback,
real recording/export/retention and remote host support remain unqualified.
