# Chatom bridge contract v1

Server injection only. No new dated wire enum or SDK namespace. DeliveryPort receives
native DeliverySubmission and exposes submit/context status/cancel(reason). The existing
DeliveryRepository owns durable state, lease/CAS and immutable receipts. Receipt binds
typed destination tenant/ref, saved key, payload hash and known outcome/evidence ref;
unknown requires a null message ref. No sender may overwrite a same-key changed payload:
contract code DELIVERY_PAYLOAD_CONFLICT uses existing integrity-conflict exceptions.

Native source identity is tenant + source kind/event + assistant instance, not a browser
nonce. One chosen source relay calls the fenced existing inbox, which commits dedup and
wake before ACK. Native OutboxRepository/scanner owns wake delivery. Exactly one selected
outgoing bridge claims each logical destination; destination native-key dedup additionally
protects uncertain retries and competing workers. Missing required suites/stores fail.

The host publisher supplies a checked export before hashing, including display/interrupted
text and typed citation/artifact/action refs under the same audience/ACL. Raw model refs,
private tool arguments or child transcript metadata are not approved exports. Existing
TextResponsePublisher/ResponseIdentity define the real typed document; this extension does
not replace their export grammar. Changed text requires a new immutable response version
and explicit supersedes relation from that publisher, never same-key overwrite. The
synthetic fixture's minimal JSON is illustrative and not a new Chatom wire format.

Before external IO the platform claim commits uncertainty. The destination MUST enforce
current membership/visibility/hidden/held checks and the supplied current callback at its
mutation boundary. Its native tenant/destination/client key is immutable and differing
hash conflicts atomically. lookup returns the exact original message/evidence after lost
ACK. A known refusal needs an owner evidence ref. Missing result stays unknown. No response
regeneration, model call, Action replay or alternate destination follows from send failure.

No transcript copy is stored in bridge SQL. Existing encrypted payload metadata includes
purpose/classification/key ref/expiry and cleanup receipts; decrypted bytes are transient.
History requests use current viewer policy and exact owner refs with bounded deterministic
windows. Deletes/revokes cannot be undone from temporary payloads or diagnostics.

Edit/delete/purge invalidation events remain in the owner's durable event journal until
all idempotent steps acknowledge. Source closure can precede graph invalidation; retry the
same versioned event, without treating its SQL source receipt as full cleanup. Graph
invalidation covers registered derivatives; every other external owner requires its own
receipt. Runtime retention preserves only permitted technical audit/tombstone refs.

Evidence partitions: contract-scripted (history/value/production closure), isolated-postgres
(native intake/delivery/encrypted payload/derivation plus scripted destination). DEL-001 is
a PostgreSQL boundary journey with completed-generation state seeded as a precondition.
It does not execute the model kernel or real Chatom driver. Neither tier proves remote
host_e2e/live-provider/pilot/production readiness. Real Chatom pins are pending separately.
