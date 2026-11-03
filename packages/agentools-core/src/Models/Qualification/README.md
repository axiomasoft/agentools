# Qualification snapshots and local rollout policies

These additive pure values/ports compose with the existing Router and guarded
ModelTransport. They add no provider/effect execution loop, protocol status,
production rollout, Botkit migration or per-request model judge.

`Tuple::forRoute` retains the complete original tenant-scoped routing hash
(provider/account/region, catalog, purpose, profile bundle, task and suites) and
adds explicit profile identity, bundle content hash, execution-policy hash and
evidence-policy hash. Prompt, skill, tool-schema, corpus and ordered suite-version
pins remain required. A model brand or healthy endpoint is never qualification.
Any required hash change makes the old snapshot inapplicable. Tenant identities
are explicit; changing a source hash under an accepted snapshot ID conflicts.

`EvidencePolicy` is supplied by the trusted host: exact cases, required scorers,
canonical allowed evidence kinds and scope. `technical.hard` cannot be omitted.
`Evidence` retains the original source result hash/ref, exact executed/required
cases and scorer outcomes, with separate domain/live/host/pilot statuses. Missing,
empty, extra or duplicate cases, a missing scorer, required unknown/not_run and
unsupported tiers produce insufficient evidence and nonzero qualification exit.
Hard failure or a nonsynthetic public corpus rejects. Design-schema/unit evidence
cannot qualify runtime. `assertClaim` refuses fake-only live/pilot readiness with
`EVIDENCE_SCOPE_MISMATCH`, an internal exception message, not a new wire enum.
Absent live credentials/evidence stays `not_run` without deleting technical receipts.

`Snapshot` derives its decision from those facts and binds them to the tuple and
policy hash. It enforces issued-at/expiry and exact tuple/policy applicability.
This module does not authenticate arbitrary model-authored evidence. The trusted
ingestion owner must verify P12.1 run/result seals, original tuple, exact cases,
source manifest and access policy before constructing `Evidence`; it must never
rebind a synthetic teacher result to an unrelated real deployment. Original
source-result hashes remain distinct from the tenant/domain-separated snapshot
hash. This contract permits a technical qualification while the domain outcome
is insufficient; it makes no blanket educational/live/production claim.

Compose `SnapshotCatalog(originalCatalog, snapshotStore, currentRequirements,
routeRequest, hasher, clock)` with the existing `Router` and `QualifiedTransport`.
It replaces legacy certificates in this composition with the newest applicable
snapshot, including later rejection/expiry; it preserves original admin/health
state and emergency rules. A null current owner requirements result (for example,
revoked material) denies. Router retains its current host/data/region/capability/
quota/emergency checks at finalization and before IO. No cached snapshot grants
present owner permission. The host resolves requirements for the actual pinned
bundle, not automatically to its newest publication.

`RolloutPolicy` pins stable/canary snapshot refs, cohort basis points, maximum
new canary admissions, owner baseline ref/hash, minimum sample count, explicit
owner metric bounds and audit reason. There are no default quality thresholds.
`Measurements` binds saved owner evidence to that baseline and exact candidate
tuple, time window, sample count, hard violations and nullable numeric metrics.
Absent/expired/mismatched/unknown measurements, hard violations or exceeded owner
bounds do not admit canary traffic. Sample bounds are owner decisions from saved
measurements, not a universal percentage or an LLM judge score.

Cohorts use a deterministic tenant/session/policy hash. `choose` produces an
assignment intent only; the PostgreSQL repository serializes capacity, current
eligibility and first pin creation. Existing sessions retain their original
assignment across publication, cohort changes and rollback. A blocked old pin is
denied, never silently moved to the canary. New assignments must also pass current
`SelectionGate`; this trusted port must compose exact tuple/evidence-policy,
Router/current authorization/quota and current material/disclosure guards. Unknown
or denied owner state throws. No permissive production implementation is supplied.

Publication requires explicit `Compatibility` validation for target bundles and
deployments, a reason and expected revision. Rollback names the current origin
policy and a prior published qualified stable snapshot. Compatibility, expiry,
latest qualification and current material access are checked again. Revoking
material can make the previous tuple unusable: rollback then fails until the host
supplies a corrected corpus/bundle and fresh qualification. A certificate does
not restore revoked data or authorize redisclosure of a saved response. Existing
Runtime/disclosure guards remain responsible for each actual payload boundary.
Rollback changes new-session selection only, with zero Action replay or pin edits.

Persistence and tests are in the sibling postgres Qualification module. The
fixture matrix, live recipe and owner handoff are under
`fixtures/platform/v1/qualification/`. Edufy owns tutoring/formula/child safety/
retrieval/voice/report scorers and expert gold; no student data is added here.
