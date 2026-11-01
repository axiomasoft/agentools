# Typed code-only hooks

Use `PluginHost` as the HostBindings.host decorator in the trusted scoped factory.
No automatic provider, route, configurable class loader, second Runtime or effect
loop is installed. Registry registrations contain actual code objects and immutable
Manifest refs: explicit owner, id/version/hash, code hash/source, purpose, phases,
token ceiling and failure policy. Only selected pinned plugins are invoked; all
installed plugin caches are reset. Unregistered IDs/hash mismatches/revocations
fail, including for an old pin. Manifest source hashes are trusted installation
attestations; arbitrary PHP is not sandboxed. Install only trusted reviewed code.

ContextData executes after profile/data guards and before the host compiler.
CheckedDisclosure is an observer after mandatory full disclosure checks; it sees
only a checked ref and cannot return fragments or replace that ref. Its entry
metadata is null because HostAdmission.disclose has no entry argument: no route
identity is fabricated. HookInput contains technical refs/purpose only, with no
container, credentials, transport, executor, owner handler or mutable context.
Contribution can contain only bounded Fragment references with hashes, token
claims and provenance. Every fragment is untrusted_data, including injected
instructions. A trusted DataSink MUST verify exact bytes/hash, actual token bound,
current source ACL and classification, then expose them to its context compiler
as data. It must never interpret a fragment as policy, tools or executable code.

The wrapper always delegates authentication, tenant/session, profile/data,
compilation, deployment, disclosure and delivery to the original host. Definition
purpose, tool and region ceilings can only narrow the compiled work. Existing
budget, model, Actions/confirmation and delivery remain the same Managed Runtime.
Raw/build/stream/voice/background/direct-action entries use the same wrapper;
stream/voice remain buffered under the P6.1 contract. Optional settings cannot
remove guards or give a classifier/fragment authority.

FailurePolicy.abort returns a typed refusal; omit_optional omits an unavailable
optional enhancement and emits a safe code. ContractViolation, IntegrityConflict,
LifecycleFailure, current revocation and DataSink validation are always outside
that omission policy. No raw plugin exception message is emitted. Decision stages
include a bounded hash of the pinned plugin key for source traceability.

Reset attempts underlying host, every plugin, definition/plugin availability caches
and sink, even after failure. Availability.reset clears transient state while
preserving authoritative revocations. Reset must be idempotent when a host shares
one availability port between registries. Failure
throws LifecycleFailure into the existing worker-health poison/retirement logic.
All user state remains scoped. ProductionBinding lets HostBindings recursively
inspect this decorator, its registry, plugins and host ports, so wrapping a known
testing/fake implementation cannot hide it from the production guard. This is a
binding guard, not a durability or arbitrary-code security certificate.

P6.3 can supply port-backed testing implementations under the existing explicit
testing profile. Full SkillManifest selection, fragment loading/publication and
ProfileBundle compiler remain their P7 owners. The local contract tests verify
selection of one installed plugin among 100, not live skill/provider qualification.
