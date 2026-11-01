# Typed profile composition

`Core\Profiles\Composition` implements the P7.2 monotone composition contract.
It has no LLM, handler, transport or effect loop. P7.1 BundleRef serialization,
publisher/session APIs, dated SDK statuses and existing mandatory guards remain
unchanged. Hosts explicitly install the compiler and current policy ports.

`PolicyLayer` carries a tagged owner, resource kind, stable ID, version, full
content hash and provenance. `Document` hashes its typed content, including
defaults, editable declarations, restrictions, styles, material changes and
methodology selection. Stored/cached inputs should supply expectedHash when
reconstructing documents. `TypedCompiler` additionally compares the exact complete
dependency closure against the immutable bundle manifest. A mismatched cached
owner/version/hash cannot fall back to latest. Global tool schemas come from
`ToolRegistry`, built by trusted code; profiles cannot install executable code.

Every composition requires exactly one definition, platform, tenant and profile
layer. Selected skills and lessons are separate bounded layers. Platform ownership
is explicit; a tenant layer must name that tenant. Source identities cannot carry
different content/provenance within one compilation, including current overrides.
The code definition alone declares `DefaultSettings` and `EditableSettings`.
HardConstraints, style overrides and OperationalOverrides are different types.
Unknown/null bounds never mean unrestricted access.

The deterministic rules are:

- Forbidden values are a union, separately for regions, deployments, data scopes,
  tools and capabilities. Allowed values are intersections minus those denials.
- Tools also intersect the pinned registered tool catalog. Runtime use further
  intersects explicit host, session and live availability inputs. Removed tool
  schemas are absent from both CompiledProfile and JSON-serialized result objects.
- Empty region/deployment/tool/data-scope results fail closed. Required
  capabilities are a union and must remain available; a `tool:<id>` capability
  cannot survive removal of its actual tool.
- Confirmation uses the declared internal order None < Required < Fresh, and
  safety uses Baseline < Strict. Floors take max; tenant/lesson/style input cannot
  lower them. Fresh asks the host for fresh confirmation evidence through its
  existing guards; it introduces no wire status or parallel confirmation flow.
- Budget is min of explicit nonnegative integer caps in the host admission unit,
  including the current host cap. Zero means exhausted and blocks invocation.
  This is a policy cap, not a money ledger or metering source of truth.
- Style priority is definition, platform, tenant, profile, selected skill, lesson.
  Only code-declared tone/display_name fields can be overridden. Names are bounded
  UTF-8 data without control characters; tone is a closed value. Conflicting
  overrides at the same priority fail rather than choosing by incidental order.
- Methodology requires one compatible pack when the definition declares a
  compatibility key. Multiple packs require a separately pinned approval manifest
  containing their exact ordered owner/id/version/hash references. `none` explicitly
  permits no methodology. There is no implicit prompt concatenation.
- Material additions and exclusions are explicit pinned Knowledge references.
  Exclusions are a union and cannot be silently undone by another layer. Every
  included material passes the current required MaterialAccessPolicy at compile
  and at each invocation/action/disclosure preparation. A denial returns a conflict;
  an unavailable policy propagates an error and never becomes allow or zero.

`Composer::compile($input)` returns CompiledComposition or ProfileConflict.
Conflicts contain code, field, source_refs, source_values_hashes and a safe reason;
source values are hashed instead of included as diagnostic payload. No model
arbitrates an incompatible policy, and no permitted default is invented.

For publication, inject `TypedCompiler($codeRevision, $inputCatalog, $composer,
$canonicalJson)` into the existing BundleValidator. InputCatalog must resolve
the exact pinned typed documents. Preview uses `resolve` to retain full conflict
provenance. The compiler adapter throws CompilationFailure on conflict; P7.1 records
failed validation as draft issues. Its existing list-of-issue-codes format stays
unchanged; callers can obtain field-level details from resolve/preview.

For an active session, first use P7.1 SessionRepository current/assertPin and the
existing host guard chain. Reconstruct the original bundle with `compiler->pinned`;
it checks compiler and compiled bytes as well as all dependency pins. Then:

```php
$current = $composer->prepare($pinned, $hostLimits, $sessionLimits,
    $liveAvailability, UseBoundary::Invocation);
```

The three OperationalOverrides roles are required and rechecked. The result can
only narrow the pinned policy. Repeat at action and disclosure boundaries. Host
and owner authorization still apply immediately before IO/effects/disclosure.
A locked deployment preference cannot override an incident ban; an unavailable
unlocked preference is dropped without selecting another deployment. Conflicting
viable preferences require an explicit host choice. These operations do not alter
bundle hashes or session version assignments and never perform an implicit upgrade.

`compatible.json` and `conflict.json` are executable paired CM-001/CM-002 inputs.
Other named cases are in merge.php, merge-live.php and PublicationMergeTest.php.
CM-003/005/006 and the tenant positive/cache-owner fault cases use the existing
real isolated PostgreSQL lifecycle and publisher. The owning `profile-merge` gate
requires exact portable and PostgreSQL case sets, current source hashes, strict
Composer validation, scoped PHPStan and a fresh production --no-dev install.
Focused tests run single files or `merge-pg.py --filter PublicationMergeTest`.

Evidence is contract-scripted with real isolated PostgreSQL and scripted host,
material and evaluation owners. It is not live-provider or production qualification.
P7.3 owns skill manifest selection/routing; selected skill policy inputs here are
already explicit. D8 excludes classical mutation testing and scores. Historical
P4.4 findings-only review, P5.2 RED, P2.4 fractional ManualClock, P3.5 audit/reset
uncertainty and P6.5 prepare-retry/dual-dispatch risks remain. The Scheduling graph
check fix is not claimed to have received a visible Grok review.
