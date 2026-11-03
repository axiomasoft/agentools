# Final utterance ingress

`Boundary::open(opaqueHostHandle)` resolves `Request` through a trusted backend
`Owner`, performs current host authorization and channel consent checks, and
returns an `Envelope` containing the existing core `TrustedInput`. It makes no
model, TTS, Action, recording, export or Chatom calls. There is no media engine,
second compiler, history store, working state or effect loop in this extension.
The optional package has only the pure core as its production dependency.

The host accepts STT bytes through its authenticated media API, binds them to the
tenant/AssistantSession/assistant instance, verifies the immutable `text_ref`
and `text_hash`, and stores the normalized request behind an opaque handle.
The browser cannot supply actor, tenant, purpose, consent or an authorized
snapshot. This port is a backend contract, not an implementation of Loqum's
provider protocol. A concrete Loqum provider/version has not been qualified.

## Mapping and stable intake

The final-utterance schema and synthetic sample live in
`fixtures/platform/v1/voice/`. Its snake_case fields map one-to-one to
`Transcript` constructor fields (`is_final` maps to `final`). Optional raw
provider interim events may be normalized as `final=false`, but are refused
with `INTERIM_TRANSCRIPT` and create no Turn/message/work. Calendar timestamps,
same-tenant refs and ordered intervals are checked by typed runtime values too.

Source kind is the additive `voice-utterance`; source event ID is the canonical,
tenant-scoped hash of AssistantSession plus stable utterance ID. The existing
`SourceIdentity` also scopes it by assistant instance. `InputPayload.inputRef`
and `utteranceRef` are deterministic refs from that same identity. The semantic
content hash includes final revision, locale, start/end, immutable text ref/hash
and original context revision. Technical realtime session, epoch, transport
event ID, transport retry key and channel are deliberately outside that hash.
The backend must preserve utterance ID across partial/final/reconnect overlap;
it must not manufacture it from the current socket or arrival timestamp.

After opening, use `envelope.input` in the existing host profile/bundle,
Context Compiler and ordinary `IntakeRepository::accept(input, snapshot)` path.
`Boundary::assertCurrent(envelope)` rehydrates permission, consent, media fence
and content before admission/IO. Wire it into the host's current admission and
invocation guards as well as its delivery/disclosure guards. A prepared value
does not replace current owner checks, `IntakeAdmission` local transaction
preconditions or the existing owner checks at effect commit. External media
IO must not be performed inside the intake SQL transaction. The host must
serialize epoch changes with admission using its local admission fence when
atomic media revocation is required; this projection does not create a second
authoritative media checkpoint. Worker teardown/reset stays with host scope.

The existing durable intake returns the original Turn/Run receipt for an exact
replay and rejects different payload hashes as `IntegrityConflict` (the host
intake mapping of `SOURCE_PAYLOAD_CONFLICT`). A revised final is an explicit
edit/reconciliation; changing revision does not silently allocate another
source event. Both older and future epoch/context revisions are refused.
`STALE_CONTEXT_REVISION` carries `currentRevision`; the caller must refresh
through the owner. A reconnect rebinds the same utterance to the current media
epoch before replay. An old event is refused even if it was accepted before;
a final with an obsolete context revision also requires explicit refresh or
reconciliation rather than blending old and current state.

## Text continuity and owner handoff

`text-fallback` uses the same source, semantic payload, AssistantSession,
compiler, pinned state and bundle. For an accepted final, retry the existing
handle/utterance; never issue a new turn or model invocation merely to display
its response after a Loqum/TTS outage. For genuinely new typed input the host
allocates a new stable utterance ID. The media owner's last logical epoch/ref
remains available during outage; reconnect advances it monotonically. Host
authorization still applies to text; voice recording consent may be revoked
independently. Transcript words such as “yes” do not grant Action confirmation.

On consent revoke, `Owner::authorize`/`assertPermitted` must immediately deny
prohibited ingress and recording/memory/export/disclosure; the host/media owners
stop actual recording/export and apply the retention owner policy. This adapter
stores no audio, transcript bytes, credentials or domain history and cannot
assert that an external recorder has stopped. Credentials are backend-only;
frontend session/user refs remain opaque. Unknown owner policy is denial.

P11.2 owns response/segment/receipt binding to the existing AssistantSession and
Turn, conservative delivered portion, separate display evidence and exact
confirmation binding. P11.3 owns the combined fault/qualification journey.
Barge-in invalidates old media work and stops new disclosures/UI commands at
those boundaries; stopping media never cancels an already started Action.
The P11.1 synthetic interruption test proves only ingress fencing and stable
intake, not playout, heard material, command revocation or Action cancellation.
No unsent speech is added to history by ingress. The live prerequisite matrix
explicitly keeps acoustic/STT/TTS/remote-owner qualification pending opt-in.
