# Optional knowledge ingest and immutable releases

This additive platform extension leaves the dated Actions wire and SDK namespaces
unchanged. Hosts explicitly wire `AgenToolsPlatform\Knowledge\Pipeline`; installing
the package creates no worker, route, model loop, provider call or database table.
Schema installation is an explicit host action on a dedicated PostgreSQL connection.

Library/Content owns material meaning, publication, audience, access and license.
`Material` is an exact tenant/id/version projection with `ownerRef`,
`accessPolicyRef`, `licenseRef`, immutable `bytesRef`, `sourceHash`, audiences,
required scopes and locale. Files/Vaulter owns bytes, derivatives, scan/hold and
retention. This extension stores technical release metadata and bounded extracted
artifacts, and never becomes a second file store or licensing authority. Hosts
must bound extractor output for their deployment; this package imposes no claim
of suitability for unbounded documents.

The initial release is one source-version partition of a corpus. Hosts may assign
multiple exact release references to a corpus/bundle. There is no implicit
"latest", mutable fragment update, pointer switch or automatic embedding.
Subsequent versions, extractor revisions and embedding index revisions use new
release IDs. Same tenant/corpus/release ID with a different hash conflicts.
The material projection itself cannot change under an existing tenant/id/version.

## Pipeline and ports

`DocumentSource::load(Material): SourceDocument` resolves the exact immutable
Files/Vaulter version after access authorization. The transient result contains
source bytes and ordered canonical UTF-8 page text. A PDF/OCR adapter supplies
this mapping and its own engine dependencies; the PHP-only domain has none.
Original bytes and full page bodies are discarded after validation. Extracted
fragment/node text may overlap full small pages in the plain text adapter.

`Extractor::extract(Material, SourceDocument): Extraction` returns an extractor
revision and structural nodes, fragments, navigation summaries or knowledge cards.
Each artifact retains a stable local ID, kind, one-based page, half-open UTF-8
**byte** offsets `[start,end)`, source/page/content SHA-256, parent node and
optional normalized `[x0,y0,x1,y1]` box. Coordinates refer to canonical page text;
box coordinates are optional adapter assertions, not independently verified
visual OCR accuracy. Generated cards/navigation use a supporting source range;
their generated text has its own content hash. Nodes/fragments must match the
source range exactly. Source version and rights are inherited from the release's
material, so an artifact reference always includes the release hash.

`GenericValidator` is mandatory before publication. It checks exact source
ref/hash, nonempty UTF-8 pages, codepoint-aligned byte ranges, finite valid boxes,
source/page/content hashes, matching extracted text, unique IDs, parent range
containment and acyclic structural hierarchy. Every page must include the
configured required kinds (default node + fragment); hosts can also require
navigation/cards. A second `Validator` may add subject-specific rules. Results
are `validated`, `failed` or `unknown`; only `validated` publishes. Generic
validation does not validate pedagogy, answer keys, standards or formulas; Edufy
retains those rules. No partial or unvalidated release is stored by the pipeline.
Storage decoding verifies integrity for reading, but cannot authorize publication:
the repository accepts only releases made by the source-validation factory.

`MaterialAccessPolicy::check(Scope, Material, operation): Decision` is a host port
for current owner permissions, purpose, rights/license, hold/revoke and emergency
guards. Allowed, denied and unknown remain distinct; only allowed proceeds.
Scope is supplied by a trusted host, never from browser/model assertions. Local
tenant/audience/required-scope filters run before source load. The host check runs
before extraction, again after validation, and at the repository publication
boundary. No owner I/O runs inside the adapter transaction. External owners must
provide current decisions; a distributed owner-revoke race cannot be made atomic
with a local PostgreSQL commit. Local invalidation serializes with publication.

`KnowledgeRepository` stores immutable releases, enforces tenant-scoped replay
and hash conflicts, and exposes `get`, `invalidate`, `link`, `dependencyUsable`.
Every get rechecks current permissions and local invalidation even for pinned
releases. Corruption causes refusal. An absent release returns null, a denial or
outage raises an error; neither becomes empty successful evidence.

## Correction, dependencies and persistence

`Pipeline::ingest(..., previous: Material)` publishes a distinct version of the
same tenant/material and atomically invalidates the previous exact version.
A release conflict rolls back the correction. `invalidate` adds an irreversible
local tombstone even before extraction/publication exists, blocking delayed
publishers. Repeated invalidation is idempotent; the first reason remains the
historic annotation. A release payload never changes. Historical annotations are
the append-only invalidation table; `get` refuses new use of those versions.

Exact bundle/snapshot/cache refs link to tenant/corpus/release. Rebinding a
dependent identity conflicts. A tombstone makes every linked dependent unusable
without rewriting its historic content. Hosts must use this check when consuming
dependencies; propagation to external owners remains their adapter contract.
SQL triggers reject UPDATE/DELETE on release, invalidation and dependency records.
The schema lifecycle belongs to the host; it must not grant untrusted clients SQL
or schema-owner access. Runtime repository connections cannot join ambient
transactions. Advisory locks serialize the same tenant/material across independent
connections. Reads filter by tenant; SQL dependency foreign keys include tenant.

`EmbeddingSpace` keys every provider/model/revision/dimensions/normalization/
indexVersion field. Equal dimensions do not imply compatibility.
`EmbeddingTransport` is an optional explicit port; ingest does not call it.
Re-embedding uses a new space/index/release, never historical mutation. Mixed-space
nearest-neighbor search requires a separately specified explicit transform.

Shared examples are generated by `php extensions/knowledge/tests/generate.php`.
The registered `knowledge-ingest` partitions enforce exact, nonempty case sets,
no skips, explicit contract-scripted versus isolated-postgres tiers. Durable
tests exercise competing processes and SIGKILL before/after repository commit.
Both partitions bind reports to unchanged input hashes and declare their harness
limitations; the gate records a digest of the exact suite manifest.
These are host contract harness results, with no external Library/Files, OCR,
provider, pilot or production qualification claim.
