# Warming and erase limits

Construct `Manager` with a tenant-bound durable `Store`, current host `Guard`, actual
provider cache port, compiler `Rebuilder`, ordinary `Admission` and canonical
`WarmAccounting`. The optional PostgreSQL implementation is
`AgenToolsPlatform\Postgres\Cache\Repository`; install its checksum-pinned schema
explicitly. No framework routes, default unlimited budgets or provider SDK claims
are added. Test-only memory/scripted implementations never enter production autoload.

`BudgetAdmission` maps an actual compiler-finalized context into host `AdmissionScope`
and uses the established `BudgetBoundary`. A cache hit supplies no local allowance.
`WarmAccounting` also uses that owner boundary and the P9.2/P9.3 `UsageNormalizer`/
`UsagePort`. Supply a provider-specific overlap mapping. It exports observed warm/
refresh tokens and reported amount; absent storage prices/amounts stay NULL.

Warm only a requested profile, stable permitted nonpersonal material and declared
tools/skills/policy. The host guard must reject entire child transcripts, diaries,
changing permissions and material lacking provider-storage rights. Popularity,
time window, operation count and estimated-unit bounds are required; the repository
serializes tenant quotas across processes. These scheduling limits are not a money
or entitlement ledger. `WarmPolicy` requires measured/forecast beneficial economics
or an explicit host choice to warm for performance without a savings claim.

Each warm/refresh has a stable operation identity/hash and original admission JSON.
The store persists its intent before admission/provider IO and reservation ref before
create/refresh. Its reply/descriptor/canonical usage retry checkpoint commit locally
together. The descriptor is unavailable until canonical accounting accepts the fact.
Accounting retries retain the same event identity. Existing warm calls dedup, and
changed payload under the same operation conflicts. Lost reply/crash retains unknown
units/cost exposure; recovery never blindly calls create again or releases funds.
Late results cannot overwrite an already recovered immutable unknown fact.

The authoritative reservation reconciler consumes the saved original admission key,
reservation ref and canonical usage fact. It settles known normalized facts once or
retains unknown/manual resolution through the P9.3 owner contract. This cache module
does not invent owner settlement/erasure APIs. A later provider fact must be a normal
immutable usage correction, not an overwrite or another business debit. Continuing
storage charges must arrive from the authoritative metering owner; this module does
not infer them from TTL or a local cache hit.

Revoke sequence:

1. Commit local denial/fence and dependency invalidation with a deletion intent.
2. Request provider delete only with current verified support, outside SQL locks.
3. Preserve the actual receipt or unknown/unsupported outcome and resource expiry.
4. After a lost ACK, use supported owner status with the same operation key; do not
   blindly repeat deletion. Local use stays denied regardless of provider residue.

Deletion tombstones survive worker restarts and late warming completions. A supported
delete requires a receipt; unsupported delete never claims erasure. `expired` means
resource use TTL elapsed, not proven physical deletion. Unsupported/unknown residue
requires host operator policy and actual provider retention guarantees.

Call bounded `retireIdle(before, now, limit)` with the host policy's idle cutoff
(normally now minus `WarmPolicy::idleSeconds`). Retirement denies the old resource
first and preserves its delete/expiry record. It allows a later newly admitted warm
if current source rights and demand remain valid; material revoke uses a persistent
key fence. Do not refresh idle, revoked or unrequested resources to claim savings.

All provider callbacks run outside store transactions. Reauthorization occurs after
external use/create, and `touch` checks the local fence after admission. Existing
cache records never freeze ACL, consent, emergency deployment or storage permissions.
