# Versioned skill manifests and bounded selection

`Core\Profiles\Skills` supplies metadata-only SkillManifest, immutable publication
records, a deterministic router and an exact fragment loader. It imports only
Common and Profiles values/ports. It has no model judge, handler registry, effect
loop, default host bindings, routes or external storage/evaluation implementation.

A manifest hashes explicit owner/id/version/provenance plus purpose, triggers,
required context kinds, allowed tool ID/version/hash references, prompt fragment
refs/hashes, rubric and evaluation refs, token budget, safety class and exact
definition/profile/compiler compatibility. Publication status and qualification
are separate lifecycle evidence on PublishedSkill; raw manifest data cannot claim
published authority. ManifestCodec rejects unknown/inline fields, duplicate names,
changed hashes and implicit null platform ownership. Metadata is bounded to 8 KiB,
16 triggers/context kinds/tools, four fragments/rubric/evaluation refs and a
positive fragment token ceiling of at most 16384. No prompt body, schema catalogue,
history, corpus, curriculum or credential belongs in a manifest.

Registry separates trusted platform registrations from the tenant publication
catalog. Tenant reads are exact owner/id/version/hash lookups. A platform miss
never falls back to a tenant overlay. Published versions are immutable; hosts
must persist Publisher results with tenant-scoped identity/hash conflict checks.
The core supplies no implicit database, public publish path or mutable latest alias.

Publisher requires current publication permission before loading content. It
checks compatibility, exact fragment identity/hash/provenance, content kind,
current semantic content/audience policy and a qualified deployment-specific
token bound. Then the host Policy must supply actual deterministic AND task-specific
evaluation evidence. References alone are not proof. Rejected or insufficient
evidence cannot become a publication. Host-owned durable storage must preserve
the immutable content and certificates and reauthorize its write boundary.

Qualification is bound to deployment/model revision, profile bundle hash,
task/purpose, suite versions, prompt refs/hashes, skill content hash, filtered tool
schema hash, corpus hash and counter revision. It is not a model-brand approval.
Current certificate applicability/revocation/expiry is checked without rerunning
evaluation every turn. A changed tuple requires corresponding evidence; missing
domain gold stays insufficient_evidence. The scripted certificates in these
fixtures prove generic technical contracts only.

SelectionRequest carries an authenticated host's fixed routing inputs, context
kinds/hash, exact skill pins, current host/session/live limits and qualification
environment. Hosts first enforce P7.1 assertPin and existing guard composition.
The router checks compatibility, current permissions, publication status and
budget using metadata only. Shortlist size is 1..16. A known host route selects
its exact pinned skill or fails; it does not choose another version or route.
Without a known route, candidates are ordered by explicit owner/id/version and
the first eligible candidate with required context is selected. Missing mandatory
context returns typed missing_context, with no invented fields or content reads.

This slice selects **one skill per invocation**. That is the current selection
bound and keeps each qualification tuple exact; arbitrary multi-skill prompt
composition is not implicitly qualified. Fixed routing inputs produce the same
semantic selection manifest across Runs, while the private Run binding prevents
replaying one Run's snapshot as another Run's request.

Only load(request, storedSelection) reads full fragments. It uses exact selected
refs, rechecks the request/pins/current rights/qualification, verifies bytes and
provenance, counts the actual fragments, and rechecks rights/revoke before release.
It never asks for latest. Current unknown status/count/policy/storage is a typed
non-success, not allow, empty content or zero cost. Partial loads are never
returned as a prepared request. Technical selection snapshots and hashes remain
audit history; audit overlays a current revoked marker without rewriting them.

Fragment stores return typed content envelopes. Secret/history/corpus/curriculum/
tool-catalogue kinds are rejected. Policy.fragment remains mandatory to catch
sensitive content disguised as a skill fragment; generic core does not claim a
universal secret or domain classifier. Publication and load invoke that current
host policy. All accepted text, including third-party instructions such as
"ignore tenant policy", is serialized as untrusted data with its original
classification/provenance. It cannot modify policy, tool definitions or trust.

Projection narrows the existing P7.2 session tool set using exact installed
tool versions, then reapplies profile/host/session/live intersections, safety
floors, denials, budget and deployment restrictions through Composer.prepare.
The actual deployment must match its pin and the current allowed/selected
deployment. Unavailable schemas never enter PreparedSelection. Skill token
ceilings are separate from host admission/money units. Existing full-request
token accounting, authorization, confirmation and disclosure guards still apply.

PreparedSelection is the model/context projection: selected fragment data,
filtered code schemas, resolved restrictions and selection refs/hashes. A host
maps data to ContextBlock(kind=skill, trust=untrusted, original classification)
and then to the existing model request. It must not serialize the full registry
or promote skill text into system policy. No second model or effect loop is added.

New qualified versions/canary/rollback are explicit host choices of pinned refs
for new Runs. Existing request/snapshot bindings continue to their old versions;
revocation applies now even to an old pin. Persist selection snapshots with the
existing technical request/context evidence before invoking a transport. Durable
assignment/storage and cohort policy remain host-owned hooks, not a production
rollout implementation in this core module.

Generate manifest.json, fragment.json and selection.json with
`php packages/agentools-core/tests/Profiles/Skills/generate.php`.
They are synthetic support data, not live evaluation evidence. Focused proof is
contract.php, lifecycle.php and examples.php. CM-007 records 100 registered
manifests, one selected full fragment, one recorded ModelRequest and only search;
CM-008 records exact hash failure without latest substitution. Negative/fault
cases exercise all forbidden payload categories, cache corruption, missing
context, token unknown/exhaustion, current revoke, qualification changes, version
assignment and injection without permission/classification changes.

The owning skill-selection gate requires exact cases/observations, source hashes,
strict Composer validation, scoped max-level PHPStan and a fresh standalone core
--no-dev install with no SDK, PostgreSQL, framework or test owner. Evidence remains
contract-scripted, not live/provider/production qualification. D8 excludes classical
mutation testing. P4.4 findings-only review, P5.2 historical RED, P2.4 fractional
ManualClock and P3.5 audit/reset uncertainty remain. P6.5 prepare-retry/dual-dispatch
risks and the lack of visible Grok review of its Scheduling graph fix are retained.
