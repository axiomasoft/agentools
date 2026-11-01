# History and memory contract (additive platform/v1)

`HistorySource` is the external transcript owner. `recent` returns a bounded exact
window after a turn watermark; `manifest` attests complete source coverage;
`read` resolves an exact source ref/version/hash. Available, unavailable, failed,
revoked and deleted are distinct. AgenTools retains refs and hashes, never an
indefinite second transcript. Exact strings are transient context/extractor input.
The external-owner-adapter.php example delegates to a host Messenger/Chatom port
with current host authorization. Its tests are scripted host-contract evidence,
not a claim that Chatom or AiCore adapters are deployed.

WorkingState is bounded goal/step/question/result/UI/promise/action refs, schema
version, optimistic version, acceptance ref, expiry and retention. Model output is
a WorkingProposal. WorkingStateEditor requires host acceptsState before storage;
the PostgreSQL repository also requires Authority acceptance, including direct
calls/replay. Host policy decides whether a prior promise/question can be removed.
Mandatory working-state and requested exact boundary messages survive optional
historical pruning. History message block IDs retain chronological turn order through the compiler's
stable ID ordering. HistorySelector bounds relevance to <=32 recent messages and
<=16 mandatory exact refs, never silently truncating a mandatory message. The
compiler performs deployment token budgeting. Unavailable mandatory source refuses;
unavailable optional history returns an explicit degraded status and bounded state.
A summary whose coverage overlaps mandatory exact messages is excluded wholesale.

SummaryVersion is immutable source_manifest/through_turn/version/source hashes,
covered/understood/uncertain/open-question/promise/artifact/action refs, view ref/hash,
extractor version, classification, creation and expiry. All summary text remains
untrusted data. SummaryRebuilder obtains a complete manifest and exact messages,
then rechecks sources and consent after extraction. No previous-summary text is
an extractor parameter. It cannot mark an unavailable rebuild revalidated, and
classification cannot fall below source sensitivity and summary expiry cannot
outlive its earliest source expiry. Through-turn never decreases
within a branch. Rebuild produces a new version and preserves prior metadata.
TriggerPolicy supports configured token threshold, segment completion,
topic/session/model transition and periodic exact-source rebuild; it does not
require work on every turn. Hosts schedule due triggers and provide qualified
input token observations; this library does not invent a tokenizer or scheduler.

SummaryViews resolves the separately owner-stored derived view with a pinned hash.
HistorySelector emits untrusted ContextBlocks with exact message IDs, source refs,
through-turn and summary version. Text and voice continue through the P7.4 compiler.
SummaryRefs/uncertain observations never assign authoritative domain facts or
instruction privileges. Operational state has its own retention; encrypted prompt
diagnostics remain the separate P7.4 host port.

MemorySource is external: candidate query/read, owner CAS persistence, dedup,
view resolution and owner delete process. MemoryLifecycle requires proposed ->
storage_permitted -> evidence_checked -> host_accepted -> active -> revalidated.
Every transition checks current host consent/policy. Evidence uses exact source
refs; unavailable evidence is not revalidated. Dedup is tenant/subject/type/value
hash scoped. Null confidence stays unknown. Diagnosis, emotion, secret and
third-party candidates require explicit manual owner acceptance; they cannot
activate automatically. Observation acceptance is by the supplied domain owner
(e.g. AiCore), not by generic summary. Values are external refs, not a local
universal memory engine. MemorySelector verifies active lifecycle, expiry, source
rights, dependency validity and view hash and always emits untrusted data.
External MemorySource must enforce current authorization/consent, stable request
identity, hash conflict and CAS itself, including when its API is called directly.

The opt-in PostgreSQL Summaries schema stores metadata-only immutable summary and
working-state versions, CAS heads, exact source pins, reverse dependency refs,
tombstones and historical run correction markers. Same identity/version with a
different hash conflicts. Source registration serializes with invalidation.
Deletion/revocation/consent invalidates summary/candidate/history-index/context-cache
refs for new use and annotates linked completed runs. Correction invalidates the
old source version; a newer exact owner version can rebuild new summaries.
Historical versions are not rewritten or retroactively declared never delivered.
Hosts must register run/cache/index edges before use and route source/consent
events through Revocation. Revocation persists local invalidation before requesting
the external owner's delete process. Pending/unknown/failed delete receipts do not
mean completed deletion. Host consent must deny further extraction/export before
that process completes. Cross-owner activation/deletion is not a distributed SQL
transaction; owner APIs must reauthorize and host event delivery/reconciliation
must be durable. P7.6 owns aggregate stale/revoke/disclosure regression.

Generate JSON examples with `php packages/agentools-core/tests/Context/History/generate.php`.
The retention-revoke matrix records technical effects versus owner obligations.
No live provider, production readiness or mutation-test qualification is claimed.
