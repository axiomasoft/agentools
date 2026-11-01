# Profile publication and session versions

P7.1 supplies the private, opt-in `Core\Profiles` contract and
`Postgres\Profiles` publication/session repositories. It changes no dated SDK
wire status, existing BundleRef serialization, runtime loop or package namespace.

Install `Profiles\Schema::install($pdo, $schema)` explicitly as the schema owner.
It is checksum/version pinned, transactional and independent of the first-slice
runtime migrations. It never runs on package boot. Application roles must have
only their intended table privileges; the schema owner remains a trusted setup
actor. Published content, dependency/provenance history, migration history and
command receipts cannot be edited through the repositories or ordinary SQL
UPDATE/DELETE. A failed commit rolls back history, head and receipt together.

Construct `CodeRegistry` from trusted platform code. A `ResourceRef` has an
explicit platform/tenant owner, resource kind, ID, version and content hash.
Definition, profile, model revision, provider account, deployment, skill,
methodology, knowledge and execution policy are different resource kinds.
InstanceId is a separate tenant assignment identity. `LayeredCatalog` resolves
platform resources exclusively through the code registry and tenant resources
exclusively through the tenant overlay; neither falls back to the other.
An overlay cannot inherit another tenant's profile or install executable code.

The host supplies a trusted `BundleCompiler` and a dependency catalog with
exact installed hashes/provenance. Compiler code must resolve the complete
transitive dependency closure and deterministically compile effective fields
and resolved restrictions before LLM use. The publisher independently reruns
that compiler and compares canonical output. Unknown/missing dependencies,
wrong hashes/provenance, duplicate versions and wrong compiler pins fail.
`Authority` verifies creator/validation/evaluation refs against the candidate
hash; a reference's presence is not evidence of a completed evaluation. There
is no permissive production authority or compiler implementation.

The lifecycle is draft → validated → published → deprecated → retired.
`draft(context, manifest, expectedRevision)` creates/edits only draft content.
`validate` records issues and retains draft on failure. Validated bytes are
frozen; changing them requires a new bundle version. `publish` reruns validation
and live policy rather than trusting an earlier validation verdict. Published
version/hash identity is stable and publication replay recovers the same result.
Deprecated bundles accept existing pins but reject new assignments/migrations;
retired bundles allow authorized historical inspection only. Revocation does
not delete historical provenance.

Use `Profiles\SessionRepository::assign` with a stable tenant command ref,
InstanceId, exact code definition ResourceRef, published BundleRef and expected
assignment revision. This is the profile assignment authority for hosts using
this API. It is an opt-in successor to P6.2's initial assignment mechanism;
do not run two assignment authorities for one instance. A host migrating its
composition must explicitly map the P6.2 definition's owner/id/version/hash to
the Core ResourceRef. No Botkit migration or existing-data backfill is included.

`pin(context, instance)` atomically saves the assignment's version/hash for a
new session. Assignment @8 leaves an existing session on @7. Retrying after a
lost response recovers the original pin before considering the latest assignment.
The original `Runtime\SessionRepository` and P6.2 SessionBinder remain technical
bootstrap bindings; this API does not rewrite those immutable identities.
Hosts opting into versioned profiles compile from this repository's SessionPin,
and must not overwrite it with a legacy binder's original snapshot.

Upgrading an active session is explicit:

```php
$pin = $sessions->current($context, UseBoundary::Invocation);
$next = $sessions->migrate($context, $stableCommand, $pin->revision,
    $publishedTarget, 'owner requested compatible upgrade');
```

The host must positively validate compatibility. The SQL transaction appends
revision, target version/hash, reason, compatibility ref, actor, command ref and
timestamp, then advances the session head. Concurrent/stale migrations fail CAS.
Same command/different request is an integrity conflict. Retry reuses its original
receipt. Changing an instance/session's code definition requires a new instance;
it cannot be smuggled into a profile assignment or migration.

At **each invocation, action and disclosure**, the host calls
`assertPin(context, compiledPin, boundary)` immediately before the existing
execution guard chain. That checks the current session version, owner context,
dependencies and `Authority::use` (emergency, revocation, current deployment and
permissions/material policy). Pins grant no authority. A migration makes work
compiled with the old pin stale. Host permission changes that race external IO
remain subject to the existing execution/owner/disclosure guards. These
repositories add no effect loop, handler registry, guard bypass or automatic
Laravel binding; callers must wire the port into their existing composition.

For recovery, inspect the exact bundle ref/hash under current authorization.
After publish-response loss, retry that version; never rebuild it from latest
dependencies. After pin-response loss, retry the same session/instance. After
migration-response loss, retry the same command/source revision/target/reason.
After a commit failure, retry the unchanged request after the fault is removed.
Never reset a pin, delete history, silently retarget a session or replay an
unknown business effect to repair profile metadata.

`bundle-7.json`, `bundle-8.json`, `skill-dependency.json` and
`skill-manifest.example.json` are generated by
`php packages/agentools-core/tests/Profiles/generate.php`. They use explicitly
scripted provenance, not live evaluation evidence. The skill example describes
only a versioned dependency. The skill manifest is a hashed design example;
its final schema, validation and selection belong to P7.3.
`paired-merge.json` contains design vectors for P7.2, not executed merge proof.
P7.2 should implement a trusted compiler using these immutable publication
values; P7.3 should add its manifests through the same typed dependency closure.

The owning gate is `profile-publish`, with separate contract-scripted and
isolated-postgres partitions and exact case IDs. Focused tests use
`tests/Profiles/publication.php`, `examples.php` and
`packages/agentools-postgres/tests/Profiles/run.py --filter CLASS_OR_METHOD`.
The durable partition requires two actually contending PostgreSQL connections,
three post-commit SIGKILL/restart probes and a deferred commit failure.

Evidence is local/scripted plus real isolated PostgreSQL, never live-provider
or production qualification. D8 excludes classical mutation tests and scores.
P4.4 findings-only review, P5.2 historical RED, P2.4 fractional ManualClock and
P3.5 audit/reset uncertainty remain historical/unresolved as recorded upstream.
P6.5 Authority.prepare retry and dual-dispatch risks remain; its Scheduling graph
check fix did not visibly receive Grok review. This slice does not change them.
