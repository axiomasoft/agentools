# Optional profile administration

Explicitly register `InspectorServiceProvider`, then bind scoped
`Profiles\Bindings` in trusted host code with the canonical P7.1
`BundleRepository`, `SessionRepository`, P7.2 `TypedCompiler` and a host
`Profiles\Access`. Use the same tenant/context/connection, dependency catalog,
compiler and current `Profiles\Authority` for these services. The host remains
responsible for production persistence, provenance, emergency/revoke and
compatibility decisions. `Access::reset()` must clear the associated host
profile authority/catalog permission caches. Testing ports are refused in
production; host service graphs must implement `ProductionBinding` where needed.

Add `ProfilePlugin` explicitly to a host-authenticated Filament panel. Provider
registration creates no routes. `InspectorPlugin` continues to register only
the read-only inspector. The headless Laravel installation has no Filament
dependency. The host chooses the current tenant, actor, purpose and session on
every request; no form field grants authority.

Save a canonical `profile-bundle/v1` draft manifest, then validate and publish
with the returned bundle reference/hash and expected revision. Validation uses
the headless validator; typed merge conflicts display source refs and value
hashes. Projections omit compiled fields and exception messages. Published
content edits require a new bundle version. Deprecate and retire use the
headless forward lifecycle transitions.

Assignment authorizes and audits the typed instance ref along with the bundle.
It requires an instance, stable command reference, reason reference
and expected assignment revision. It affects new sessions. Explicit migration
is a separate confirmed action on the host-selected session, with the expected
pin revision, stable command, reason reference and host compatibility check.
It never derives a session from editable Livewire properties. Current guards
continue to apply above existing pins.

All writes require a durable requested audit before the effect and a result
audit before disclosure, with actor/session/bundle/operation/code/reason/command
refs. An uncertain failure or failed post-write audit returns `outcome_unknown`;
inspect/reconcile using the same reference before retrying. Host authentication
failure audit remains host-owned. Do not log manifests, prompts or exception
payloads. UI confirmation is additional intent UX; authorization lives in the
host and canonical services.

Owning suite: `python3 tools/platform_gate.py test --suite profile-publish-admin`;
isolated partition: `python3 tools/platform_gate.py pg-test --suite profile-publish-admin --isolated`.
Exact cases/tiers are in `tests/profile-cases.json`. Tests use real PHP
Filament/Laravel services and isolated PostgreSQL with scripted host ports;
they do not qualify a browser deployment, live provider or production host.
