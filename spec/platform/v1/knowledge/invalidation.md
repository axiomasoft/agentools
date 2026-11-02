# Dependency invalidation and disclosure

P8.3 adds an optional explicit PostgreSQL dependency graph. It stores technical
references, exact versions and SHA-256 hashes, never owner source bytes. Nodes
cover material, fragment, card, release, bundle, snapshot, cache, message, summary,
memory, history_index and context artifacts. A node identity is tenant/kind/ref/
version; the content hash and complete sorted parent set are immutable. Parents
must already exist and be usable. A tenant transaction lock serializes graph
registration, invalidation, use recording and deletion intent creation.

Correction invalidates the exact source version. Delete/revoke/consent revoke
also tombstone the source identity so a later version cannot resurrect it. Deletion requests support a bounded read
and an exclusive request-id cursor so completed records cannot starve later work.
Ancestor checks immediately deny every derivative, including stale cache hits,
before asynchronous provider deletion. Unregistered nodes fail closed. New
versions after correction require distinct identities and new releases. The
knowledge PostgreSQL repository registers material -> fragments/cards -> release
and release -> bundle/snapshot/cache, and publishes corrections atomically with
old-source invalidation. Shared downstream nodes may have multiple parents when
registered through the graph API; their parent set is fixed at creation.

`Node` holds tenant, kind, ref, version and a bare SHA-256 hash. `PostgresGraph`
uses trusted `Scope` and a host `DependencyPolicy` for every public operation.
`usable` rechecks current owner policy and local tombstones. Owner unknown is
preserved as `Decision::Unknown`; boolean repository adapters fail closed.
`recordUse` binds a stable tenant/run identity to an immutable sorted node set.
`markers` returns source_corrected/source_deleted/source_revoked/consent_revoked
annotations separately from the original facts. Record dependencies before invocation; later marker reads annotate that run
without changing its original facts. Replaying a recorded use is an audit replay,
never permission for a new invocation.

Provider resources attach to exact nodes with a provider ref, documented delete
support (supported/unsupported/unknown), and optional known expiry. Invalidation
persists deletion intents for affected resources in the same transaction. Local
use remains denied independently of provider residue. `DeletionWorker` persists
an attempt before calling its explicit transport outside the transaction. A
replayed started attempt returns unknown without repeating IO; a completed
attempt returns its saved result. A new explicit attempt can retry a failed or
unknown request. The transport must honor the stable request id as its provider
idempotency key. Unsupported deletion reports unsupported and known expiry;
no inferred immediate erasure or provider capability is claimed. Receipt attempt
identities and input/result hashes conflict on substitution. No automatic loop
or provider integration is installed.

Core stays independent of the knowledge package. Hosts wire current dependency
checks into ContextAccess (including graph nodes for retrieved fragments), use
`History\DerivedContextAccess` for summary/memory blocks, and implement
`SnapshotAccess` for their exact snapshot graph node. `authorizeReuse` checks a
compiled context's integrity, run, turn, bundle, pins, scope, TTL, current block
permissions and current snapshot dependency permission immediately before a
cached artifact is released again. History and memory selectors recheck after
view loading. A pin, old EvidenceSet or compiled context grants no new rights.
External Chatom/MemorySource owners retain transcript, consent and deletion
ownership. P8.3 does not replace those owners or install Laravel wiring.

Evidence is a host contract harness and isolated PostgreSQL, with scripted
provider/owner ports. It does not prove live erasure, external integration,
provider support, production readiness or instantaneous cross-system revocation.
The tenant lock favors correctness over concurrent writes within one tenant.
