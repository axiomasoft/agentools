# Edufy host contract harness

Development-only synthetic owner implementations, explicitly loaded by bootstrap.php.
No provider calls, external Edufy code, migrations or production fallback bindings.
Export contract.json + fixture.json + change-request.json to the Edufy owner; its real
implementation and host_e2e proof are pending (EDUFY-AGENTOOLS-HOST-001 revision 1).

Production composition injects HostOwner/EducationApi/ReportEvidence in Laravel scoped
bindings. Queue only Managed\Input; open it again per request/job, and reset Boundary
in finally. Feed envelope.input to existing managed admission/intake, envelope.slices
to the existing context compiler, and ReportAdapter to the existing Actions effect
service after ConfirmationGateway approval/commit. The adapter has no loop, routes,
model dispatch or database connection. Its native-key contract requires owner qualification.

SchoolHost/SchoolApi/SavedEvidence/IntakeStub are single-process fixture owners only.
SavedEvidence models the owner lookup of saved approval; it neither exercises nor
reimplements the dated token/PG confirmation gateway. No durability or crash-recovery
claim follows. SchoolApi owns its synthetic official report revision; IntakeStub stores
only technical ACK identities/hashes, never report facts, transcript or learning records.

Focused files: php conformance/platform/host/{identity,boundary,report,ownership}.php.
Coordinator suite: python3 tools/platform_gate.py test --suite host.
Tier: contract-scripted; evidence_kind: host_contract_harness.
