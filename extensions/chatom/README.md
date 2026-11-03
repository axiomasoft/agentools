# Optional Chatom delivery/history bridge

Private additive `agentools/platform-chatom-bridge`. It owns no transcript, model loop,
Chatom tables, routes, scheduler or default destination. Existing
`packages/agentools-chatom` and its namespaces are preserved. The host explicitly wires
its existing `NativeHistory` into `BoundedHistory`, and its qualified Messenger driver
into `Destination`. The scripted examples are never production dependencies.

`Incoming` uses the existing fenced IntakeRepository: source dedup, Turn/Run and wake
outbox commit before ACK/dispatch. Chatom's selected source relay owns committed source
events and event retries; the platform owns its technical inbox and wake outbox. An
adapter may wrap the existing PostgresInbox, never relay the same logical input twice.
Current host identity/session/purpose is reconstructed before entry, as in P10.1.

`DeliveryPort` is an extension seam over existing `DeliveryRepository`. submit commits
response/version/hash/key and encrypted payload refs before relay. `PostgresDelivery`
performs one bounded existing delivery claim, destination lookup, optional native-key
send and receipt settlement. It has no model/Action dispatcher dependency. Scanner
pending recovers commit-before-dispatch. Run completed is independent of delivery.
Only an exact destination/key/hash receipt establishes success or failure; exceptions,
lost ACK or missing lookup remain unknown. Unknown does not mean authoritative absence.
Native-key retry is allowed only under the Destination contract's atomic dedup promise.

The adapter uses the SAVED key unchanged, including when wired to the existing
PostgresDeliveryOwner/BridgeKeys export. Fixture IdentityKeys.delivery keys prove local
tenant/run/response-version/destination identity only; they do not qualify Chatom's real
client-key encoding. Real Chatom version/client key pin remains separate evidence.

Membership, current visibility, hidden/held moderation, emergency policy and selected
relay are checked again before owner IO and inside Destination.send. Its same-operation
callback checks native source/payload/lease/epoch without remote IO under runtime locks.
The driver must enforce its own owner transaction and current permission; IDs and cached
revision refs grant no rights. prepareCommit supplies no cross-database cancellation
atomicity. Reauthorization failure never licenses regeneration or a new key.

HistorySource remains owner-provided. BoundedHistory adds bounds/order/exact source
version/hash/tenant/expiry and pre/post current authorization checks, preserving
unavailable/revoked/deleted distinctly. No fallback reads diagnostic payloads. Existing
NativeHistory is the real viewer-projection adapter; synthetic support/editor tests
prove host independence, not real Chatom driver behavior.

Invalidation.apply consumes the OWNER's durable committed source event. It closes the
native delivery/input source fence and invalidates the existing summary/candidate/history
index/context-cache graph. ACK only after all steps; retry the same event after partial
progress. No new effect-capable loop or second journal is installed. Physical payload/
provider-cache cleanup belongs to the registered existing owners and retention policy;
source closure alone is not their cleanup receipt. See runbook.md and pin-matrix.json.

Focused files: tests/contracts.php, tests/delivery.php, tests/recovery.php. The PG harness
supports --case ID (unknown/empty selection fails); the gate still requires its exact
full partition. tests/run.py is conductor-owned aggregation. Dev setup: composer install;
then composer install --working-dir=tests/no-dev --no-dev --no-scripts. Production closure
proof: php tests/no-dev/check.php. No live provider or production activation is included.
