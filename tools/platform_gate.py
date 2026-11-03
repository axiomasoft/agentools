#!/usr/bin/env python3
"""Private platform gate. A successful runner exit alone is never test evidence.

Owning slices register concrete partitions with exact case IDs and dependencies.
This module never reads plan state and never installs dependencies or calls providers.
"""
from __future__ import annotations

import argparse
from collections import Counter
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
CORE = "packages/agentools-core"
TESTING = "packages/agentools-testing"
TIERS = {"design-schema", "unit", "contract-scripted", "isolated-postgres",
         "local-host-e2e", "live-provider", "product-pilot"}

# Explicit, independent of discovery: removing a test must make the gate red.
SELF_CASES = tuple("GateTests.test_" + name for name in (
    "bootstrap_recovery_rejects_missing_partitions", "baseline_incidents_registry", "qualification_rollout_registry", "operations_registry", "observability_registry", "evaluation_registry", "voice_journey_registry", "voice_delivery_registry", "voice_ingress_registry", "host_e2e_registry", "second_host_registry", "chatom_registry", "host_registry", "architecture_fixture", "boundary_current_tree", "boundary_missing_source",
    "boundary_production_testing", "boundary_duplicate_owner", "boundary_core_dependency",
    "boundary_autoload_escape", "boundary_transitive_testing", "boundary_namespace_path",
    "boundary_production_source", "contradictory_totals",
    "sdk_adapter_registry", "model_transport_registry", "runtime_registry", "read_journey_registry", "action_submit_registry", "confirmation_write_registry", "effect_reconcile_registry", "cancellation_resolution_registry", "write_recovery_registry", "laravel_composition_registry", "platform_definitions_registry", "composition_testing_registry", "filament_registry", "scheduler_registry", "platform_registry", "fsm_registry", "testkit_registry", "pg_schema_registry", "pg_intake_registry", "pg_claims_outbox_registry", "pg_action_journal_registry", "pg_isolation_retention_registry", "aggregate_preflight",
    "usage_registry", "usage_reservations_registry", "cache_manager_registry", "provider_fallback_registry", "routing_registry", "knowledge_ingest_registry", "knowledge_retrieval_registry", "knowledge_invalidation_registry", "knowledge_journey_registry", "profile_publish_registry", "profile_admin_registry", "profile_merge_registry", "skill_selection_registry", "context_compiler_registry", "history_memory_registry", "context_guards_registry", "cli_missing_suite", "cli_wrong_mode", "cli_missing_pg_isolation", "cli_future_commands",
    "exact_cases", "zero_cases", "skips", "failure", "duplicate_cases", "extra_cases",
    "missing_cases", "mode_mismatch", "tier_mismatch", "unknown_status", "malformed_report",
    "pg_proof_missing", "pg_case_set", "runner_nonzero", "missing_dependency",
    "missing_runner", "receipt_hashes", "timeout", "live_refused",
))


@dataclass(frozen=True)
class Suite:
    owner: str
    mode: str
    tier: str
    cases: tuple[str, ...]
    command: tuple[str, ...]
    dependencies: tuple[str, ...]
    requires_pg: bool = False


# Keys are (name, partition). Future owners add their actual suites here, never
# substitute gate-self or contract tests for a durable partition.
SUITES = {
    ("gate-self", "test"): Suite("P2.1", "tooling-self", "unit", SELF_CASES,
        (sys.executable, "-B", "tools/tests/test_platform_gate.py", "--report"),
        ("tools/platform_gate.py", "tools/tests/test_platform_gate.py",
         f"{TESTING}/fixtures/architecture.json", f"{TESTING}/bin/php-tokens.php")),
    ("core-smoke", "test"): Suite("P2.1", "unit", "unit", ("core.exception",),
        ("php", f"{CORE}/tests/smoke.php"),
        (f"{CORE}/composer.json", f"{CORE}/composer.lock", f"{CORE}/vendor/autoload.php",
         f"{CORE}/tests/smoke.php", f"{CORE}/src/Common/IntegrityConflict.php")),
    ("testing-smoke", "test"): Suite("P2.1", "unit", "unit",
        ("testing.result", "testing.invalid-result"), ("php", f"{TESTING}/tests/smoke.php"),
        (f"{TESTING}/composer.json", f"{TESTING}/composer.lock", f"{TESTING}/vendor/autoload.php",
         f"{TESTING}/tests/smoke.php", f"{TESTING}/src/CaseResult.php")),
    ("no-dev-smoke", "test"): Suite("P2.1", "production-no-dev", "unit", ("packaging.no-dev",),
        ("php", f"{TESTING}/tests/no-dev/check.php"),
        (f"{TESTING}/tests/no-dev/composer.json", f"{TESTING}/tests/no-dev/composer.lock",
         f"{TESTING}/tests/no-dev/vendor/autoload.php", f"{TESTING}/tests/no-dev/check.php")),
}
CONTRACT_CASES = (
    'identity.dated-opaque-preserved',
    'context.actor-refresh',
    'request.direct-actions',
    'request.stable-hash',
    'request.tenant-denied',
    'request.registry-not-class-loader',
    'proposal.saved-confirmation-mapping',
    'delivery.saved-response-binding',
    'invocation.fallback-new-identity',
    'run.graph-contract',
    'error.taxonomy-contract',
    'identity.replay',
    'identity.hash-conflict',
    'identity.tenant-instance-scope',
    'identity.cross-tenant-rejected',
    'identity.invocation-attempt',
    'identity.proposal-saved-key',
    'identity.effect-owner-conflict',
    'identity.delivery-version-destination',
    'identity.usage-version',
    'identity.occurrence-utc',
    'identity.invalid-id-hash',
    'context.trusted-factory',
    'context.browser-flags-rejected',
    'context.tenant-mismatch',
    'context.revoke-overrides-pin',
    'context.policy-refresh',
    'context.scope-binding',
    'context.worker-tenant-switch',
    'context.immutable',
    'run.closed-fsm',
    'run.terminal-late-callback',
    'run.stale-version-epoch',
    'run.unknown-not-terminal',
    'run.cancel-unresolved',
    'run.resolution-tenant',
    'action.alc-projection',
    'action.mapping-not-dispatch-authority',
    'jcs.existing-encoder-vectors',
    'jcs.duplicate-and-object-array',
    'jcs.order-invariance',
    'jcs.scope-domain-separation',
    'jcs.unsafe-number-rejected',
    'error.closed-vocabulary',
    'error.unknown-retry-forbidden',
    'error.retry-taxonomy',
    'error.public-redaction',
    'error.dated-contextual-mapping',
    'receipt.unknown-distinct',
    'receipt.known-observation',
    'receipt.cross-tenant',
    'usage.null-versus-zero',
    'usage.same-version-conflict',
    'profile.immutable-pin',
    'receipt.no-new-wire-status',
    'schema-control.required-case-set',
    'schema-control.missing-document',
    'schema-control.actual-schema',
    'schema-control.rejection-reason',
    'schema-control.declared-partitions',
)
SPEC_CASES = (
    'schema.35-direct-action-request',
    'schema.36-proposal-binding',
    'schema.37-delivery-intent',
    'schema.38-invocation-fallback',
    'schema.39-definition-fqn-rejected',
    'schema.40-action-tenant-mismatch',
    'schema.01-trusted-input',
    'schema.02-stale-context',
    'schema.03-untrusted-browser-context',
    'schema.04-accepted-turn',
    'schema.05-action-receipt',
    'schema.06-unknown-invocation',
    'schema.07-partial-usage',
    'schema.08-context-snapshot',
    'schema.09-unknown-effect-reconcile',
    'schema.10-confirmation-input-substitution',
    'schema.11-lease-expiry-blind-retry',
    'schema.12-stale-worker-write',
    'schema.13-owner-revoke-before-write',
    'schema.14-trusted-envelope',
    'schema.15-normalized-response',
    'schema.16-bundle-pin',
    'schema.17-utc-instant',
    'schema.18-checkpoint',
    'schema.19-effect-request',
    'schema.20-public-error',
    'schema.21-observed-zero-usage',
    'schema.22-known-effect',
    'schema.23-browser-input',
    'schema.24-tenant-mismatch',
    'schema.25-unstable-payload-field',
    'schema.26-unknown-as-known',
    'schema.27-public-secret-field',
    'schema.28-unknown-terminal',
    'schema.29-non-utc-time',
    'schema.30-invalid-calendar',
    'schema.31-missing-is-not-zero',
    'schema.32-no-new-action-status',
    'schema.33-version-conflict',
    'schema.34-unknown-cancellation',
)

PLATFORM_INPUTS = tuple(str(p.relative_to(ROOT)) for base in
    (ROOT / CORE / "src", ROOT / CORE / "tests/Common", ROOT / "spec/platform/v1",
     ROOT / "fixtures/platform/v1/contracts") for p in sorted(base.rglob("*"))
    if p.is_file() and p.suffix in {".php", ".json"}) + (
        f"{CORE}/composer.json", f"{CORE}/composer.lock", f"{CORE}/vendor/autoload.php",
        "php/composer.json", "php/composer.lock", "php/vendor/autoload.php",
        "php/src/Schema/Canonicalizer.php", "php/src/Schema/PublishedSchemaValidator.php",
        "php/src/Action/ActionStatus.php", "php/src/Error/ToolErrorCode.php",
        "spec/revisions/2026-09/schema/lifecycle-event.json", "spec/revisions/2026-09/schema/effect-registry.json",
        "spec/platform/v1/schema/contracts.json", "fixtures/platform/v1/contracts/manifest.json",
        "spec/platform/v1/fixture-cases.json")
SUITES.update({
    ("contracts", "test"): Suite("P2.2", "contract-scripted", "contract-scripted", CONTRACT_CASES,
        ("php", f"{CORE}/tests/Common/run.php"), PLATFORM_INPUTS),
    ("platform-spec", "check-spec"): Suite("P2.2", "schema-profile", "design-schema", SPEC_CASES,
        ("php", f"{CORE}/tests/Common/spec.php"), PLATFORM_INPUTS),
    ("contracts-no-dev", "test"): Suite("P2.2", "production-no-dev", "unit", ("packaging.new-core-no-sdk",),
        ("php", f"{CORE}/tests/Common/no_dev.php"),
        (f"{CORE}/tests/Common/no_dev.php", f"{TESTING}/tests/no-dev/composer.lock",
         f"{TESTING}/tests/no-dev/vendor/autoload.php")),
})

FSM_CASES = (
    'run.all-transition-pairs', 'RUN-007', 'V2ALL-STATES-POSITIVE',
    'V2ALL-STATES-NEGATIVE', 'V2ALL-STATES-FAULT', 'run.command-bindings',
    'V2ALL-CATALOG-POSITIVE', 'V2ALL-CATALOG-NEGATIVE', 'V2ALL-CATALOG-FAULT',
    'registry.hash-content-and-policy', 'facade.accepted-replay-conflict',
    'facade.guards-before-submit', 'facade.retry-current-restrictions',
    'facade.tenant-and-result-binding', 'action.dated-mapping-and-unknown',
    'facade.cancel-reconcile-no-effect-loop',
)
FSM_INPUTS = PLATFORM_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'tests/Runtime', ROOT / CORE / 'tests/Actions',
    ROOT / 'fixtures/platform/v1/fsm') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.json'}) + (
        f'{CORE}/tests/Runtime/run.php', 'fixtures/platform/v1/fsm/run-v1.json',
        'php/src/Action/ActionJournal.php', 'php/src/Action/ActionReducer.php',
        'php/src/Action/ActionEvent.php', 'php/src/Action/Actor.php',
        'spec/platform/v1/runtime/contract.json',
    )
SUITES[("fsm-registry", "test")] = Suite("P2.3", "unit", "unit", FSM_CASES,
    ("php", f"{CORE}/tests/Runtime/run.php"), FSM_INPUTS)

TESTKIT_PARITY_CASES = (
    'testkit.clock-random', 'testkit.transport-boundaries', 'testkit.owner-lookup-unknown',
    'testkit.disclosure-revocation-isolation', 'testkit.real-facade-guards',
    'testkit.real-confirmation-guards', 'testkit.commit-crash-replay',
    'testkit.scheduler-repeatable-fail-closed', 'testkit.real-dedup-unknown-effect',
    'portable.action.complete', 'portable.action.wait-cancel', 'portable.action.unknown-kind',
    'portable.action.unknown-effect', 'portable.action.terminal-late', 'portable.action.stale-epoch',
    'portable.action.invalid-cancel', 'portable.action.version-cas', 'portable.usage.partial',
    'portable.usage.zero', 'portable.usage.missing-not-zero', 'portable.action.receipt',
    'portable.action.closed-wire', 'portable.error.unknown-not-known',
    'CompatibilityTests.test_case_set', 'CompatibilityTests.test_missing_consumer',
    'CompatibilityTests.test_tiers', 'CompatibilityTests.test_observed',
    'CompatibilityTests.test_drift', 'CompatibilityTests.test_applicability',
    'compatibility.current-semantic-parity', 'compatibility.frozen-predecessor',
)
TESTKIT_INPUTS = FSM_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / TESTING / 'src', ROOT / TESTING / 'tests', ROOT / 'conformance/platform',
    ROOT / 'fixtures/platform/v1/compatibility', ROOT / 'fixtures/platform/v1/testkit',
    ROOT / 'php/src/Action', ROOT / 'php/src/Confirmation', ROOT / 'php/src/Idempotency',
    ROOT / 'dart/agentools/lib/src/journal', ROOT / 'dart/agentools/lib/src/schema')
    for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.parts
    and p.suffix in {'.php', '.dart', '.json', '.py'}) + (
        f'{TESTING}/composer.json', f'{TESTING}/composer.lock', f'{TESTING}/vendor/autoload.php',
        f'{TESTING}/tests/run.php', 'conformance/platform/harness.py',
        'conformance/platform/worker.php', 'conformance/platform/worker.dart',
        'conformance/platform/test_harness.py', 'conformance/platform/profile.php',
        'conformance/platform/compare.php', 'conformance/vendor/autoload.php',
        'conformance/composer.lock', 'conformance/src/Conformance/SemanticParity.php',
        'php/conformance/semantic_profile.php', 'dart/agentools/conformance/semantic_profile.dart',
        'dart/agentools/pubspec.lock', 'dart/agentools/.dart_tool/package_config.json',
        'spec/revisions/2026-09/semantic-manifest.json',
        'fixtures/platform/v1/compatibility/vectors.json',
        'fixtures/platform/v1/compatibility/applicability.json',
    )
SUITES[("testkit-parity", "test")] = Suite("P2.4", "contract-scripted", "contract-scripted",
    TESTKIT_PARITY_CASES, (sys.executable, '-B', 'conformance/platform/harness.py'), TESTKIT_INPUTS)

POSTGRES = 'packages/agentools-postgres'
PG_SCHEMA_CASES = tuple('pg-schema.' + name for name in (
    'testCleanInstallAndReapply', 'testCatalogKeysIndexesAndNullability',
    'testUnsafeLifecycleRefusedBeforeMutation', 'testCrossTenantForeignKeysRejected',
    'testStableIdentitiesAndHashConflicts', 'testActiveRunAndUnknownRemainUnresolved',
    'testVersionCasAndFractionalDbClock', 'testBoundedCheckpointAndNullableUsage',
    'testRetentionProtectsUnresolvedAndKeepsTombstone', 'testMigrationChecksumAndHistoryGuard',
    'testDeliveryResolutionRequiresBoundLatestReceipt',
    'testFailedUpgradeRollsBackDdlAndHistory', 'testAdditiveUpgradeOldReaderAndTransactionBoundary',
))
PG_SCHEMA_INPUTS = tuple(f'{POSTGRES}/{path}' for path in (
    'composer.json', 'composer.lock', 'phpunit.xml', 'phpstan.neon', 'vendor/autoload.php',
    'vendor/bin/phpunit', 'src/Migrator.php', 'src/MigrationException.php',
    'migrations/0001_intake.sql', 'migrations/0002_durable_refs.sql', 'migrations/0003_action_journal.sql', 'migrations/0004_payload_lifecycle.sql',
    'migrations/0005_delivery_resolution.sql', 'migrations/0006_delivery_claims.sql', 'migrations/0007_action_submissions.sql',
    'migrations/0008_payload_holds.sql', 'migrations/0009_confirmation.sql', 'migrations/0010_effect_recovery.sql',
    'migrations/0011_operator_operations.sql', 'migrations/0012_derived_payloads.sql',
    'tests/IsolatedDatabase.php', 'tests/SchemaTest.php', 'tests/run.py',
)) + ('tools/storage_chaos.py', 'tools/platform_gate.py')
SUITES[('pg-schema', 'pg-test')] = Suite('P3.1', 'isolated-postgres', 'isolated-postgres',
    PG_SCHEMA_CASES, (sys.executable, '-B', f'{POSTGRES}/tests/run.py'), PG_SCHEMA_INPUTS, requires_pg=True)

PG_INTAKE_CASES = (
    'RUN-001', 'RUN-002', 'V2-P3-2-INTAKE-FAULT', 'V2ALL-IDENTITY-POSITIVE',
    'V2ALL-IDENTITY-NEGATIVE', 'V2ALL-IDENTITY-FAULT',
    'pg-intake.testSessionAndInstanceScope', 'pg-intake.testTenantWorkerResetAndAccess',
    'pg-intake.testStaleContextBeforeAdvance', 'pg-intake.testAtomicRollbackAtEveryBoundary',
    'pg-intake.testActiveRunAndCallerTransactionBoundary',
)
PG_INTAKE_INPUTS = PG_SCHEMA_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Runtime', ROOT / POSTGRES / 'src/Inbox',
    ROOT / POSTGRES / 'tests/Runtime') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.xml'}) + (
        f'{CORE}/composer.json', f'{CORE}/composer.lock',
        f'{POSTGRES}/vendor/agentools/platform-core/src/Common/IdentityKeys.php',
        f'{POSTGRES}/vendor/agentools/sdk/src/Schema/Canonicalizer.php',
        'php/composer.json', 'php/composer.lock',
    ) + tuple(str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Common', ROOT / CORE / 'src/Security', ROOT / CORE / 'src/Context')
        for p in sorted(base.rglob('*.php')))
SUITES[('pg-intake', 'pg-test')] = Suite('P3.2', 'isolated-postgres', 'isolated-postgres',
    PG_INTAKE_CASES, (sys.executable, '-B', f'{POSTGRES}/tests/Runtime/run.py'), PG_INTAKE_INPUTS, requires_pg=True)

PG_CLAIMS_OUTBOX_CASES = (
    'RUN-003', 'RUN-004', 'V2-P3-3-WAKE-POS', 'V2-P3-3-WAKE-NEG', 'V2-P3-3-CLAIM-POS',
    'V2ALL-STORAGE-POSITIVE', 'V2ALL-STORAGE-NEGATIVE', 'V2ALL-STORAGE-FAULT',
    'pg-claims-outbox.testBoundedRecoveryAndDeadletter',
    'pg-claims-outbox.testRetrySameKeyAndCurrentGuards',
    'pg-claims-outbox.testSkipLockedAndAuthoritativeEvidence',
)
PG_CLAIMS_OUTBOX_INPUTS = PG_INTAKE_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Claims', ROOT / POSTGRES / 'src/Outbox',
    ROOT / POSTGRES / 'tests/Concurrency') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.xml', '.md'})
SUITES[('pg-claims-outbox', 'pg-test')] = Suite('P3.3', 'isolated-postgres', 'isolated-postgres',
    PG_CLAIMS_OUTBOX_CASES, (sys.executable, '-B', f'{POSTGRES}/tests/Concurrency/run.py'),
    PG_CLAIMS_OUTBOX_INPUTS, requires_pg=True)

PG_ACTION_JOURNAL_CASES = (
    'ACT-001', 'ACT-005', 'V2-P3-4-INTENT-POS', 'V2ALL-JOURNAL-POSITIVE',
    'V2ALL-JOURNAL-NEGATIVE', 'V2ALL-JOURNAL-FAULT',
    'pg-action-journal.testConcurrentAppendAndScope',
    'pg-action-journal.testParticipantAndSchemaQualification',
    'pg-action-journal.testCorruptionAndUnknownRemainUnresolved',
)
PG_ACTION_JOURNAL_INPUTS = PG_CLAIMS_OUTBOX_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Actions', ROOT / POSTGRES / 'tests/Actions',
    ROOT / 'fixtures/platform/v1/journal') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.xml', '.md', '.json'}) + (
        'php/src/Persistence/PdoPostgresEventStore.php',
        f'{POSTGRES}/vendor/agentools/sdk/src/Persistence/PdoPostgresEventStore.php',
        'fixtures/hardening/sql/event-store.v1.sql',
    ) + tuple(str(p.relative_to(ROOT)) for base in (
        ROOT / 'php/src/Action', ROOT / 'php/src/Persistence', ROOT / 'php/src/Effects') for p in sorted(base.rglob('*.php')))
SUITES[('pg-action-journal', 'pg-test')] = Suite('P3.4', 'isolated-postgres', 'isolated-postgres',
    PG_ACTION_JOURNAL_CASES, (sys.executable, '-B', f'{POSTGRES}/tests/Actions/run.py'),
    tuple(dict.fromkeys(PG_ACTION_JOURNAL_INPUTS)), requires_pg=True)

PG_PAYLOAD_CASES = (
    'TEN-001', 'V2-P3-5-TENANT-POS', 'V2-P3-5-TENANT-FAULT',
    'V2ALL-TENANT-POSITIVE', 'V2ALL-TENANT-NEGATIVE', 'V2ALL-TENANT-FAULT',
    'pg-isolation-retention.testRetentionProtectsUnresolvedAndPurgesCopies',
    'pg-isolation-retention.testResolverFailuresAuditAndRevoke',
    'pg-isolation-retention.testReadPurgeDisclosureFence',
    'pg-isolation-retention.testLegacyPurgeReservationRequiresReceiptResolution',
    'pg-isolation-retention.testBoundsDoNotReplaceCurrentSourceAuthority',
    'pg-isolation-retention.testHoldAuthorityCannotCrossSessionsOrEraseItsHistory',
    'pg-isolation-retention.testHoldsSurviveRestartAndPurgeAllRegisteredCopies',
    'pg-isolation-retention.testOrphanScanAndCrashAfterPhysicalPurgeReconcileWithoutResurrection',
    'pg-isolation-retention.testExpiryScanIncludesUnknownDeliveryButCannotAuthorizeItsErasure',
    'pg-isolation-retention.testParallelPlanDirectActionInputRemainsUnresolvedAndCannotLinkAfterPurge',
    'pg-isolation-retention.testAllSevenStoresEraseBytesBeforeDurableCompletionAndCannotReopen',
    'pg-isolation-retention.testEmptyCoverageForeignScopeAndChangedOperationNeverProduceCompletion',
    'pg-isolation-retention.testHoldAndLostPhysicalDeletionAckReconcileAfterRestart',
    'pg-isolation-retention.testBoundedCleanupCompletesOnlyAfterAllPayloads',
    'pg-isolation-retention.testActualProcessCrashBeforeRenameRetainsHandleProvenanceAndPurgeCleansStaging',
)
PG_ISOLATION_RETENTION_CASES = PG_SCHEMA_CASES + PG_INTAKE_CASES + PG_CLAIMS_OUTBOX_CASES + PG_ACTION_JOURNAL_CASES + PG_PAYLOAD_CASES
PG_ISOLATION_RETENTION_INPUTS = PG_ACTION_JOURNAL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Payloads', ROOT / POSTGRES / 'tests/Isolation') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.xml', '.md'}) + (
        f'{POSTGRES}/tests/no_dev.py', 'docs/platform/storage.md',
    )
SUITES[('pg-isolation-retention', 'pg-test')] = Suite('P3.5', 'isolated-postgres', 'isolated-postgres',
    PG_ISOLATION_RETENTION_CASES, (sys.executable, '-B', f'{POSTGRES}/tests/Isolation/run.py'),
    tuple(dict.fromkeys(PG_ISOLATION_RETENTION_INPUTS)), requires_pg=True)

MODEL_TRANSPORT_CASES = ('CM-028', 'CM-029', 'CM-030', 'models.typed-request', 'models.typed-values-reject-invalid', 'models.tenant-bound-request', 'models.nullable-usage', 'models.response-metadata-refusal', 'models.normalized-errors', 'models.proposal-only', 'models.evidence-unknown-expired', 'models.cancel-deadline-before-io', 'models.provider-option-binding', 'models.stream-complete-identity', 'models.stream-missing-terminal-partial', 'models.stream-duplicate-gap-terminal', 'models.stream-proposal-conflict', 'models.legacy-exceptions-preserved', 'models.legacy-unsupported-before-respond', 'models.legacy-scenario-boundary')
MODEL_TRANSPORT_INPUTS = tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Models', ROOT / CORE / 'tests/Models',
    ROOT / 'php/src/Platform/Models', ROOT / 'spec/platform/v1/models',
    ROOT / 'fixtures/platform/v1/models') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.json', '.md'}) + (
        f'{CORE}/vendor/autoload.php', 'php/vendor/autoload.php',
        'php/src/Contracts/LlmPort.php', 'php/src/Scenario/ScenarioRunner.php',
        'php/reference/Adapters/ScriptedLlmPort.php',
        'php/reference/fixtures/llm/simple_tool_call.json',
        'dart/agentools/lib/platform_models.dart', 'dart/agentools/lib/src/platform/models.dart',
        'dart/agentools/test/platform/models_test.dart',
    )
SUITES[('model-transport', 'test')] = Suite('P4.1', 'contract-scripted', 'contract-scripted',
    MODEL_TRANSPORT_CASES, ('php', f'{CORE}/tests/Models/run.php'), MODEL_TRANSPORT_INPUTS)
# Acceptance names the same complete contract partition model-contract.
SUITES[('model-contract', 'test')] = SUITES[('model-transport', 'test')]


MODEL_ADAPTER = "packages/agentools-model-adapter"
SDK_ADAPTER_CASES = ('SDK-001', 'V2ALL-SDK-POSITIVE', 'V2ALL-SDK-NEGATIVE', 'V2ALL-SDK-FAULT', 'adapter.request-wire', 'adapter.optional-before-io', 'adapter.removed-generation-options', 'adapter.current-credential-bindings', 'adapter.revoke-before-send', 'adapter.cancel-deadline-fraction', 'adapter.schema-before-io', 'adapter.orphan-tool-result', 'adapter.usage-null-zero', 'adapter.proposal-hash-conflict', 'adapter.proposal-validation', 'adapter.structured-full-validation', 'adapter.refusal-truncation', 'adapter.http-errors-redacted', 'adapter.timeout-no-retry', 'adapter.unknown-protocol-result', 'adapter.stream-chunks-and-tools', 'adapter.stream-cancel-deadline-revoke', 'adapter.stream-timeout', 'adapter.stream-cumulative-not-summed', 'adapter.stream-block-and-id-conflicts', 'adapter.stream-extension-and-empty-tool', 'adapter.callback-retry-unknown-and-conflict', 'adapter.curl-streaming', 'adapter.curl-cancel-deadline-guard', 'adapter.curl-tls-and-redirect', 'adapter.production-no-dev')
SDK_ADAPTER_INPUTS = tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / MODEL_ADAPTER / 'src', ROOT / MODEL_ADAPTER / 'tests', ROOT / MODEL_ADAPTER / 'resources',
    ROOT / CORE / 'src/Models', ROOT / CORE / 'src/Common', ROOT / CORE / 'tests/Common',
    ROOT / TESTING / 'src/Models') for p in sorted(base.rglob('*'))
    if p.is_file() and (p.suffix in {'.php', '.py', '.json'} or p.name == 'LICENSE')) + (
        f'{MODEL_ADAPTER}/composer.json', f'{MODEL_ADAPTER}/composer.lock',
        f'{MODEL_ADAPTER}/phpstan.neon', f'{MODEL_ADAPTER}/vendor/autoload.php',
        f'{CORE}/composer.json', f'{TESTING}/composer.json',
        'docs/platform/sdk-capabilities.json', 'docs/platform/loop-ownership.json',
        'docs/platform/package-map.json', 'docs/platform/sdk-adapter.md',
    )
SUITES[('sdk-adapter', 'test')] = Suite('P4.2', 'contract-scripted', 'contract-scripted',
    SDK_ADAPTER_CASES, (sys.executable, '-B', f'{MODEL_ADAPTER}/tests/contract.py'), SDK_ADAPTER_INPUTS)
# Names of the owning adapter and its contract refer to the same exact partition.
SUITES[('model-adapter', 'test')] = SUITES[('sdk-adapter', 'test')]
SUITES[('sdk-contract', 'test')] = SUITES[('sdk-adapter', 'test')]


# Readiness requirements, not implemented suites. All are mandatory when selected.
REQUIRED = {
    "check-spec": (("platform-spec", "check-spec"),),
    "qualify": (("read", "e2e"), ("platform-durability", "pg-test")),
    "acceptance": (("platform-spec", "check-spec"), ("read", "e2e"),
                   ("platform-durability", "pg-test")),
    "doctor": (("platform-spec", "check-spec"), ("read", "e2e"),
               ("platform-durability", "pg-test")),
}


RUNTIME_CASES = (
    'runtime.bounds-and-serial-only', 'runtime.plan-and-explicit-replacement',
    'runtime.normalization-and-mapping-integrity', 'runtime.unknown-keeps-null-and-no-dispatch',
    'runtime.unknown-admission-is-not-admitted',
    'runtime.pre-provider-refusal-is-known-and-durable', 'runtime.pre-provider-exact-denial-types',
    'runtime.revocation-during-outbound-is-proven-not-sent',
    'runtime.provider-uncertainty-including-typed-errors-stays-unknown',
    'runtime.outbound-crash-does-not-become-refusal-or-resend',
    'runtime.refusal-persistence-failure-after-outbound-remains-uncertain',
    'runtime.refusal-persistence-before-outbound-does-not-invent-unknown',
    'runtime.refusal-does-not-disable-successful-exchanges',
    'runtime.refusal-replay-rejects-inconsistent-metadata',
)
RUNTIME_INPUTS = MODEL_TRANSPORT_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Runtime', ROOT / CORE / 'src/Budget', ROOT / CORE / 'tests/Runtime')
    for p in sorted(base.rglob('*.php')))
SUITES[('runtime', 'test')] = Suite('P4.3', 'unit', 'unit', RUNTIME_CASES,
    ('php', f'{CORE}/tests/Runtime/advance.php'), RUNTIME_INPUTS)
PG_MODEL_CASES = (
    'RUN-005', 'RUN-006', 'V2-P4-3-PROPOSAL-POS', 'V2-P4-3-PROPOSAL-NEG',
    'V2-P4-3-PROVIDER-POS', 'V2-P4-3-PROVIDER-NEG', 'V2ALL-RUN-POSITIVE',
    'V2ALL-RUN-NEGATIVE', 'V2ALL-RUN-FAULT', 'pg-models.testSecondHostReadExample',
    'pg-models.testFencingAndCompetingConnections', 'pg-models.testInvocationAndCostAndErrorBounds',
    'pg-models.testResultUsageRollback', 'pg-models.testResponseDeliveryRollback',
    'pg-models.testIdentityAndCurrentPermissionGuards', 'pg-models.testReadContinuationDoesNotReplayAction',
    'pg-models.testObservedZeroAndMissingCache', 'pg-models.testFractionalDeadlineBoundary',
    'pg-models.testPreProviderRefusalSurvivesRestartWithoutIntent',
    'pg-models.testPreProviderRefusalAfterIntentIsKnownFailure',
    'pg-models.testUnknownCannotBecomePreProviderRefusal',
    'pg-models.testRefusalCommitFailureAfterIntentRecoversUnknown',
)
PG_MODEL_INPUTS = PG_CLAIMS_OUTBOX_INPUTS + RUNTIME_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Models', ROOT / POSTGRES / 'tests/Models') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.xml', '.md'})
SUITES[('pg-runtime', 'pg-test')] = Suite('P4.3', 'isolated-postgres', 'isolated-postgres', PG_MODEL_CASES,
    (sys.executable, '-B', f'{POSTGRES}/tests/Models/run.py'), tuple(dict.fromkeys(PG_MODEL_INPUTS)), requires_pg=True)
SUITES[('pg-models', 'pg-test')] = SUITES[('pg-runtime', 'pg-test')]
# Acceptance uses one suite name for its core and isolated PostgreSQL partitions.
SUITES[('durable-invocation', 'test')] = SUITES[('runtime', 'test')]
SUITES[('durable-invocation', 'pg-test')] = SUITES[('pg-runtime', 'pg-test')]


CONTEXT_READ_CASES = (
    'context-read.pinned-manifest', 'context-read.mandatory-overflow', 'context-read.optional-pruning',
    'context-read.current-access-and-expiry', 'context-read.cross-tenant-and-purpose',
    'context-read.invalid-count-and-output-reserve', 'context-read.trust-and-classification-retained',
)
READ_JOURNEY_CASES = (
    'READ-001', 'READ-ALLOWLIST-NEG', 'READ-DISCLOSURE-NEG', 'READ-RECOVERY-FAULT',
    'READ-DELIVERY-FAULT', 'READ-DELIVERY-NEG', 'READ-IDENTITY-NEG', 'READ-GUARDS-NEG',
    'READ-SECOND-HOST', 'READ-EXAMPLE', 'READ-PRIVACY', 'READ-CONTEXT',
    'READ-F1-NOT-SENT', 'READ-F2-PRE-SEND',
)
READ_JOURNEY_INPUTS = tuple(dict.fromkeys(PG_MODEL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'examples/platform/read-journey', ROOT / 'conformance/platform/read-journey',
    ROOT / CORE / 'src/Context', ROOT / CORE / 'tests/Context', ROOT / TESTING / 'tests/Journeys')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml', '.sql', '.md'}) + (
        f'{TESTING}/composer.json', f'{TESTING}/composer.lock', f'{TESTING}/phpstan.neon',
        f'{MODEL_ADAPTER}/.gitignore', 'tools/tests/test_platform_gate.py',
    )))
SUITES[('context-read', 'test')] = Suite('P4.4', 'unit', 'unit', CONTEXT_READ_CASES,
    ('php', f'{CORE}/tests/Context/compiler.php'), READ_JOURNEY_INPUTS)
SUITES[('read-journey', 'e2e')] = Suite('P4.4', 'local-host-e2e', 'local-host-e2e', READ_JOURNEY_CASES,
    (sys.executable, '-B', 'conformance/platform/read-journey/run.py'), READ_JOURNEY_INPUTS, requires_pg=True)


ACTION_SUBMIT_CASES = (
    'action.outcomes', 'facade.accepted-replay-conflict', 'facade.guards-before-submit',
    'facade.retry-current-restrictions', 'facade.tenant-and-result-binding',
    'action.dated-mapping-and-unknown', 'facade.cancel-reconcile-no-effect-loop',
    'V2ALL-CATALOG-POSITIVE', 'V2ALL-CATALOG-NEGATIVE', 'V2ALL-CATALOG-FAULT',
    'registry.hash-content-and-policy', 'dart.action.accepted-pending', 'dart.action.known-success',
    'dart.action.known-failure', 'dart.action.unknown-is-not-failed',
)
PG_ACTION_SUBMIT_CASES = (
    'ACT-SUBMIT-REPLAY', 'ACT-STATUS-DISCLOSURE', 'ACT-SUBMIT-GUARDS',
    'ACT-SUBMIT-ATOMIC', 'ACT-SUBMIT-PROPOSAL', 'ACT-SUBMIT-RACE',
    'ACT-SUBMIT-CRASH', 'ACT-STATUS-UNKNOWN', 'ACT-DIRECT-NO-MODEL',
)
ACTION_SUBMIT_INPUTS = tuple(dict.fromkeys(PG_ACTION_JOURNAL_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Actions', ROOT / CORE / 'tests/Actions',
        ROOT / 'examples/platform/direct-action', ROOT / 'fixtures/platform/v1/actions')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.dart', '.json', '.md'})
    + tuple(str(p.relative_to(ROOT)) for p in sorted((ROOT / POSTGRES / 'migrations').glob('*.sql'))) + (
        # Keep the owning migration mandatory even if it disappears from discovery.
        f'{POSTGRES}/migrations/0007_action_submissions.sql',
        'dart/agentools/.dart_tool/package_config.json', 'dart/agentools/pubspec.lock',
        'dart/agentools/test/platform/action_submit_test.dart',
        'dart/agentools/lib/src/journal/action_reducer.dart', 'tools/tests/test_platform_gate.py',
    )))
SUITES[('action-submit', 'test')] = Suite('P5.1', 'unit', 'unit', ACTION_SUBMIT_CASES,
    (sys.executable, '-B', 'examples/platform/direct-action/tests/contract.py'), ACTION_SUBMIT_INPUTS)
SUITES[('action-submit', 'pg-test')] = Suite('P5.1', 'isolated-postgres', 'isolated-postgres', PG_ACTION_SUBMIT_CASES,
    (sys.executable, '-B', f'{POSTGRES}/tests/Actions/submit.py'), ACTION_SUBMIT_INPUTS, requires_pg=True)

CONFIRMATION_PORTABLE_NAMES = (
    'recipient', 'document', 'amount', 'caller-flags', 'scope', 'definition', 'policy',
    'actor', 'tenant', 'expired', 'replay', 'missing-proof', 'forged-voice',
)
CONFIRMATION_CASES = ('confirmation.binding-tuple', 'confirmation.no-caller-gate') + tuple(
    {'recipient': 'CNF-001', 'caller-flags': 'CNF-003'}.get(name, 'php.confirmation.' + name)
    for name in CONFIRMATION_PORTABLE_NAMES) + tuple('dart.confirmation.' + name for name in CONFIRMATION_PORTABLE_NAMES)
PG_CONFIRMATION_CASES = (
    'V2-P5-2-CONFIRM-POS', 'CNF-002', 'CNF-004', 'V2ALL-CONFIRM-NEGATIVE',
    'V2ALL-CONFIRM-POSITIVE', 'V2ALL-CONFIRM-FAULT', 'confirmation.atomic-journal-proof',
    'confirmation.cancel-compensation', 'confirmation.runnable-example',
    'confirmation.owner-commit-journal-failure', 'confirmation.owner-receipt-outage',
    'confirmation.owner-reconciliation-guards',
)
CONFIRMATION_INPUTS = tuple(dict.fromkeys(ACTION_SUBMIT_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / POSTGRES / 'src/Confirmation', ROOT / CORE / 'tests/Actions/Confirmation',
        ROOT / 'examples/platform/confirmed-write', ROOT / 'fixtures/platform/v1/confirmation',
        ROOT / 'php/src/Confirmation', ROOT / 'php/src/Contracts', ROOT / 'dart/agentools/lib/src/confirmation')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.dart', '.json', '.py', '.xml', '.md'}) + (
        f'{POSTGRES}/migrations/0009_confirmation.sql',
        'fixtures/platform/v1/confirmation/binding-map.json', 'fixtures/platform/v1/confirmation/bindings.json',
        'php/tests/Confirmation/PlatformBindingFixture.php', 'php/tests/Confirmation/PlatformBindingTest.php',
        'php/tests/Confirmation/platform-binding.php', 'dart/agentools/test/confirmation/platform_binding_test.dart',
    )))
SUITES[('confirmation-write', 'test')] = Suite('P5.2', 'contract-scripted', 'contract-scripted', CONFIRMATION_CASES,
    (sys.executable, '-B', f'{CORE}/tests/Actions/Confirmation/contract.py'), CONFIRMATION_INPUTS)
SUITES[('confirmation-write', 'pg-test')] = Suite('P5.2', 'isolated-postgres', 'isolated-postgres', PG_CONFIRMATION_CASES,
    (sys.executable, '-B', f'{CORE}/tests/Actions/Confirmation/pg.py'), CONFIRMATION_INPUTS, requires_pg=True)

EFFECT_CASES = ('effect.strategy-matrix', 'effect.typed-binding', 'effect.native-key-contract') + tuple(
    language + '.effect.' + name for language in ('php', 'dart')
    for name in ('pending', 'unknown', 'success', 'failure', 'takeover'))
PG_EFFECT_CASES = (
    'ACT-002', 'ACT-003', 'ACT-004', 'V2-P5-3-MANUAL-POS', 'V2-P5-3-LOOKUP-POS',
    'V2ALL-EFFECT-POSITIVE', 'V2ALL-EFFECT-NEGATIVE', 'V2ALL-EFFECT-FAULT',
    'effect.retry-bounds-cancellation', 'effect.projection-current-authority',
    'effect.native-unknown-retry', 'effect.read-absent-recovery',
    'effect.definition-current-policy',
    'effect.sigkill-after-owner-commit', 'effect.competing-recovery',
)
EFFECT_INPUTS = tuple(dict.fromkeys(CONFIRMATION_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Actions/Effects', ROOT / CORE / 'tests/Actions/Effects',
        ROOT / POSTGRES / 'src/Effects', ROOT / POSTGRES / 'tests/Effects',
        ROOT / TESTING / 'src/Owners', ROOT / 'fixtures/platform/v1/effects')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.json', '.py', '.xml', '.md'}) + (
        f'{POSTGRES}/migrations/0010_effect_recovery.sql',
        f'{POSTGRES}/tests/Effects/CrashTest.php', f'{POSTGRES}/tests/Effects/EffectsTest.php',
        f'{CORE}/tests/Actions/Effects/contract.php', f'{CORE}/tests/Actions/Effects/contract.py',
        'fixtures/platform/v1/effects/outcomes.json', 'fixtures/platform/v1/effects/strategies.json',
        'dart/agentools/test/platform/effect_recovery_test.dart',
    )))
SUITES[('effect-reconcile', 'test')] = Suite('P5.3', 'contract-scripted', 'contract-scripted', EFFECT_CASES,
    (sys.executable, '-B', f'{CORE}/tests/Actions/Effects/contract.py'), EFFECT_INPUTS)
SUITES[('effect-reconcile', 'pg-test')] = Suite('P5.3', 'isolated-postgres', 'isolated-postgres', PG_EFFECT_CASES,
    (sys.executable, '-B', f'{POSTGRES}/tests/Effects/run.py'), EFFECT_INPUTS, requires_pg=True)

CANCELLATION_CASES = ('cancellation.command-binding', 'cancellation.run-policy', 'cancellation.output-certainty') + tuple(
    language + '.cancel.' + name for language in ('php', 'dart')
    for name in ('unknown', 'late-success', 'completed-stop', 'before-io'))
PG_CANCELLATION_CASES = (
    'CANCEL-001', 'V2-P5-4-TTS-POS', 'V2ALL-CANCEL-POSITIVE', 'V2ALL-CANCEL-NEGATIVE', 'V2ALL-CANCEL-FAULT',
    'cancel.manual-atomic',
    'cancel.manual-cas-replay', 'cancel.authority-immutable-audit', 'cancel.source-closure-unknown',
    'cancel.explicit-compensation', 'cancel.compensation-guards', 'cancel.crash-after-commit', 'cancel.competing-commands',
)
CANCELLATION_INPUTS = tuple(dict.fromkeys(EFFECT_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Actions/Cancellation', ROOT / CORE / 'src/Runtime', ROOT / POSTGRES / 'src/Operations',
        ROOT / 'packages/agentools-laravel/src', ROOT / 'packages/agentools-laravel/tests', ROOT / 'fixtures/platform/v1/cancellation')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.json', '.py', '.xml', '.md'}) + (
        f'{POSTGRES}/migrations/0011_operator_operations.sql', f'{POSTGRES}/src/Delivery/DeliveryRepository.php',
        'packages/agentools-laravel/composer.json', 'packages/agentools-laravel/composer.lock',
        'packages/agentools-laravel/vendor/autoload.php', 'packages/agentools-laravel/phpstan.neon',
        'packages/agentools-laravel/tests/Operations/contract.php', 'packages/agentools-laravel/tests/Operations/contract.py',
        'packages/agentools-laravel/tests/Operations/CancellationTest.php', 'packages/agentools-laravel/tests/Operations/CompensationTest.php',
        'packages/agentools-laravel/tests/Operations/RecoveryTest.php', 'packages/agentools-laravel/tests/Operations/run.py',
        'dart/agentools/test/platform/cancellation_test.dart', 'fixtures/platform/v1/cancellation/outcomes.json',
    )))
SUITES[('cancellation-resolution', 'test')] = Suite('P5.4', 'contract-scripted', 'contract-scripted', CANCELLATION_CASES,
    (sys.executable, '-B', 'packages/agentools-laravel/tests/Operations/contract.py'), CANCELLATION_INPUTS)
SUITES[('cancellation-resolution', 'pg-test')] = Suite('P5.4', 'isolated-postgres', 'isolated-postgres', PG_CANCELLATION_CASES,
    (sys.executable, '-B', 'packages/agentools-laravel/tests/Operations/run.py'), CANCELLATION_INPUTS, requires_pg=True)


WRITE_JOURNEY_CASES = ('WRITE-001', 'WRITE-DIRECT-PARITY', 'WRITE-REVOKE', 'WRITE-F2-OWNER-COMMIT',
    'WRITE-TENANT-INSTANCE', 'WRITE-CRASH-MATRIX', 'WRITE-ZOMBIE', 'WRITE-SOURCE-RACE', 'WRITE-DRAIN',
    'WRITE-ATOMIC-MATRIX', 'WRITE-ROLLBACK', 'WRITE-PRIVACY')
WRITE_RECOVERY_CASES = WRITE_JOURNEY_CASES + PG_CONFIRMATION_CASES + PG_EFFECT_CASES + PG_CANCELLATION_CASES
WRITE_BOUNDARIES = ('intake', 'signal-commit', 'wake', 'admission', 'invocation-intent', 'provider-commit',
    'payload-accepted', 'model-result', 'action-submit', 'preview', 'proof', 'confirmation', 'effect-intent',
    'owner-commit', 'effect-confirmed', 'delivery-intent', 'delivery-claim', 'destination-commit', 'delivery-receipt')
WRITE_ATOMIC_BOUNDARIES = ('model-result', 'action-submit', 'preview', 'effect-intent', 'delivery-intent', 'delivery-receipt')
PORTABLE_RECOVERY_IDS = ('effect.pending', 'effect.unknown', 'effect.success', 'effect.failure', 'effect.takeover', 'cancel.unknown', 'cancel.late-success', 'cancel.completed-stop', 'cancel.before-io', 'recovery.stale-epoch', 'recovery.version-conflict', 'recovery.terminal-late', 'recovery.unknown-kind', 'existing.confirmation.recipient', 'existing.confirmation.document', 'existing.confirmation.amount', 'existing.confirmation.caller-flags', 'existing.confirmation.scope', 'existing.confirmation.definition', 'existing.confirmation.policy', 'existing.confirmation.actor', 'existing.confirmation.tenant', 'existing.confirmation.expired', 'existing.confirmation.replay', 'existing.confirmation.missing-proof', 'existing.confirmation.forged-voice', 'recovery.confirmation.ui', 'recovery.confirmation.voice', 'recovery.confirmation.before-expiry', 'recovery.confirmation.at-expiry', 'recovery.confirmation.consume-after-denial', 'recovery.retry.native', 'recovery.retry.read-missing', 'recovery.retry.read-absent', 'recovery.retry.read-found', 'recovery.retry.at-most-once', 'recovery.retry.unsupported', 'recovery.retry.hash-conflict', 'recovery.retry.in-flight')
PORTABLE_RECOVERY_CASES = tuple(consumer + '.' + case for consumer in ('reference', 'php-sdk', 'dart-sdk') for case in PORTABLE_RECOVERY_IDS)
WRITE_RECOVERY_INPUTS = tuple(dict.fromkeys(CANCELLATION_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (ROOT / 'conformance/platform/write-journey', ROOT / 'fixtures/platform/v1/recovery', ROOT / 'php/tests/Recovery')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml', '.sql', '.md', '.neon'}) + (
        'conformance/platform/write-journey/e2e.py', 'conformance/platform/write-journey/full.xml',
        'conformance/platform/write-journey/parity.py', 'conformance/platform/write-journey/RecoveryTest.php',
        'fixtures/platform/v1/recovery/portable.json', 'fixtures/platform/v1/recovery/write-matrix.json',
        'dart/agentools/test/platform/write_recovery_test.dart', 'dart/agentools/test/platform/write_recovery_worker.dart',
        'php/tests/Recovery/PortableRecovery.php', 'php/tests/Recovery/worker.php',
    )))
SUITES[('write-recovery', 'test')] = Suite('P5.5', 'contract-scripted', 'contract-scripted', PORTABLE_RECOVERY_CASES,
    (sys.executable, '-B', 'conformance/platform/write-journey/parity.py'), WRITE_RECOVERY_INPUTS)
SUITES[('write-recovery', 'e2e')] = Suite('P5.5', 'local-host-e2e', 'local-host-e2e', WRITE_RECOVERY_CASES,
    (sys.executable, '-B', 'conformance/platform/write-journey/e2e.py'), WRITE_RECOVERY_INPUTS, requires_pg=True)


LARAVEL_PG_CASES = ('CM-041', 'CM-042', 'V2ALL-MANAGED-POSITIVE', 'V2ALL-MANAGED-NEGATIVE',
    'V2ALL-MANAGED-FAULT', 'managed.disclosure-revocation', 'managed.budget-unknown',
    'managed.delivery-unknown', 'managed.production-no-fake', 'managed.reset-failure',
    'managed.reset-all-ports', 'managed.opt-in-provider', 'managed.octane-sandbox',
    'managed.accounting-failure', 'managed.command-admission', 'managed.single-loop-replay', 'managed.evidence-tenant', 'managed.pg-session-reset')
LARAVEL_CASES = LARAVEL_PG_CASES + ('managed.composer-valid', 'managed.static-analysis',
    'managed.production-no-dev', 'managed.reuse-map')
LARAVEL_INPUTS = tuple(dict.fromkeys(WRITE_RECOVERY_INPUTS + tuple(str(p.relative_to(ROOT))
    for base in (ROOT / 'packages/agentools-laravel/src', ROOT / 'packages/agentools-laravel/tests/Composition')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml'}) + (
        'packages/agentools-laravel/composer.json', 'packages/agentools-laravel/composer.lock',
        'packages/agentools-laravel/phpstan.neon', 'packages/agentools-laravel/vendor/autoload.php',
        'packages/agentools-laravel/tests/Composition/composition.py',
        'packages/agentools-laravel/tests/Composition/ManagedTest.php',
        'packages/agentools-laravel/tests/Composition/LifecycleTest.php',
        'packages/agentools-laravel/tests/Composition/no_dev.py',
        'packages/agentools-laravel/tests/Composition/reuse-map.json', 'docs/platform/laravel.md')))
SUITES[('laravel-composition', 'pg-test')] = Suite('P6.1', 'isolated-postgres', 'isolated-postgres',
    LARAVEL_PG_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Composition/run.py'),
    LARAVEL_INPUTS, requires_pg=True)
# The acceptance test command self-provisions/verifies PG through the existing safe bootstrap.
# Its nested proof is mandatory below, even though the public command has no --isolated option.
SUITES[('laravel-composition', 'test')] = Suite('P6.1', 'contract-scripted', 'contract-scripted',
    LARAVEL_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Composition/composition.py'), LARAVEL_INPUTS)


DEFINITIONS_PG_CASES = ('definitions.immutable-owner', 'definitions.hash-conflict', 'definitions.draft-isolation',
    'definitions.current-revocation', 'plugins.selected-data-only', 'plugins.failure-policy', 'plugins.bounds',
    'plugins.reset-failure', 'instances.cas-replay', 'instances.session-pins', 'instances.current-tenant',
    'instances.commit-fault', 'instances.schema-version', 'definitions.managed-positive',
    'definitions.managed-negative', 'definitions.fragment-guards', 'definitions.managed-fault')
DEFINITIONS_CASES = DEFINITIONS_PG_CASES + ('definitions.composer-valid', 'definitions.static-analysis',
    'definitions.production-no-dev', 'definitions.attribution')
DEFINITIONS_INPUTS = tuple(dict.fromkeys(LARAVEL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'packages/agentools-laravel/src/Definitions', ROOT / 'packages/agentools-laravel/src/Plugins',
    ROOT / 'packages/agentools-laravel/tests/Definitions') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml', '.sql', '.md'}) + (
        'packages/agentools-laravel/tests/Definitions/composition.py',
        'packages/agentools-laravel/tests/Definitions/RegistryTest.php',
        'packages/agentools-laravel/tests/Definitions/PluginTest.php',
        'packages/agentools-laravel/tests/Definitions/InstanceTest.php',
        'packages/agentools-laravel/tests/Definitions/AdmissionTest.php',
        'packages/agentools-laravel/tests/Definitions/attribution.json',
        'packages/agentools-laravel/src/Definitions/schema.sql')))
SUITES[('platform-definitions', 'pg-test')] = Suite('P6.2', 'isolated-postgres', 'isolated-postgres',
    DEFINITIONS_PG_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Definitions/run.py'),
    DEFINITIONS_INPUTS, requires_pg=True)
SUITES[('platform-definitions', 'test')] = Suite('P6.2', 'contract-scripted', 'contract-scripted',
    DEFINITIONS_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Definitions/composition.py'), DEFINITIONS_INPUTS)


COMPOSITION_PORT_CASES = ('fake.ports-identity-state', 'fake.raw-admission-and-purpose-denial',
    'fake.delivery-disclosure-and-unknown', 'fake.owner-requires-durable-boundary',
    'fake.external-fault-and-lookup', 'fake.stream-terminal-loss-stays-unknown', 'fake.tenant-cache-reset')
COMPOSITION_TESTING_PG_CASES = ('V2ALL-FAKE-POSITIVE', 'V2ALL-FAKE-NEGATIVE', 'V2ALL-FAKE-FAULT',
    'fake-profile.raw-admission', 'fake-profile.worker-isolation', 'fake-profile.after-commit',
    'fake-profile.model-recovery', 'fake-profile.owner-boundary')
COMPOSITION_TESTING_CASES = COMPOSITION_PORT_CASES + COMPOSITION_TESTING_PG_CASES + (
    'fake-profile.composer-valid', 'fake-profile.static-analysis',
    'fake-profile.consumer-analysis', 'fake-profile.production-no-dev')
COMPOSITION_TESTING_INPUTS = tuple(dict.fromkeys(DEFINITIONS_INPUTS + tuple(str(p.relative_to(ROOT))
    for base in (ROOT / TESTING / 'src/Composition', ROOT / TESTING / 'tests/Composition',
        ROOT / 'packages/agentools-laravel/tests/Testing', ROOT / 'fixtures/platform/v1/composition')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml', '.md'}) + (
        f'{TESTING}/composer.json', f'{TESTING}/composer.lock', f'{TESTING}/phpstan.neon',
        f'{TESTING}/src/Models/PreciseClock.php', f'{TESTING}/src/Failpoints.php', f'{TESTING}/src/FaultPoint.php',
        f'{TESTING}/src/ScriptedDisclosure.php', f'{TESTING}/vendor/autoload.php',
        'packages/agentools-laravel/tests/Testing/composition.py',
        'packages/agentools-laravel/tests/Testing/ProfileTest.php',
        'packages/agentools-laravel/tests/Testing/FaultTest.php',
        f'{TESTING}/tests/Composition/ports.php', 'fixtures/platform/v1/composition/testing-profile.json')))
SUITES[('composition-testing', 'pg-test')] = Suite('P6.3', 'isolated-postgres', 'isolated-postgres',
    COMPOSITION_TESTING_PG_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Testing/run.py'),
    COMPOSITION_TESTING_INPUTS, requires_pg=True)
SUITES[('composition-testing', 'test')] = Suite('P6.3', 'contract-scripted', 'contract-scripted',
    COMPOSITION_TESTING_CASES, (sys.executable, '-B', 'packages/agentools-laravel/tests/Testing/composition.py'),
    COMPOSITION_TESTING_INPUTS)


FILAMENT_PG_CASES = ('V2ALL-ADMIN-POSITIVE', 'V2ALL-ADMIN-NEGATIVE', 'V2ALL-ADMIN-FAULT',
    'admin.audit-fail-closed', 'admin.diagnostics-permission-ttl', 'admin.worker-tenant-switch',
    'admin.production-no-fake', 'admin.page-fresh-redacted', 'admin.cleanup-partial-failure', 'admin.unknown-owner-outcome')
FILAMENT_SHELL_CASES = ('admin.route-opt-in', 'admin.view-escaping')
FILAMENT_CASES = FILAMENT_PG_CASES + FILAMENT_SHELL_CASES + (
    'admin.composer-valid', 'admin.static-analysis', 'admin.production-no-dev')
FILAMENT_INPUTS = tuple(dict.fromkeys(LARAVEL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'extensions/filament/src', ROOT / 'extensions/filament/resources', ROOT / 'extensions/filament/tests')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml'}) + (
        'extensions/filament/composer.json', 'extensions/filament/composer.lock',
        'extensions/filament/phpstan.neon', 'extensions/filament/vendor/autoload.php',
        'extensions/filament/tests/InspectorTest.php', 'extensions/filament/tests/ShellTest.php',
        'extensions/filament/tests/cases.json', 'extensions/filament/tests/run.py',
        'extensions/filament/tests/shell.py', 'extensions/filament/tests/no_dev.py',
        'extensions/filament/tests/no-dev.php', 'extensions/filament/tests/suite.py',
        'extensions/filament/resources/views/inspect.blade.php',
        'extensions/filament/resources/views/projection.blade.php', 'docs/platform/admin.md')))
SUITES[('filament', 'pg-test')] = Suite('P6.4', 'isolated-postgres', 'isolated-postgres',
    FILAMENT_PG_CASES, (sys.executable, '-B', 'extensions/filament/tests/run.py'), FILAMENT_INPUTS, requires_pg=True)
SUITES[('filament', 'test')] = Suite('P6.4', 'contract-scripted', 'contract-scripted', FILAMENT_CASES,
    (sys.executable, '-B', 'extensions/filament/tests/suite.py'), FILAMENT_INPUTS)


SCHEDULER_PG_CASES = ('CM-045', 'CM-046', 'CM-047', 'V2ALL-SCHED-NEGATIVE',
    'scheduler.atomic-rollback', 'scheduler.stop-unplanned-denied', 'scheduler.crash-after-commit',
    'V2ALL-SCHED-POSITIVE', 'CM-048', 'scheduler.common-guards-source-binding', 'V2ALL-SCHED-FAULT',
    'scheduler.tenant-production', 'scheduler.guard-required-alias-denied')
SCHEDULER_CALENDAR_CASES = ('scheduler.dst-overlap-zone', 'scheduler.dst-gap',
    'scheduler.misfire-policies', 'scheduler.explicit-policies')
SCHEDULER_CASES = SCHEDULER_PG_CASES + SCHEDULER_CALENDAR_CASES + (
    'scheduler.composer-valid', 'scheduler.static-analysis', 'scheduler.core-port-analysis', 'scheduler.production-no-dev')
SCHEDULER_INPUTS = tuple(dict.fromkeys(LARAVEL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'extensions/scheduler/src', ROOT / 'extensions/scheduler/tests', ROOT / 'fixtures/platform/v1/scheduler')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.xml', '.sql'}) + (
        'extensions/scheduler/composer.json', 'extensions/scheduler/composer.lock', 'extensions/scheduler/phpstan.neon',
        'extensions/scheduler/.gitignore', 'extensions/scheduler/vendor/autoload.php',
        'extensions/scheduler/tests/StoreTest.php', 'extensions/scheduler/tests/RuntimeTest.php',
        'extensions/scheduler/tests/CalendarTest.php', 'extensions/scheduler/tests/worker.php',
        'extensions/scheduler/tests/Evidence.php', 'extensions/scheduler/tests/run.py',
        'extensions/scheduler/tests/calendar.py', 'extensions/scheduler/tests/no_dev.py',
        'extensions/scheduler/tests/no-dev.php', 'extensions/scheduler/tests/suite.py',
        'extensions/scheduler/src/schema.sql', 'packages/agentools-core/src/Scheduling/SchedulePort.php',
        'fixtures/platform/v1/scheduler/cases.json', 'docs/platform/scheduler.md')))
SUITES[('scheduler', 'pg-test')] = Suite('P6.5', 'isolated-postgres', 'isolated-postgres',
    SCHEDULER_PG_CASES, (sys.executable, '-B', 'extensions/scheduler/tests/run.py'), SCHEDULER_INPUTS, requires_pg=True)
SUITES[('scheduler', 'test')] = Suite('P6.5', 'contract-scripted', 'contract-scripted', SCHEDULER_CASES,
    (sys.executable, '-B', 'extensions/scheduler/tests/suite.py'), SCHEDULER_INPUTS)


PROFILE_UNIT_CASES = ('profiles.typed-owner-registry', 'profiles.content-hash-codec',
    'profiles.compiler-output-pin', 'profiles.dependency-hash-provenance', 'profiles.cross-tenant-overlay',
    'profiles.closure-duplicate-missing', 'profiles.deterministic-immutable-bytes',
    'profiles.revoked-dependency-not-cached', 'profiles.strict-manifest-envelope')
PROFILE_EXAMPLE_CASES = ('profiles.generated-manifest-examples',)
PROFILE_CASES = PROFILE_UNIT_CASES + PROFILE_EXAMPLE_CASES + ('profiles.core-composer-valid',
    'profiles.pg-composer-valid', 'profiles.core-static-analysis', 'profiles.pg-static-analysis', 'profiles.production-no-dev')
PROFILE_PG_CASES = ('profiles.published-immutable', 'profiles.validation-failure-draft',
    'profiles.publish-current-dependencies', 'profiles.publication-identity-cas', 'profiles.lifecycle-history',
    'profiles.tenant-current-owner', 'profiles.schema-version', 'profiles.assignment-new-sessions-only',
    'profiles.assignment-command-cas', 'profiles.current-guards-over-pins', 'profiles.explicit-migration-audit',
    'profiles.migration-denial', 'profiles.concurrent-publish', 'profiles.concurrent-pin',
    'profiles.publish-sigkill-recovery', 'profiles.pin-sigkill-recovery', 'profiles.migration-sigkill-recovery',
    'profiles.migration-commit-rollback')
PROFILE_INPUTS = tuple(dict.fromkeys(tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Profiles', ROOT / CORE / 'src/Common', ROOT / CORE / 'src/Security',
    ROOT / CORE / 'tests/Profiles', ROOT / 'packages/agentools-postgres/src/Profiles',
    ROOT / 'packages/agentools-postgres/tests/Profiles', ROOT / 'fixtures/platform/v1/profiles')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.sql', '.xml', '.md'}) + (
        f'{CORE}/composer.json', f'{CORE}/composer.lock', f'{CORE}/phpstan.neon',
        f'{CORE}/tests/Common/JcsAdapter.php', f'{CORE}/tests/Common/TestHost.php',
        f'{CORE}/tests/Common/Harness.php', f'{CORE}/tests/Common/bootstrap.php',
        'packages/agentools-postgres/composer.json', 'packages/agentools-postgres/composer.lock',
        'packages/agentools-postgres/phpstan.neon', 'packages/agentools-postgres/tests/run.py',
        'packages/agentools-postgres/tests/IsolatedDatabase.php',
        'packages/agentools-postgres/tests/Runtime/TestRevisions.php',
        'packages/agentools-postgres/src/Runtime/Connection.php',
        'packages/agentools-postgres/src/Runtime/RepositoryContext.php',
        'packages/agentools-postgres/src/Runtime/ContextRevision.php',
        'packages/agentools-postgres/src/Runtime/StorageFailure.php',
        f'{CORE}/tests/Profiles/suite.py', f'{CORE}/tests/Profiles/publication.php',
        f'{CORE}/tests/Profiles/examples.php', f'{CORE}/tests/Profiles/no_dev.py',
        'packages/agentools-postgres/src/Profiles/schema.sql',
        'packages/agentools-postgres/tests/Profiles/run.py',
        'packages/agentools-postgres/tests/Profiles/PublicationTest.php',
        'packages/agentools-postgres/tests/Profiles/PinningTest.php',
        'packages/agentools-postgres/tests/Profiles/RecoveryTest.php',
        'packages/agentools-postgres/tests/Profiles/worker.php',
        'fixtures/platform/v1/profiles/bundle-7.json', 'fixtures/platform/v1/profiles/bundle-8.json',
        'fixtures/platform/v1/profiles/skill-dependency.json', 'fixtures/platform/v1/profiles/paired-merge.json',
        'fixtures/platform/v1/profiles/skill-manifest.example.json',
        'fixtures/platform/v1/profiles/README.md', 'tools/storage_chaos.py',
        'php/composer.json', 'php/composer.lock', 'php/src/Schema/Canonicalizer.php',
        'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('profile-publish', 'test')] = Suite('P7.1', 'contract-scripted', 'contract-scripted',
    PROFILE_CASES, (sys.executable, '-B', f'{CORE}/tests/Profiles/suite.py'), PROFILE_INPUTS)
SUITES[('profile-publish', 'pg-test')] = Suite('P7.1', 'isolated-postgres', 'isolated-postgres',
    PROFILE_PG_CASES, (sys.executable, '-B', 'packages/agentools-postgres/tests/Profiles/run.py'),
    PROFILE_INPUTS, requires_pg=True)


PROFILE_MERGE_CORE_CASES = ('CM-001','CM-002','V2ALL-PROFILE-POSITIVE','V2ALL-PROFILE-NEGATIVE',
    'merge.methodology-approved','merge.style-bounds','merge.required-capability',
    'merge.deterministic-provenance','merge.document-hash-validation','merge.dependency-closure')
PROFILE_MERGE_LIVE_CASES = ('CM-004','V2ALL-PROFILE-FAULT','merge.material-rights-current',
    'merge.no-unknown-default','merge.operational-role-required','merge.filtered-serialization')
PROFILE_MERGE_PG_CASES = ('CM-003','CM-005','CM-006','V2-P7-2-TENANT-POS','V2-P7-2-TENANT-FAULT')
PROFILE_MERGE_CASES = PROFILE_MERGE_CORE_CASES + PROFILE_MERGE_LIVE_CASES + PROFILE_MERGE_PG_CASES + (
    'merge.composer-valid','merge.static-analysis','merge.production-no-dev')
PROFILE_MERGE_INPUTS = tuple(dict.fromkeys(PROFILE_INPUTS + tuple(str(p.relative_to(ROOT))
    for p in sorted((ROOT / 'fixtures/platform/v1/merge').rglob('*')) if p.is_file()) + (
        f'{CORE}/tests/Profiles/merge.php', f'{CORE}/tests/Profiles/merge-live.php',
        f'{CORE}/tests/Profiles/merge-pg.py', f'{CORE}/tests/Profiles/merge-suite.py',
        f'{CORE}/tests/Profiles/merge-phpunit.xml', f'{CORE}/tests/Profiles/merge-pg-bootstrap.php',
        f'{CORE}/tests/Profiles/MergeFixture.php', f'{CORE}/tests/Profiles/MergeProof.php',
        f'{CORE}/tests/Profiles/PublicationMergeTest.php',
        f'{CORE}/src/Profiles/Composition/Composer.php', f'{CORE}/src/Profiles/Composition/TypedCompiler.php',
        'fixtures/platform/v1/merge/compatible.json', 'fixtures/platform/v1/merge/conflict.json',
        'fixtures/platform/v1/merge/README.md')))
SUITES[('profile-merge','test')] = Suite('P7.2','contract-scripted','contract-scripted',
    PROFILE_MERGE_CASES, (sys.executable,'-B',f'{CORE}/tests/Profiles/merge-suite.py'), PROFILE_MERGE_INPUTS)


SKILL_CORE_CASES = ('CM-007','CM-008','V2ALL-SKILL-POSITIVE','V2ALL-SKILL-NEGATIVE',
    'skills.missing-context','skills.reproducible-host-route','skills.tool-versions-availability',
    'skills.token-bounds','skills.permissions-shortlist','skills.metadata-bounds')
SKILL_LIFECYCLE_CASES = ('V2ALL-SKILL-FAULT','skills.injection-remains-data','skills.qualification-exact-tuple',
    'skills.publication-qualification-required','skills.versioned-new-runs','skills.tenant-code-separation','skills.current-guard-after-load')
SKILL_EXAMPLE_CASES = ('skills.generated-examples',)
SKILL_CASES = SKILL_CORE_CASES + SKILL_LIFECYCLE_CASES + SKILL_EXAMPLE_CASES + (
    'skills.composer-valid','skills.static-analysis','skills.production-no-dev')
SKILL_INPUTS = tuple(dict.fromkeys(PROFILE_MERGE_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Models', ROOT / CORE / 'src/Context', ROOT / 'fixtures/platform/v1/skills')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.json','.md'}) + (
        f'{CORE}/src/Profiles/Skills/SkillManifest.php', f'{CORE}/src/Profiles/Skills/Router.php',
        f'{CORE}/src/Profiles/Skills/ManifestCodec.php', f'{CORE}/src/Profiles/Skills/Projection.php',
        f'{CORE}/tests/Profiles/Skills/contract.php', f'{CORE}/tests/Profiles/Skills/lifecycle.php',
        f'{CORE}/tests/Profiles/Skills/examples.php', f'{CORE}/tests/Profiles/Skills/suite.py',
        f'{CORE}/tests/Profiles/Skills/no_dev.py', f'{CORE}/tests/Profiles/Skills/Fixture.php',
        f'{CORE}/tests/Profiles/Skills/Capture.php', f'{CORE}/tests/Profiles/Skills/Proof.php',
        'fixtures/platform/v1/skills/manifest.json', 'fixtures/platform/v1/skills/fragment.json',
        'fixtures/platform/v1/skills/selection.json', 'fixtures/platform/v1/skills/README.md')))
SUITES[('skill-selection','test')] = Suite('P7.3','contract-scripted','contract-scripted',
    SKILL_CASES,(sys.executable,'-B',f'{CORE}/tests/Profiles/Skills/suite.py'),SKILL_INPUTS)


CONTEXT_CORE_CASES = ('CM-009','CM-010','CM-011','CM-012','CM-013','V2-P7-4-TRUST-POS','V2-P7-4-TOKENIZER-FAULT')
CONTEXT_LIFECYCLE_CASES = ('V2ALL-CONTEXT-POSITIVE','V2ALL-CONTEXT-NEGATIVE','V2ALL-CONTEXT-FAULT',
    'V2ALL-TOKEN-POSITIVE','V2ALL-TOKEN-NEGATIVE','V2ALL-TOKEN-FAULT','context.diagnostics-authorized-ttl')
CONTEXT_EXAMPLE_CASES = ('context.generated-examples',)
CONTEXT_PG_CASES = ('context.snapshot-durable-replay','context.snapshot-current-scope','context.snapshot-integrity-conflict')
CONTEXT_CASES = CONTEXT_CORE_CASES + CONTEXT_LIFECYCLE_CASES + CONTEXT_EXAMPLE_CASES + CONTEXT_READ_CASES + CONTEXT_PG_CASES + (
    'context.composer-valid','context.static-analysis','context.production-no-dev')
CONTEXT_INPUTS = tuple(dict.fromkeys(tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Common', ROOT / CORE / 'src/Security', ROOT / CORE / 'src/Context', ROOT / CORE / 'tests/Context',
    ROOT / POSTGRES / 'src/Context', ROOT / POSTGRES / 'tests/Context', ROOT / 'spec/platform/v1/context', ROOT / 'fixtures/platform/v1/context')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.sql','.md'}) + (
    f'{CORE}/composer.json',f'{CORE}/composer.lock',f'{CORE}/phpstan.neon', f'{CORE}/src/Profiles/BundleRef.php',
    f'{CORE}/tests/Common/bootstrap.php',f'{CORE}/tests/Common/Harness.php',f'{CORE}/tests/Common/JcsAdapter.php',f'{CORE}/tests/Common/TestHost.php',
    f'{POSTGRES}/composer.json',f'{POSTGRES}/composer.lock',f'{POSTGRES}/src/Runtime/Connection.php',f'{POSTGRES}/src/Runtime/RepositoryContext.php',
    f'{POSTGRES}/src/Runtime/ContextRevision.php',f'{POSTGRES}/src/Runtime/StorageFailure.php',f'{POSTGRES}/tests/IsolatedDatabase.php',
    'php/src/Schema/Canonicalizer.php','tools/platform_gate.py','tools/tests/test_platform_gate.py')))
SUITES[('context-compiler','test')] = Suite('P7.4','contract-scripted','contract-scripted',CONTEXT_CASES,
    (sys.executable,'-B',f'{CORE}/tests/Context/suite.py'),CONTEXT_INPUTS)
SUITES[('context-compiler','pg-test')] = Suite('P7.4-pg','isolated-postgres','isolated-postgres',CONTEXT_PG_CASES,
    ('php',f'{POSTGRES}/tests/Context/snapshots.php'),CONTEXT_INPUTS,requires_pg=True)


HISTORY_CORE_CASES = ('CM-014','CM-017','V2ALL-HISTORY-POSITIVE','V2ALL-HISTORY-NEGATIVE','V2ALL-HISTORY-FAULT',
    'history.working-cas','history.trigger-source-rebuild','history.bounded-unavailable')
MEMORY_CORE_CASES = ('CM-015','CM-016','memory.lifecycle-evidence-dedup','memory.expiry-revoke','memory.consent-export','memory.tenant-injection')
HISTORY_EXAMPLE_CASES = ('history.generated-owner-example',)
HISTORY_CASES = HISTORY_CORE_CASES + MEMORY_CORE_CASES + HISTORY_EXAMPLE_CASES + (
    'history.composer-valid','history.static-analysis','history.production-no-dev')
SUMMARY_DURABLE_CASES = ('summary.durable-metadata','summary.monotone-integrity','summary.source-cascade','summary.current-guards-corruption')
SUMMARY_CONCURRENT_CASES = ('summary.competing-connections',)
SUMMARY_RECOVERY_CASES = ('summary.crash-restart',)
SUMMARY_PG_CASES = SUMMARY_DURABLE_CASES + SUMMARY_CONCURRENT_CASES + SUMMARY_RECOVERY_CASES
HISTORY_INPUTS = tuple(dict.fromkeys(CONTEXT_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / POSTGRES / 'src/Summaries', ROOT / POSTGRES / 'tests/Summaries', ROOT / 'fixtures/platform/v1/history')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.sql','.md'})))
SUITES[('history-memory','test')] = Suite('P7.5','contract-scripted','contract-scripted',HISTORY_CASES,
    (sys.executable,'-B',f'{CORE}/tests/Context/History/suite.py'),HISTORY_INPUTS)
SUITES[('history-memory','pg-test')] = Suite('P7.5-pg','isolated-postgres','isolated-postgres',SUMMARY_PG_CASES,
    (sys.executable,'-B',f'{POSTGRES}/tests/Summaries/suite.py'),HISTORY_INPUTS,requires_pg=True)


SECURITY_GUARD_CASES = ('security.injection-surfaces','security.stale-revision','security.current-source-revoke','security.entry-parity',
    'security.exact-rendered-binding','security.emergency-and-unknown','security.snapshot-integrity-and-scope')
SECURITY_DISCLOSURE_CASES = ('CM-043','security.disclosure-denial-and-revoke','security.checked-segments-interruption','security.live-approved',
    'security.unknown-delivery','security.output-order-bounds','security.disclosure-policy-refresh')
SECURITY_WORKER_CASES = ('security.worker-isolation','security.worker-reset-fault','security.redacted-logs-diagnostics')
SECURITY_EXAMPLE_CASES = ('security.generated-examples',)
SECURITY_CASES = SECURITY_GUARD_CASES + SECURITY_DISCLOSURE_CASES + SECURITY_WORKER_CASES + SECURITY_EXAMPLE_CASES + (
    'security.composer-valid','security.static-analysis','security.production-no-dev')
SECURITY_INPUTS = tuple(dict.fromkeys(HISTORY_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'tests/Security', ROOT / CORE / 'src/Models', ROOT / 'fixtures/platform/v1/security') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php','.py','.json','.md'}) + (
        f'{CORE}/src/Runtime/RequestRenderer.php', f'{CORE}/src/Runtime/GuardedTransport.php',
        f'{CORE}/src/Models/ModelTransport.php', f'{CORE}/src/Models/ModelRequest.php', f'{CORE}/src/Models/TransportPreflight.php',
        f'{CORE}/src/Profiles/DefinitionRef.php')))
SUITES[('context-guards','test')] = Suite('P7.6','contract-scripted','contract-scripted',SECURITY_CASES,
    (sys.executable,'-B',f'{CORE}/tests/Security/suite.py'),SECURITY_INPUTS)
SUITES[('context-security','test')] = SUITES[('context-guards','test')]

PROFILE_ADMIN_PG_CASES = ('profile-admin.lifecycle', 'profile-admin.immutable',
    'profile-admin.conflict-provenance', 'profile-admin.cross-tenant-actor', 'profile-admin.current-revocation',
    'profile-admin.assignment-pins-migration', 'profile-admin.command-cas', 'profile-admin.emergency-compatibility',
    'profile-admin.deprecate-retire', 'profile-admin.audit-unknown', 'profile-admin.scope-production',
    'profile-admin.page-current-rights', 'profile-admin.untrusted-form')
PROFILE_ADMIN_SHELL_CASES = ('profile-admin.route-opt-in', 'profile-admin.escaped-conflict')
PROFILE_ADMIN_CASES = PROFILE_ADMIN_PG_CASES + PROFILE_ADMIN_SHELL_CASES + (
    'profile-admin.composer-valid', 'profile-admin.static-analysis', 'profile-admin.production-no-dev')
PROFILE_ADMIN_INPUTS = tuple(dict.fromkeys(FILAMENT_INPUTS + PROFILE_INPUTS + PROFILE_MERGE_INPUTS + (
    'extensions/filament/tests/ProfilePublishTest.php', 'extensions/filament/tests/ProfileShellTest.php',
    'extensions/filament/tests/ProfileFixture.php', 'extensions/filament/tests/profile-publish.xml',
    'extensions/filament/tests/profile-shell.xml', 'extensions/filament/tests/profile-cases.json',
    'extensions/filament/tests/profile-run.py', 'extensions/filament/tests/profile-shell.py',
    'extensions/filament/tests/profile-suite.py', 'extensions/filament/src/Profiles/Admin.php',
    'extensions/filament/src/Pages/Profiles.php', 'extensions/filament/resources/views/profiles.blade.php',
    'extensions/filament/resources/views/profile-projection.blade.php', 'extensions/filament/README.md',
    'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('profile-publish-admin', 'pg-test')] = Suite('P7.7-pg', 'isolated-postgres', 'isolated-postgres',
    PROFILE_ADMIN_PG_CASES, (sys.executable, '-B', 'extensions/filament/tests/profile-run.py'), PROFILE_ADMIN_INPUTS, requires_pg=True)
SUITES[('profile-publish-admin', 'test')] = Suite('P7.7', 'contract-scripted', 'contract-scripted',
    PROFILE_ADMIN_CASES, (sys.executable, '-B', 'extensions/filament/tests/profile-suite.py'), PROFILE_ADMIN_INPUTS)


# P8.1 optional owner-referenced ingest; no fake durable substitute.
KNOWLEDGE_UNIT_CASES = ('knowledge.owner-projection-and-provenance', 'knowledge.tenant-audience-scope-before-source', 'knowledge.owner-denied-and-unknown', 'knowledge.source-ref-and-hash-mismatch', 'knowledge.artifact-hashes-and-source-mapping', 'knowledge.utf8-and-coordinate-boundaries', 'knowledge.hierarchy-and-duplicate-ids', 'knowledge.completeness-and-required-cards', 'knowledge.unknown-validation-cannot-publish', 'knowledge.revoke-during-extraction', 'knowledge.immutable-and-corrupt-release', 'knowledge.embedding-space-isolation', 'knowledge.multiple-pages-and-tenant-identity', 'knowledge.shared-fixtures')
KNOWLEDGE_PG_CASES = ('knowledge.durable-replay-and-hash-conflict', 'knowledge.correction-invalidates-exact-dependents', 'knowledge.failed-correction-rolls-back', 'knowledge.tenant-current-rights-and-unknown', 'knowledge.sql-immutability-and-tenant-foreign-key', 'knowledge.competing-connections', 'knowledge.crash-before-and-after-commit-restart')
KNOWLEDGE_CASES = KNOWLEDGE_UNIT_CASES + (
    'knowledge.composer-valid', 'knowledge.static-analysis', 'knowledge.production-no-dev')
KNOWLEDGE_INPUTS = tuple(dict.fromkeys(tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'extensions/knowledge/src', ROOT / 'extensions/knowledge/tests',
    ROOT / 'spec/platform/v1/knowledge', ROOT / 'fixtures/platform/v1/knowledge')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.xml','.sql','.md'}) + (
    'extensions/knowledge/composer.json', 'extensions/knowledge/composer.lock',
    'extensions/knowledge/phpstan.neon', 'extensions/knowledge/.gitignore',
    'extensions/knowledge/vendor/autoload.php', 'extensions/knowledge/vendor/bin/phpunit',
    'extensions/knowledge/vendor/bin/phpstan', 'packages/agentools-postgres/tests/run.py',
    'packages/agentools-postgres/tests/IsolatedDatabase.php', 'packages/agentools-postgres/vendor/autoload.php',
    'packages/agentools-postgres/vendor/bin/phpunit', 'packages/agentools-postgres/composer.lock',
    'tools/storage_chaos.py', 'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('knowledge-ingest', 'test')] = Suite('P8.1', 'contract-scripted', 'contract-scripted',
    KNOWLEDGE_CASES, (sys.executable, '-B', 'extensions/knowledge/tests/suite.py'), KNOWLEDGE_INPUTS)
SUITES[('knowledge-ingest', 'pg-test')] = Suite('P8.1-pg', 'isolated-postgres', 'isolated-postgres',
    KNOWLEDGE_PG_CASES, (sys.executable, '-B', 'extensions/knowledge/tests/pg.py'), KNOWLEDGE_INPUTS, requires_pg=True)


# P8.2: actual optional retrieval adapters, with scripted external owners only.
KNOWLEDGE_RETRIEVAL_UNIT_CASES = (
    'CM-018', 'CM-019', 'CM-021', 'CM-022', 'retrieval.pre-filter-metadata-and-rights',
    'retrieval.unknown-missing-expiry', 'retrieval.run-and-cache-binding', 'CM-020',
    'V2-P8-2-SPACE-POS', 'V2-P8-2-SPACE-FAULT', 'V2-P8-2-RETRIEVAL-POS',
    'V2ALL-KNOWLEDGE-POSITIVE', 'V2ALL-KNOWLEDGE-NEGATIVE', 'V2ALL-KNOWLEDGE-FAULT',
    'retrieval.rank-fusion-and-rerank', 'retrieval.overlap-dedup-diversity',
    'retrieval.utf8-minimal-budgets', 'retrieval.invalid-vectors', 'retrieval.cache-and-rerank-poison',
    'retrieval.last-boundary-worker-reset', 'retrieval.shared-fixtures-owner-views',
    'retrieval.query-selection-limits', 'retrieval.cache-shape-and-lifetime',
    'retrieval.source-change-at-final-repository-read',
)
KNOWLEDGE_RETRIEVAL_CASES = KNOWLEDGE_RETRIEVAL_UNIT_CASES + (
    'retrieval.composer-valid', 'retrieval.static-analysis', 'retrieval.production-no-dev')
KNOWLEDGE_RETRIEVAL_INPUTS = tuple(dict.fromkeys(tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'extensions/knowledge/src', ROOT / 'extensions/knowledge/tests/Retrieval',
    ROOT / 'spec/platform/v1/knowledge', ROOT / 'fixtures/platform/v1/retrieval')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.xml','.sql','.md'}) + (
    'extensions/knowledge/composer.json', 'extensions/knowledge/composer.lock',
    'extensions/knowledge/phpstan.neon', 'extensions/knowledge/.gitignore',
    'extensions/knowledge/tests/bootstrap.php', 'extensions/knowledge/tests/Fixture.php',
    'extensions/knowledge/vendor/autoload.php', 'extensions/knowledge/vendor/bin/phpunit',
    'extensions/knowledge/vendor/bin/phpstan', 'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('knowledge-retrieval', 'test')] = Suite('P8.2', 'contract-scripted', 'contract-scripted',
    KNOWLEDGE_RETRIEVAL_CASES, (sys.executable, '-B', 'extensions/knowledge/tests/Retrieval/suite.py'),
    KNOWLEDGE_RETRIEVAL_INPUTS)


# P8.3: transitive technical dependency graph, local denial before provider deletion.
KNOWLEDGE_INVALIDATION_UNIT_CASES = ('invalidation.exact-tenant-version-hash', 'invalidation.explicit-delete-capability', 'invalidation.delete-replay-and-unknown', 'invalidation.delete-unsupported-retry', 'invalidation.cached-context-fresh-guards', 'invalidation.summary-view-revoke-and-missing', 'invalidation.memory-view-revoke')
KNOWLEDGE_INVALIDATION_PG_CASES = ('invalidation.graph-correction-and-history', 'invalidation.message-delete-revoke-consent', 'invalidation.graph-identity-tenant-and-sql', 'invalidation.durable-delete-receipts', 'invalidation.repository-stale-cache-and-history', 'invalidation.correction-atomic-rollback', 'invalidation.competing-register-revoke', 'invalidation.sigkill-invalidation-restart', 'invalidation.sigkill-provider-response-unknown', 'invalidation.explicit-correction-and-delete-reasons')
KNOWLEDGE_INVALIDATION_CASES = KNOWLEDGE_INVALIDATION_UNIT_CASES + (
    'invalidation.composer-valid', 'invalidation.static-analysis', 'invalidation.core-static-analysis', 'invalidation.production-no-dev')
KNOWLEDGE_INVALIDATION_INPUTS = tuple(dict.fromkeys(KNOWLEDGE_RETRIEVAL_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'extensions/knowledge/tests/Invalidation', ROOT / CORE / 'src', ROOT / CORE / 'tests/Context', ROOT / CORE / 'tests/Common')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.xml','.md'}) + (
    f'{CORE}/composer.json', f'{CORE}/composer.lock', f'{CORE}/phpstan.neon', f'{CORE}/vendor/autoload.php', f'{CORE}/vendor/bin/phpstan',
    'extensions/knowledge/tests/bootstrap.php', 'extensions/knowledge/tests/Fixture.php',
    'packages/agentools-postgres/tests/run.py', 'packages/agentools-postgres/tests/IsolatedDatabase.php',
    'packages/agentools-postgres/vendor/autoload.php', 'packages/agentools-postgres/vendor/bin/phpunit',
    'tools/storage_chaos.py', 'php/vendor/autoload.php', 'php/src/Schema/Canonicalizer.php')))
SUITES[('knowledge-invalidation','test')] = Suite('P8.3','contract-scripted','contract-scripted',KNOWLEDGE_INVALIDATION_CASES,
    (sys.executable,'-B','extensions/knowledge/tests/Invalidation/suite.py'),KNOWLEDGE_INVALIDATION_INPUTS)
SUITES[('knowledge-invalidation','pg-test')] = Suite('P8.3-pg','isolated-postgres','isolated-postgres',KNOWLEDGE_INVALIDATION_PG_CASES,
    (sys.executable,'-B','extensions/knowledge/tests/Invalidation/pg.py'),KNOWLEDGE_INVALIDATION_INPUTS,requires_pg=True)


KNOWLEDGE_JOURNEY_UNIT_CASES = ('knowledge-golden.host-formula-unit-gold', 'knowledge-golden.domain-gold-insufficient', 'knowledge-golden.technical-coordinates-hashes', 'knowledge-golden.synthetic-nonpersonal-only', 'knowledge-golden.static-analysis')
KNOWLEDGE_JOURNEY_CASES = ('KNOWLEDGE-SUPPORT-GOLDEN', 'KNOWLEDGE-EDUCATION-GOLDEN', 'knowledge-golden.domain-release-gate', 'KNOWLEDGE-CORRECTION-GOLDEN', 'knowledge-golden.buffered-revoke', 'knowledge-golden.tenant-audience-unknown', 'knowledge-golden.no-fabricated-source', 'knowledge-golden.mandatory-context-budget', 'knowledge-golden.correction-before-provider', 'knowledge-golden.provider-crash-recovery', 'knowledge-golden.runnable-example', 'knowledge-golden.index-identity-and-tenant', 'KNOWLEDGE-EDUCATION-CORRECTION')
KNOWLEDGE_JOURNEY_INPUTS = tuple(dict.fromkeys(READ_JOURNEY_INPUTS + KNOWLEDGE_INVALIDATION_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'examples/platform/knowledge', ROOT / 'conformance/platform/knowledge', ROOT / 'extensions/knowledge/tests/Golden')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.json','.xml','.sql','.md','.neon'})))
SUITES[('knowledge-journey','e2e')] = Suite('P8.4','local-host-e2e','local-host-e2e',KNOWLEDGE_JOURNEY_CASES,
    (sys.executable,'-B','conformance/platform/knowledge/run.py'),KNOWLEDGE_JOURNEY_INPUTS,requires_pg=True)


ROUTING_CASES = (
    'catalog.schema-validation', 'catalog.full-hash-and-evidence', 'catalog.price-unverified-not-ranked',
    'CM-023', 'V2ALL-MODEL-POSITIVE', 'routing.qualification-exact-tuple',
    'routing.qualification-expiry-and-rejection', 'routing.qualification-current-rejection',
    'routing.admin-separate-health', 'routing.legal-data-host-quota', 'routing.emergency-target-matrix',
    'routing.all-unavailable-and-tenant', 'routing.current-check-before-io',
    'routing.draining-existing-and-incident', 'routing.stream-current-and-capability', 'V2ALL-MODEL-NEGATIVE',
    'CM-024', 'CM-025', 'CM-026', 'CM-027', 'V2-P9-1-INTERRUPT-POS',
    'fallback.segment-policy-denied', 'fallback.disclosure-unknown-effect',
    'V2-P9-1-RECONCILE-POS', 'V2ALL-MODEL-FAULT',
)
ROUTING_PG_CASES = tuple('routing-pg.' + name for name in (
    'testImmutableReplayAndConflict', 'testQualificationAndLiveEmergency', 'testStateCasAndAuthorization',
    'testTenantIsolationAndDeniedWrites', 'testTamperAndSchemaRevision', 'testTraceAndFallbackIdentity',
))
ROUTING_INPUTS = tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Common', ROOT / CORE / 'src/Models', ROOT / CORE / 'tests/Models/Routing',
    ROOT / 'fixtures/platform/v1/routing') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php', '.json', '.md'}) + (
    f'{CORE}/composer.json', f'{CORE}/composer.lock', f'{CORE}/phpstan.neon', f'{CORE}/vendor/autoload.php',
    f'{CORE}/tests/Common/bootstrap.php', f'{CORE}/tests/Common/Harness.php',
    f'{CORE}/tests/Common/JcsAdapter.php', f'{CORE}/tests/Common/TestHost.php',
    f'{CORE}/tests/Models/bootstrap.php', f'{CORE}/tests/Models/ModelFixtures.php',
    'php/src/Schema/Canonicalizer.php', 'php/composer.json', 'php/composer.lock',
    'tools/platform_gate.py', 'tools/tests/test_platform_gate.py',
)
ROUTING_PG_INPUTS = ROUTING_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / 'packages/agentools-postgres/src/Deployments', ROOT / 'packages/agentools-postgres/tests/Deployments')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.sql', '.xml'}) + (
    'packages/agentools-postgres/composer.json', 'packages/agentools-postgres/composer.lock',
    'packages/agentools-postgres/phpstan.neon',
    'packages/agentools-postgres/vendor/autoload.php', 'packages/agentools-postgres/vendor/bin/phpunit',
    'packages/agentools-postgres/tests/run.py', 'packages/agentools-postgres/tests/IsolatedDatabase.php',
    'packages/agentools-postgres/src/Runtime/Connection.php', 'packages/agentools-postgres/src/Runtime/StorageFailure.php',
    'tools/storage_chaos.py',
)
SUITES[('routing', 'test')] = Suite('P9.1', 'contract-scripted', 'contract-scripted', ROUTING_CASES,
    ('php', f'{CORE}/tests/Models/Routing/run.php'), ROUTING_INPUTS)
SUITES[('routing', 'pg-test')] = Suite('P9.1-pg', 'isolated-postgres', 'isolated-postgres', ROUTING_PG_CASES,
    (sys.executable, '-B', 'packages/agentools-postgres/tests/Deployments/run.py'), ROUTING_PG_INPUTS, requires_pg=True)
SUITES[('deployment-catalog', 'test')] = SUITES[('routing', 'test')]
SUITES[('deployment-catalog', 'pg-test')] = SUITES[('routing', 'pg-test')]
SUITES[('deployment-routing', 'test')] = SUITES[('routing', 'test')]
SUITES[('deployment-routing', 'pg-test')] = SUITES[('routing', 'pg-test')]


USAGE_CASES = (
    'CM-035', 'CM-036', 'ECO-USE-001', 'ECO-USE-005', 'ECO-USE-006', 'ECO-USE-008', 'ECO-USE-010',
    'usage.observed-zero', 'usage.absent-cached-unknown', 'usage.overlap-preserved', 'usage.outbound-unproven',
    'usage.invalid-quantities-and-overlap', 'usage.separate-adapter-mapping',
    'CM-037', 'ECO-USE-002', 'CM-038', 'ECO-USE-007', 'ECO-USE-003', 'ECO-USE-009',
    'V2-P9-2-FALLBACK-NEG', 'ECO-USE-004', 'V2-P9-2-FALLBACK-POS',
    'V2ALL-USAGE-POSITIVE', 'V2ALL-USAGE-NEGATIVE', 'V2ALL-USAGE-FAULT',
    'budget.strict-scope', 'budget.identity-conflict', 'budget.denied-unavailable-unknown',
    'budget.current-guards', 'budget.release-exposure', 'budget.unknown-settlement', 'budget.renew', 'budget.result-codec',
)
USAGE_PORTABLE_CASES = (
    'CM-035', 'CM-036', 'ECO-USE-001', 'ECO-USE-005', 'ECO-USE-006', 'ECO-USE-008', 'ECO-USE-010',
    'usage.observed-zero', 'usage.absent-cached-unknown', 'usage.overlap-preserved', 'usage.outbound-unproven',
    'ECO-USE-002', 'ECO-USE-003', 'ECO-USE-009', 'V2-P9-2-FALLBACK-NEG', 'ECO-USE-004', 'ECO-USE-007',
    'V2-P9-2-FALLBACK-POS', 'budget.result-codec',
)
USAGE_INPUTS = tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / CORE / 'src/Usage', ROOT / CORE / 'src/Budget', ROOT / CORE / 'src/Common',
    ROOT / CORE / 'tests/Usage', ROOT / 'spec/platform/v1/usage', ROOT / 'fixtures/platform/v1/usage')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.md'}) + (
    f'{CORE}/composer.json', f'{CORE}/composer.lock', f'{CORE}/phpstan.neon', f'{CORE}/vendor/autoload.php',
    f'{CORE}/tests/Common/bootstrap.php', f'{CORE}/tests/Common/JcsAdapter.php',
    f'{CORE}/tests/Common/Harness.php', f'{CORE}/tests/Common/TestHost.php',
    'php/src/Schema/Canonicalizer.php', 'php/composer.json', 'php/composer.lock',
    'tools/platform_gate.py', 'tools/tests/test_platform_gate.py',
)
USAGE_PARITY_INPUTS = USAGE_INPUTS + (
    'dart/agentools/lib/platform_usage.dart', 'dart/agentools/lib/src/platform/usage.dart',
    'dart/agentools/lib/src/schema/canonicalizer.dart', 'dart/agentools/lib/src/schema/jcs_number.dart',
    'dart/agentools/pubspec.yaml', 'dart/agentools/pubspec.lock',
    'dart/agentools/test/platform/usage_contract.dart', 'dart/agentools/test/platform/usage_worker.dart',
    'dart/agentools/test/platform/usage_test.dart',
)
SUITES[('usage-normalization', 'test')] = Suite('P9.2', 'contract-scripted', 'contract-scripted', USAGE_CASES,
    ('php', f'{CORE}/tests/Usage/run.php'), USAGE_INPUTS)
SUITES[('usage-budget', 'test')] = SUITES[('usage-normalization', 'test')]
SUITES[('usage', 'test')] = SUITES[('usage-normalization', 'test')]
SUITES[('usage-admission', 'test')] = SUITES[('usage-normalization', 'test')]
SUITES[('usage-parity', 'test')] = Suite('P9.2-parity', 'contract-scripted', 'contract-scripted', USAGE_PORTABLE_CASES,
    (sys.executable, '-B', f'{CORE}/tests/Usage/parity.py'), USAGE_PARITY_INPUTS)


USAGE_RESERVATIONS_CASES = (
    'CM-039', 'ECO-ADM-001', 'ECO-ADM-002', 'ECO-ADM-004', 'ECO-ADM-005',
    'V2ALL-ADMISSION-POSITIVE', 'V2ALL-ADMISSION-NEGATIVE', 'V2ALL-ADMISSION-FAULT',
    'accounting.explicit-owner-proof',
)
USAGE_RESERVATIONS_PG_CASES = (
    'CM-040', 'accounting.atomic-response-export', 'accounting.identity-quarantine',
    'accounting.correction-replacement', 'accounting.settle-once', 'accounting.admission-conflict-backlog',
    'accounting.denial-revocation-before-provider', 'ECO-ADM-003', 'accounting.lost-admit-ack',
    'accounting.lost-release-renew', 'accounting.no-lookup-no-retry', 'accounting.export-replay-backoff',
    'accounting.worker-reset-redaction', 'accounting.competing-connections',
    'accounting.crash-commit-windows', 'accounting.crash-export-ack',
)
USAGE_RESERVATIONS_INPUTS = tuple(dict.fromkeys(USAGE_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / POSTGRES / 'src/Usage', ROOT / POSTGRES / 'src/Budget', ROOT / POSTGRES / 'tests/Usage',
        ROOT / 'packages/agentools-laravel/src/Usage', ROOT / 'fixtures/platform/v1/admission',
        ROOT / POSTGRES / 'migrations', ROOT / POSTGRES / 'tests/Models', ROOT / POSTGRES / 'tests/Runtime',
        ROOT / CORE / 'src/Runtime', ROOT / CORE / 'src/Models', ROOT / CORE / 'src/Security',
    ) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.md', '.sql'}
) + (f'{POSTGRES}/src/Models/InvocationRepository.php', f'{POSTGRES}/src/Runtime/Connection.php',
     f'{POSTGRES}/src/Runtime/RepositoryContext.php', f'{POSTGRES}/src/Migrator.php',
     f'{POSTGRES}/tests/IsolatedDatabase.php', f'{POSTGRES}/tests/Context/bootstrap.php',
     f'{CORE}/tests/Context/Fixture.php',
     f'{POSTGRES}/composer.json', f'{POSTGRES}/composer.lock', f'{POSTGRES}/vendor/autoload.php',
     'packages/agentools-laravel/src/Managed/Composition.php', 'packages/agentools-laravel/composer.json')))
SUITES[('usage-reservations', 'test')] = Suite('P9.3', 'contract-scripted', 'contract-scripted', USAGE_RESERVATIONS_CASES,
    (sys.executable, '-B', f'{POSTGRES}/tests/Usage/run.py'), USAGE_RESERVATIONS_INPUTS)
SUITES[('usage-reservations', 'pg-test')] = Suite('P9.3-pg', 'isolated-postgres', 'isolated-postgres', USAGE_RESERVATIONS_PG_CASES,
    (sys.executable, '-B', f'{POSTGRES}/tests/Usage/run.py', '--postgres'), USAGE_RESERVATIONS_INPUTS, requires_pg=True)


CACHE_MANAGER_CASES = (
    'CM-031', 'cache.closed-key-and-kind', 'cache.personal-retention', 'CM-034', 'cache.measured-roi',
    'CM-032', 'CM-033', 'V2-P9-4-REVOKE-POS', 'V2-P9-4-MISS-POS', 'cache.hit-current-acl-budget',
    'cache.mid-use-revoke', 'V2ALL-CACHE-FAULT', 'V2ALL-CACHE-POSITIVE', 'V2ALL-CACHE-NEGATIVE',
    'cache.warm-unknown-no-release', 'cache.warm-accounting-replay', 'cache.refresh-and-idle-retire',
)
CACHE_MANAGER_PG_CASES = (
    'cache.pg-scope-revoke', 'cache.pg-warm-accounting', 'cache.pg-warm-rollback', 'cache.pg-revoked-inflight',
    'cache.pg-deletion-replay', 'cache.pg-competing-quota', 'cache.pg-competing-use-revoke',
    'cache.pg-revoke-crash-windows', 'cache.pg-delete-ack-crash', 'cache.pg-warm-reply-crash', 'cache.pg-stale-warm-result',
)
CACHE_MANAGER_INPUTS = tuple(dict.fromkeys(USAGE_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Models/Cache', ROOT / 'packages/agentools-model-adapter/src/Cache',
        ROOT / 'packages/agentools-model-adapter/tests/Cache', ROOT / POSTGRES / 'src/Cache',
        ROOT / POSTGRES / 'src/Usage', ROOT / 'fixtures/platform/v1/cache',
    ) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.md', '.sql'}
) + (f'{CORE}/src/Models/Time.php', f'{CORE}/src/Models/ModelUsage.php', f'{CORE}/src/Models/CapabilityEvidence.php',
     f'{CORE}/src/Models/Support.php', f'{CORE}/src/Models/Evidence.php',
     'packages/agentools-model-adapter/src/SystemClock.php', 'packages/agentools-model-adapter/composer.json',
     'packages/agentools-model-adapter/composer.lock', 'packages/agentools-model-adapter/vendor/autoload.php',
     'packages/agentools-model-adapter/phpstan.neon', f'{CORE}/phpstan.neon', f'{POSTGRES}/phpstan.neon',
     f'{POSTGRES}/src/Runtime/Connection.php', f'{POSTGRES}/src/Runtime/StorageFailure.php', f'{POSTGRES}/tests/IsolatedDatabase.php',
     f'{POSTGRES}/composer.json', f'{POSTGRES}/composer.lock')))
SUITES[('cache-manager', 'test')] = Suite('P9.4', 'contract-scripted', 'contract-scripted', CACHE_MANAGER_CASES,
    (sys.executable, '-B', 'packages/agentools-model-adapter/tests/Cache/run.py'), CACHE_MANAGER_INPUTS)
SUITES[('cache-manager', 'pg-test')] = Suite('P9.4-pg', 'isolated-postgres', 'isolated-postgres', CACHE_MANAGER_PG_CASES,
    (sys.executable, '-B', 'packages/agentools-model-adapter/tests/Cache/run.py', '--postgres'), CACHE_MANAGER_INPUTS, requires_pg=True)


PROVIDER_FALLBACK_CASES = (
    'fallback.before-disclosure-discards-buffer', 'fallback.after-disclosure-requires-segment',
    'fallback.segment-forgery-denied', 'fallback.context-and-actions-persist',
    'fallback.unknown-effect-before-continuation', 'fallback.policy-before-cost-no-escape',
    'fallback.source-mismatch-and-late-packets',
    'cost.fallback-resource-facts-business-ref', 'cost.unknown-correction-replaces-once',
    'cost.sdk-observer-is-telemetry-only', 'cost.cache-miss-does-not-bypass-budget',
    'provider.generate-wire-usage', 'provider.stream-and-tools-proposal-only', 'provider.structured-result',
    'provider.missing-terminal-is-partial', 'provider.cancel-and-errors', 'provider.optional-cache-continuation-attachments',
    'probe.offline-and-unknown-price-fail-closed', 'probe.explicit-data-credential-and-current-owner',
    'probe.calls-and-cumulative-spend-bounded', 'probe.unknown-no-blind-resend', 'probe.partial-stream-remains-unknown',
)
PROVIDER_FALLBACK_INPUTS = tuple(dict.fromkeys(ROUTING_INPUTS + SDK_ADAPTER_INPUTS + CACHE_MANAGER_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / 'conformance/platform/provider-fallback', ROOT / TESTING / 'src/Providers',
    ) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.md'}
) + ('docs/platform/providers.md', f'{TESTING}/composer.lock', f'{TESTING}/phpstan.neon')))
SUITES[('provider-fallback', 'test')] = Suite('P9.5', 'contract-scripted', 'contract-scripted', PROVIDER_FALLBACK_CASES,
    (sys.executable, '-B', 'conformance/platform/provider-fallback/run.py'), PROVIDER_FALLBACK_INPUTS)

HOST_CASES = (
    'ECO-HOST-001', 'ECO-HOST-002', 'host.source-tenant-instance-kind-scope',
    'host.trusted-handle-no-browser-authority', 'host.envelope-required-fields',
    'host.worker-tenant-reset', 'host.slice-tenant-purpose-expiry-revoke',
    'host.current-context-revision-and-identity', 'host.forged-envelope-refused',
    'host.allowed-read-model-projection', 'host.confirmable-write-result-ref',
    'host.write-current-owner-race', 'host.confirmation-binding-expiry-fence',
    'host.owner-revision-and-hash-conflict', 'host.unknown-lookup-lost-ack',
    'host.report-tenant-and-namespace-binding',
    'V2ALL-OWNERSHIP-POSITIVE', 'V2ALL-OWNERSHIP-NEGATIVE', 'V2ALL-OWNERSHIP-FAULT',
    'host.no-direct-owner-access',
)
HOST_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src', ROOT / 'php/src/Schema',
        ROOT / 'packages/agentools-laravel/src/Host', ROOT / 'spec/platform/v1/host',
        ROOT / 'examples/platform/edufy-host', ROOT / 'conformance/platform/host',
    ) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php', '.py', '.json', '.md', '.neon'}
) + (
    'packages/agentools-laravel/src/Managed/Input.php', 'packages/agentools-laravel/src/Managed/Entry.php',
    'packages/agentools-laravel/composer.json', 'packages/agentools-laravel/composer.lock',
    f'{CORE}/composer.json', f'{CORE}/composer.lock', f'{CORE}/vendor/autoload.php',
    f'{CORE}/tests/Common/bootstrap.php', f'{CORE}/tests/Common/Harness.php',
    f'{CORE}/tests/Common/JcsAdapter.php', f'{CORE}/tests/Common/TestHost.php',
    'php/vendor/autoload.php', 'php/composer.lock', 'tools/platform_gate.py',
    'tools/tests/test_platform_gate.py', f'{TESTING}/bin/php-tokens.php',
)))
SUITES[('host', 'test')] = Suite('P10.1', 'contract-scripted', 'contract-scripted', HOST_CASES,
    (sys.executable, '-B', 'conformance/platform/host/run.py'), HOST_INPUTS)
SUITES[('edufy-host', 'test')] = SUITES[('host', 'test')]
SUITES[('host-edufy', 'test')] = SUITES[('host', 'test')]

CHATOM_CASES = (
    'chatom.history-support-editor', 'chatom.history-bounds-order-tenant',
    'chatom.history-revoke-expiry-exact-version', 'chatom.history-unavailable-not-empty-success',
    'chatom.receipt-unknown-and-binding', 'chatom.production-no-dev',
)
CHATOM_PG_CASES = (
    'DEL-001', 'ECO-DEL-001', 'ECO-DEL-002', 'ECO-DEL-003', 'ECO-DEL-004', 'ECO-DEL-005',
    'V2-P10-2-DELIVERY-POS', 'V2ALL-DELIVERY-POSITIVE', 'V2ALL-DELIVERY-NEGATIVE', 'V2ALL-DELIVERY-FAULT',
    'chatom.owner-permission-race', 'chatom.tenant-isolation', 'chatom.cancel-retains-unknown',
    'chatom.incoming-conflict-before-enqueue', 'chatom.competing-claims', 'chatom.crash-commit-before-dispatch', 'chatom.crash-destination-before-receipt',
    'chatom.invalidation-partial-recovery', 'chatom.expiry-purge-keeps-owner-transcript', 'chatom.response-version-new-key',
)
CHATOM_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / 'extensions/chatom/src', ROOT / 'extensions/chatom/tests',
        ROOT / 'examples/platform/chatom', ROOT / 'fixtures/platform/v1/delivery',
        ROOT / 'packages/agentools-chatom', ROOT / CORE / 'src',
        ROOT / POSTGRES / 'src', ROOT / POSTGRES / 'migrations', ROOT / 'php/src',
    ) for p in sorted(base.rglob('*')) if p.is_file()
    and 'vendor' not in p.relative_to(ROOT).parts
    and p.suffix in {'.php', '.py', '.json', '.md', '.sql', '.lock'}
) + (
    'extensions/chatom/composer.json', 'extensions/chatom/composer.lock', 'extensions/chatom/vendor/autoload.php',
    'extensions/chatom/phpstan.neon', 'extensions/chatom/README.md', 'extensions/chatom/contract.md',
    'extensions/chatom/runbook.md', 'extensions/chatom/pin-matrix.json',
    'extensions/chatom/tests/no-dev/vendor/autoload.php',
    'extensions/chatom/tests/no-dev/.gitignore',
    f'{POSTGRES}/tests/IsolatedDatabase.php', f'{CORE}/tests/Common/Harness.php',
    f'{CORE}/tests/Common/JcsAdapter.php', f'{CORE}/composer.json', f'{POSTGRES}/composer.json',
    'php/composer.json', 'php/composer.lock', 'tools/platform_gate.py', 'tools/tests/test_platform_gate.py',
)))
for _chatom_name in ('chatom', 'chatom-delivery', 'host-chatom'):
    SUITES[(_chatom_name, 'test')] = Suite('P10.2', 'contract-scripted', 'contract-scripted', CHATOM_CASES,
        (sys.executable, '-B', 'extensions/chatom/tests/run.py'), CHATOM_INPUTS)
    SUITES[(_chatom_name, 'pg-test')] = Suite('P10.2-pg', 'isolated-postgres', 'isolated-postgres', CHATOM_PG_CASES,
        (sys.executable, '-B', 'extensions/chatom/tests/run.py', '--pg'), CHATOM_INPUTS, True)


SECOND_HOST_CONTRACT_CASES = ('support.context', 'support.tool-intersection', 'support.untrusted-handle')
SECOND_HOST_PG_CASES = ('support.runtime-delivery', 'support.current-guard', 'support.delivery-lost-ack', 'support.owner-revoke', 'support.invalid-context-provider', 'support.source-conflict', 'support.direct-confirmed-action')
SECOND_HOST_CASES = SECOND_HOST_CONTRACT_CASES + SECOND_HOST_PG_CASES
SECOND_HOST_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / 'examples/platform/support-host', ROOT / 'conformance/platform/second-host',
        ROOT / 'examples/platform/read-journey', ROOT / 'examples/platform/confirmed-write',
        ROOT / 'examples/platform/direct-action', ROOT / CORE / 'src', ROOT / POSTGRES / 'src',
        ROOT / POSTGRES / 'migrations', ROOT / 'packages/agentools-laravel/src/Host',
    ) for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.parts
      and p.suffix in {'.php', '.py', '.json', '.md', '.sql', '.xml'}
) + ('packages/agentools-testing/tests/Journeys/SecondHostTest.php', 'packages/agentools-testing/phpstan.neon',
     'packages/agentools-core/tests/Actions/Confirmation/Fixture.php',
     'packages/agentools-core/tests/Common/Harness.php', 'packages/agentools-core/tests/Common/JcsAdapter.php',
     'packages/agentools-postgres/tests/IsolatedDatabase.php', 'packages/agentools-postgres/tests/run.py',
     'conformance/platform/read-journey/host.sql', 'tools/storage_chaos.py', 'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('second-host', 'test')] = Suite('P10.3', 'contract-scripted', 'contract-scripted', SECOND_HOST_CASES,
    (sys.executable, '-B', 'conformance/platform/second-host/run.py'), SECOND_HOST_INPUTS)


HOST_E2E_CASES = ('host-e2e.school-duplicate', 'host-e2e.school-revoke', 'host-e2e.school-revoke-preflight', 'host-e2e.support-conflict',
                  'host-e2e.lost-receipt', 'host-e2e.delivery-outage')
HOST_E2E_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT/'conformance/platform/host-e2e', ROOT/'docs/platform/integrations',
        ROOT/'examples/platform/support-host', ROOT/'examples/platform/read-journey',
        ROOT/CORE/'src', ROOT/POSTGRES/'src', ROOT/POSTGRES/'migrations',
        ROOT/'packages/agentools-laravel/src/Host',
    ) for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.parts
      and p.suffix in {'.php','.py','.json','.md','.sql','.xml'}
) + ('packages/agentools-laravel/tests/Journeys/HostE2eTest.php',
     'packages/agentools-postgres/tests/IsolatedDatabase.php', 'packages/agentools-postgres/tests/run.py',
     'conformance/platform/read-journey/host.sql', 'tools/storage_chaos.py',
     'spec/platform/v1/host/README.md', 'examples/platform/edufy-host/contract.json',
     'examples/platform/edufy-host/change-request.json', 'extensions/chatom/contract.md',
     'extensions/chatom/pin-matrix.json',
     'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('host-e2e', 'e2e')] = Suite('P10.4', 'local-host-e2e', 'local-host-e2e', HOST_E2E_CASES,
    (sys.executable, '-B', 'conformance/platform/host-e2e/run.py'), HOST_E2E_INPUTS, True)


VOICE_INGRESS_CONTRACT_CASES = (
    'voice.shared-fixture', 'voice.final-trusted-input', 'voice.interim-no-input',
    'voice.reconnect-source-stable', 'voice.stale-media-fence', 'voice.stale-context-revision',
    'voice.final-revision-is-content', 'voice.current-permissions-and-consent',
    'voice.tenant-session-instance-isolation', 'voice.text-fallback-continuity',
    'voice.same-context-compiler', 'voice.envelope-tamper', 'voice.invalid-transcript',
)
VOICE_INGRESS_SCHEMA_CASES = ('voice.schema-sample', 'voice.schema-interim-missing',
                             'voice.schema-untrusted-authority', 'voice.schema-invalid-values')
VOICE_INGRESS_PG_CASES = ('voice.pg-reconnect-lost-receipt', 'voice.pg-final-hash-revision-conflict',
                         'voice.pg-interim-stale-no-enqueue', 'voice.pg-text-fallback-barge-in',
                         'voice.pg-tenant-session-isolation')
VOICE_INGRESS_CASES = (VOICE_INGRESS_CONTRACT_CASES + VOICE_INGRESS_SCHEMA_CASES
                       + ('voice.production-no-dev',) + VOICE_INGRESS_PG_CASES
                       + ('voice.composer-valid', 'voice.static-analysis'))
VOICE_INGRESS_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT/'extensions/loqum/src/Ingress', ROOT/'extensions/loqum/tests', ROOT/'fixtures/platform/v1/voice',
        ROOT/CORE/'src', ROOT/POSTGRES/'src', ROOT/POSTGRES/'migrations',
        ROOT/POSTGRES/'vendor/agentools/platform-core/src', ROOT/POSTGRES/'vendor/agentools/sdk/src',
    ) for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.relative_to(base).parts
      and p.suffix in {'.php','.py','.json','.md','.sql','.xml'}
) + ('extensions/loqum/composer.json', 'extensions/loqum/composer.lock', 'extensions/loqum/phpstan.neon',
     'extensions/loqum/.gitignore', 'extensions/loqum/vendor/autoload.php',
     'extensions/loqum/vendor/bin/phpstan', 'extensions/loqum/tests/no-dev/vendor/autoload.php',
     f'{CORE}/tests/Common/Harness.php', f'{CORE}/tests/Common/JcsAdapter.php',
     f'{POSTGRES}/tests/IsolatedDatabase.php', f'{POSTGRES}/tests/Runtime/Fixture.php',
     f'{POSTGRES}/tests/Runtime/TestHost.php', f'{POSTGRES}/tests/Runtime/TestRevisions.php',
     f'{POSTGRES}/tests/Runtime/TestCanonicalJson.php', f'{POSTGRES}/tests/run.py',
     f'{POSTGRES}/vendor/autoload.php', f'{POSTGRES}/vendor/bin/phpunit', f'{POSTGRES}/composer.lock',
     'tools/storage_chaos.py', 'tools/platform_gate.py', 'tools/tests/test_platform_gate.py')))
SUITES[('voice-ingress','test')] = Suite('P11.1','contract-scripted','contract-scripted',VOICE_INGRESS_CASES,
    (sys.executable,'-B','extensions/loqum/tests/suite.py'),VOICE_INGRESS_INPUTS)


VOICE_DELIVERY_PG_CASES = (
    'voice-delivery.independent-portions','voice-delivery.suffix-retry','voice-delivery.lost-receipt',
    'voice-delivery.unknown-reconnect','voice-delivery.barge-in','voice-delivery.disclosure-revoke',
    'voice-delivery.tts-outage-text','voice-delivery.identity-schema-conflicts','voice-delivery.command-guards',
    'voice-delivery.receipt-binding','voice-delivery.sigkill-before-playback','voice-delivery.exact-approval',
    'voice-delivery.approval-denials','voice-delivery.effect-survives-interruption','voice-delivery.owner-revoke-after-approval',
)
VOICE_DELIVERY_CASES = VOICE_DELIVERY_PG_CASES + (
    'voice-delivery.core-disclosure','voice-delivery.schema','voice-delivery.production-no-dev',
    'voice-delivery.composer-valid','voice-delivery.static-analysis',
)
VOICE_DELIVERY_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT/'extensions/loqum/src', ROOT/'extensions/loqum/tests/Delivery',ROOT/'fixtures/platform/v1/voice',ROOT/'php/src',
        ROOT/CORE/'src',ROOT/POSTGRES/'src',ROOT/POSTGRES/'migrations',
        ROOT/CORE/'tests/Common',ROOT/CORE/'tests/Context',ROOT/CORE/'tests/Security',ROOT/CORE/'tests/Actions/Confirmation',
        ROOT/'examples/platform/confirmed-write',ROOT/'examples/platform/direct-action',
    ) for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.relative_to(base).parts
      and p.suffix in {'.php','.py','.json','.md','.sql','.xml'}
) + ('extensions/loqum/composer.json','extensions/loqum/composer.lock','extensions/loqum/phpstan.neon',
     'extensions/loqum/tests/bootstrap.php','extensions/loqum/tests/Fixture.php',
     'extensions/loqum/vendor/autoload.php','extensions/loqum/vendor/bin/phpstan',
     'extensions/loqum/tests/Delivery/no-dev/vendor/autoload.php',
     f'{POSTGRES}/tests/IsolatedDatabase.php',f'{POSTGRES}/tests/Runtime/Fixture.php',
     f'{POSTGRES}/tests/Runtime/TestHost.php',f'{POSTGRES}/tests/Runtime/TestRevisions.php',
     f'{POSTGRES}/tests/Runtime/TestCanonicalJson.php',f'{POSTGRES}/tests/run.py',
     f'{POSTGRES}/vendor/autoload.php',f'{POSTGRES}/vendor/bin/phpunit',f'{POSTGRES}/composer.lock',
     'php/composer.lock','tools/storage_chaos.py','tools/platform_gate.py','tools/tests/test_platform_gate.py')))
SUITES[('voice-delivery','test')] = Suite('P11.2','contract-scripted','contract-scripted',VOICE_DELIVERY_CASES,
    (sys.executable,'-B','extensions/loqum/tests/Delivery/suite.py'),VOICE_DELIVERY_INPUTS)


VOICE_JOURNEY_CASES = (
    'ECO-VOICE-001','ECO-VOICE-002','ECO-VOICE-003','ECO-VOICE-004','ECO-VOICE-005','ECO-VOICE-006','ECO-VOICE-007','ECO-VOICE-008',
    'V2-P11-3-VOICE-POS','V2-P11-3-CONSENT-POS','V2ALL-VOICE-POSITIVE','V2ALL-VOICE-NEGATIVE','V2ALL-VOICE-FAULT',
    'voice-journey.lost-tts-receipt','voice-journey.provider-after-disclosure','voice-journey.state-checkpoints',
    'voice-journey.worker-tenant-reset','voice-journey.runnable-example','voice-journey.final-payload-conflict',
)
VOICE_JOURNEY_INPUTS = tuple(dict.fromkeys(tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT/'examples/platform/voice',ROOT/'conformance/platform/voice',ROOT/'extensions/loqum/src',
        ROOT/'extensions/loqum/tests/Journey',ROOT/CORE/'src',ROOT/POSTGRES/'src',ROOT/POSTGRES/'migrations',ROOT/'php/src',
        ROOT/'examples/platform/confirmed-write',ROOT/'examples/platform/direct-action',
    ) for p in sorted(base.rglob('*')) if p.is_file() and 'vendor' not in p.relative_to(base).parts
      and p.suffix in {'.php','.py','.json','.md','.sql','.xml','.neon'}
) + ('extensions/loqum/composer.json','extensions/loqum/composer.lock','extensions/loqum/vendor/autoload.php',
     'extensions/loqum/vendor/bin/phpstan',f'{CORE}/tests/Actions/Confirmation/Fixture.php',
     f'{POSTGRES}/tests/IsolatedDatabase.php',f'{POSTGRES}/tests/run.py',f'{POSTGRES}/vendor/autoload.php',
     f'{POSTGRES}/vendor/bin/phpunit',f'{POSTGRES}/composer.lock','php/composer.lock',
     'tools/storage_chaos.py','tools/platform_gate.py','tools/tests/test_platform_gate.py')))
SUITES[('voice-journey','e2e')] = Suite('P11.3','local-host-e2e','local-host-e2e',VOICE_JOURNEY_CASES,
    (sys.executable,'-B','conformance/platform/voice/run.py'),VOICE_JOURNEY_INPUTS,True)


# P12.1: the evaluator is technical tooling in the development-only testing
# package. Existing runtime/fault runners retain their original gates and tiers.
EVALUATION_CASES = (
    'evaluation.schema-examples', 'evaluation.closed-schema-negative',
    'evaluation.teacher-technical-golden', 'evaluation.second-host-technical-golden',
    'evaluation.domain-insufficient-evidence', 'evaluation.deterministic-scorers',
    'evaluation.hard-invariant-faults', 'evaluation.unknown-not-success',
    'evaluation.not-run-distinct', 'evaluation.tenant-audience-access-negative',
    'evaluation.budget-negative-and-unknown', 'evaluation.case-hash-conflict',
    'evaluation.run-hash-conflict', 'evaluation.tenant-scoped-immutable-identity',
    'evaluation.registry-empty-missing-duplicate-negative', 'evaluation.existing-conformance-exact-links',
    'evaluation.integration-missing-skip-fail-negative', 'evaluation.required-tuple-invalidation',
    'evaluation.qualification-case-set-negative', 'evaluation.result-config-hash-negative',
    'evaluation.fake-production-claim-negative', 'evaluation.no-invented-domain-threshold',
    'evaluation.integration-receipt-required', 'evaluation.local-reference-escape-negative',
    'evaluation.missing-scorer-dependency-negative',
)
EVALUATION_INPUTS = tuple(str(p.relative_to(ROOT)) for base in (
    ROOT / TESTING / 'src/Evaluation', ROOT / TESTING / 'tests/Evaluation',
    ROOT / 'spec/platform/v1/evaluation', ROOT / 'fixtures/platform/v1/evaluation',
) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.py', '.json', '.md'}) + (
    'tools/platform_gate.py', 'tools/tests/test_platform_gate.py',
)
SUITES[('evaluation-harness', 'test')] = Suite('P12.1', 'contract-scripted', 'contract-scripted',
    EVALUATION_CASES, (sys.executable, '-B', f'{TESTING}/src/Evaluation/runner.py',
                       '--suite', 'evaluation-harness'), EVALUATION_INPUTS)
EVALUATION_INTEGRATIONS = {
    'technical-golden': (('knowledge-journey', 'e2e'), ('read-journey', 'e2e'), ('second-host', 'test')),
    'evaluation-faults': (('provider-fallback', 'test'), ('voice-journey', 'e2e')),
}
for _name, _keys in EVALUATION_INTEGRATIONS.items():
    _cases = tuple(key[0] + ':' + case for key in _keys for case in SUITES[key].cases)
    _inputs = tuple(dict.fromkeys(EVALUATION_INPUTS + tuple(p for key in _keys for p in SUITES[key].dependencies)))
    SUITES[(_name, 'e2e')] = Suite('P12.1-' + _name, 'local-host-e2e', 'local-host-e2e', _cases,
        (sys.executable, '-B', f'{TESTING}/src/Evaluation/runner.py', '--suite', _name), _inputs, True)


# P12.2: qualification/pinning policies have no provider execution loop.
QUALIFICATION_PARTS = {
    'evidence': ('ECO-EVAL-001','ECO-EVAL-002','ECO-EVAL-003','ECO-EVAL-005','ECO-EVAL-006',
                 'V2-P12-2-TUPLE-POS','V2-P12-2-TIER-POS','V2ALL-EVAL-NEGATIVE'),
    'routing': ('ECO-EVAL-004','qualification.health-does-not-grant',
                'qualification.current-policy-and-revoke','V2ALL-EVAL-FAULT'),
    'canary': ('V2ALL-EVAL-POSITIVE','qualification.canary-measured-owner-bounds',
               'qualification.stable-cohort-and-zero-admission'),
}
QUALIFICATION_PG_CASES = tuple('qualification-pg.' + name for name in (
    'testSnapshotReplayConflictAndRestart','testImmutableSqlAndInsufficientEvidence',
    'testPublicationPinsCanaryRollback','testEmergencyAndRevokeOverPins',
    'testBoundedCompetingAssignments','testAuditFailureRollsBack',
    'testAuthorizationScopeCasAndCompatibility','testSchemaRevisionConflict',
))
QUALIFICATION_CASES = tuple(case for group in QUALIFICATION_PARTS.values() for case in group) + QUALIFICATION_PG_CASES + ('qualification.static-analysis',)
QUALIFICATION_INPUTS = tuple(dict.fromkeys(ROUTING_INPUTS + EVALUATION_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (
        ROOT / CORE / 'src/Models/Qualification', ROOT / CORE / 'tests/Models/Qualification',
        ROOT / POSTGRES / 'src/Qualification', ROOT / POSTGRES / 'tests/Qualification',
        ROOT / 'fixtures/platform/v1/qualification',
    ) for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.sql','.json','.md','.xml','.neon'}
) + (f'{POSTGRES}/vendor/bin/phpstan', f'{POSTGRES}/phpstan.neon', f'{CORE}/phpstan.neon')))
SUITES[('qualification-rollout','test')] = Suite('P12.2','contract-scripted','contract-scripted',QUALIFICATION_CASES,
    (sys.executable,'-B',f'{CORE}/tests/Models/Qualification/run.py'),QUALIFICATION_INPUTS)
SUITES[('qualification-rollout','pg-test')] = Suite('P12.2-pg','isolated-postgres','isolated-postgres',QUALIFICATION_PG_CASES,
    (sys.executable,'-B',f'{POSTGRES}/tests/Qualification/run.py'),QUALIFICATION_INPUTS,True)


# P12.3: one bounded facade, existing scheduler and existing accounting dispatcher.
OPERATIONS_CONTRACT_CASES = ('operations.command-scope-bounds','operations.redacted-findings','operations.opt-in-command-definitions')
OPERATIONS_FACADE_CASES = ('CM-049','V2ALL-OPS-POSITIVE','CM-050','V2ALL-OPS-NEGATIVE',
    'operations.stable-delivery','V2ALL-OPS-FAULT','operations.invocation-proposal-identities',
    'operations.dependency-corrupt-proof','operations.usage-lost-ack','operations.usage-exact-key',
    'operations.cli-trusted-handle','operations.prune-tombstone','operations.owner-lookup-no-replay','operations.retention-holds')
OPERATIONS_CADENCE_CASES = ('CM-045','scheduler.atomic-rollback','scheduler.crash-after-commit','CM-048')
OPERATIONS_PG_CASES = OPERATIONS_FACADE_CASES + OPERATIONS_CADENCE_CASES
OPERATIONS_ANALYSIS = ['packages/agentools-laravel/vendor/bin/phpstan','analyse','-c',
    'packages/agentools-laravel/phpstan.neon','--no-progress','--memory-limit=512M',
    'packages/agentools-laravel/src/Operations','packages/agentools-laravel/src/Console',
    'packages/agentools-laravel/tests/Operations/Facade','packages/agentools-postgres/src/Usage/Dispatcher.php']
OPERATIONS_INPUTS = tuple(dict.fromkeys(LARAVEL_INPUTS + SCHEDULER_INPUTS + USAGE_RESERVATIONS_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (ROOT/'packages/agentools-laravel/src/Operations',
        ROOT/'packages/agentools-laravel/src/Console',ROOT/'packages/agentools-laravel/tests/Operations/Facade')
    for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.xml','.neon','.md'}
) + ('docs/platform/operations.md',)))
OPS_BASE = 'packages/agentools-laravel/tests/Operations/Facade'
SUITES[('operations-contract','test')] = Suite('P12.3-contract','contract-scripted','contract-scripted',OPERATIONS_CONTRACT_CASES,
    ('php',f'{OPS_BASE}/contract.php'),OPERATIONS_INPUTS)
SUITES[('operations-calendar','test')] = Suite('P12.3-calendar','unit','unit',SCHEDULER_CALENDAR_CASES,
    (sys.executable,'-B','extensions/scheduler/tests/calendar.py'),OPERATIONS_INPUTS)
SUITES[('operations-facade','pg-test')] = Suite('P12.3-facade','isolated-postgres','isolated-postgres',OPERATIONS_FACADE_CASES,
    (sys.executable,'-B',f'{OPS_BASE}/run.py'),OPERATIONS_INPUTS,True)
SUITES[('operations-cadence','pg-test')] = Suite('P12.3-cadence','isolated-postgres','isolated-postgres',OPERATIONS_CADENCE_CASES,
    (sys.executable,'-B','extensions/scheduler/tests/run.py','--filter',
        'testConcurrentScanners|testAtomicRollbackAndPendingBatchRecovery|testCrashAfterCommit|testCurrentRevocationBeforeDispatch'),OPERATIONS_INPUTS,True)
SUITES[('operations','pg-test')] = Suite('P12.3-pg','isolated-postgres','isolated-postgres',OPERATIONS_PG_CASES,
    (sys.executable,'-B',f'{OPS_BASE}/pg.py'),OPERATIONS_INPUTS,True)
SUITES[('operations','test')] = Suite('P12.3','contract-scripted','contract-scripted',
    OPERATIONS_CONTRACT_CASES + SCHEDULER_CALENDAR_CASES + OPERATIONS_PG_CASES + ('operations.static-analysis','operations.composer-valid'),
    (sys.executable,'-B',f'{OPS_BASE}/suite.py'),OPERATIONS_INPUTS)


# P12.4: closed metadata projection, bounded metrics, separate encrypted diagnostics and real guard regression.
OBSERVABILITY_CONTRACT_CASES = ('CM-044','V2ALL-PRIVACY-POSITIVE','observability.bounded-metrics',
    'V2ALL-PRIVACY-NEGATIVE','V2ALL-PRIVACY-FAULT')
OBSERVABILITY_PG_CASES = ('privacy.encrypted-diagnostic-access','privacy.audit-resolver-revoke',
    'privacy.expiry-tenant-integrity','privacy.diagnostic-reset-quarantine','privacy.retention-tombstone','privacy.audit-durable-graph',
    'privacy.managed-trace-entry-parity','privacy.managed-failure-policy','privacy.managed-worker-reset','privacy.operations-readonly-audit','privacy.unknown-outcomes')
OBSERVABILITY_CRON_CASES = ('CM-048','scheduler.common-guards-source-binding')
OBSERVABILITY_VOICE_CASES = ('voice-delivery.disclosure-revoke',)
OBSERVABILITY_ANALYSIS = ['packages/agentools-laravel/vendor/bin/phpstan','analyse','-c',
    'packages/agentools-laravel/phpstan.neon','--no-progress','--memory-limit=512M',
    'packages/agentools-core/src/Observability','packages/agentools-core/tests/Observability',
    'packages/agentools-laravel/src/Observability','packages/agentools-laravel/tests/Observability',
    'packages/agentools-laravel/src/Managed/Composition.php','packages/agentools-laravel/src/Managed/HostBindings.php',
    'packages/agentools-laravel/src/Operations/Facade.php']
OBSERVABILITY_INPUTS = tuple(dict.fromkeys(OPERATIONS_INPUTS + SECURITY_INPUTS + VOICE_DELIVERY_INPUTS + tuple(
    str(p.relative_to(ROOT)) for base in (ROOT/CORE/'src/Observability',ROOT/CORE/'tests/Observability',
        ROOT/'packages/agentools-laravel/src/Observability',ROOT/'packages/agentools-laravel/tests/Observability',
        ROOT/'fixtures/platform/v1/redaction') for p in sorted(base.rglob('*'))
    if p.is_file() and p.suffix in {'.php','.py','.json','.xml','.md'}
) + ('docs/platform/security.md',)))
OBS_BASE = 'packages/agentools-laravel/tests/Observability'
SUITES[('observability-contract','test')] = Suite('P12.4-contract','contract-scripted','contract-scripted',OBSERVABILITY_CONTRACT_CASES,
    ('php',f'{CORE}/tests/Observability/contract.php'),OBSERVABILITY_INPUTS)
SUITES[('observability-durable','pg-test')] = Suite('P12.4-durable','isolated-postgres','isolated-postgres',OBSERVABILITY_PG_CASES,
    (sys.executable,'-B',f'{OBS_BASE}/run.py'),OBSERVABILITY_INPUTS,True)
SUITES[('observability-cron','pg-test')] = Suite('P12.4-cron','isolated-postgres','isolated-postgres',OBSERVABILITY_CRON_CASES,
    (sys.executable,'-B','extensions/scheduler/tests/run.py','--filter','testCurrentRevocationBeforeDispatch|testCommonGuardDenialAndSourceSubstitution'),OBSERVABILITY_INPUTS,True)
SUITES[('observability-voice','pg-test')] = Suite('P12.4-voice','isolated-postgres','isolated-postgres',OBSERVABILITY_VOICE_CASES,
    (sys.executable,'-B','extensions/loqum/tests/Delivery/pg.py','--filter','testDisclosureAndRevoke'),OBSERVABILITY_INPUTS,True)
SUITES[('observability-accounting','pg-test')] = Suite('P12.4-accounting','isolated-postgres','isolated-postgres',('managed.accounting-failure',),
    (sys.executable,'-B','packages/agentools-laravel/tests/Composition/run.py','--filter','testAccountingFailureIsNotOptionalTelemetry'),OBSERVABILITY_INPUTS,True)
SUITES[('observability-security','test')] = Suite('P12.4','contract-scripted','contract-scripted',
    OBSERVABILITY_CONTRACT_CASES + OBSERVABILITY_PG_CASES + OBSERVABILITY_CRON_CASES + OBSERVABILITY_VOICE_CASES
        + ('managed.accounting-failure','observability.static-analysis','observability.composer-valid'),
    (sys.executable,'-B',f'{OBS_BASE}/suite.py'),OBSERVABILITY_INPUTS)

# P12.5: measurement and incident handoff only, never a live provider call.
BASELINE_CASES = ('baseline.recorded-measurements','baseline.actual-golden-fault',
    'incidents.required-playbooks','live.complete-is-not-qualified','live.missing-bindings-refused',
    'live.data-spend-fail-closed','live.stale-incomplete-evidence-refused','live.offline-no-io')
BASELINE_INPUTS = tuple(dict.fromkeys(EVALUATION_INPUTS + (
    'docs/platform/baseline.json','docs/platform/incidents/README.md',
    'conformance/platform/live/baseline.py','conformance/platform/live/probe.py',
    'conformance/platform/live/test_baseline.py','conformance/platform/live/run.py',
    'fixtures/platform/v1/qualification/live-probe-recipe.json',)))
SUITES[('baseline-incidents','test')] = Suite('P12.5','contract-scripted','contract-scripted',BASELINE_CASES,
    (sys.executable,'-B','conformance/platform/live/run.py'),BASELINE_INPUTS)

# P13.1: clean target bootstrap, real crash matrix and isolated backup restore.
BOOTSTRAP_RESTORE_CASES = ('bootstrap.restore-projection-usage',)
BOOTSTRAP_RECOVERY_CASES = ('CUT-001','V2ALL-MIGRATION-POSITIVE',
    'V2ALL-MIGRATION-NEGATIVE','V2ALL-MIGRATION-FAULT')
BOOTSTRAP_RECOVERY_INPUTS = tuple(dict.fromkeys(READ_JOURNEY_INPUTS + WRITE_RECOVERY_INPUTS
    + USAGE_RESERVATIONS_INPUTS + tuple(str(p.relative_to(ROOT)) for base in (
        ROOT/'packages/agentools-postgres/tests/Recovery', ROOT/'conformance/platform/bootstrap-recovery')
        for p in sorted(base.rglob('*')) if p.is_file() and p.suffix in {'.php','.py','.xml','.md'})
    + ('docs/platform/bootstrap.json','docs/platform/bootstrap-recovery.md')))
SUITES[('bootstrap-restore','pg-test')] = Suite('P13.1-restore','isolated-postgres','isolated-postgres',
    BOOTSTRAP_RESTORE_CASES,(sys.executable,'-B','conformance/platform/bootstrap-recovery/pg.py'),
    BOOTSTRAP_RECOVERY_INPUTS,requires_pg=True)
SUITES[('bootstrap-recovery','e2e')] = Suite('P13.1','local-host-e2e','local-host-e2e',
    BOOTSTRAP_RECOVERY_CASES,(sys.executable,'-B','conformance/platform/bootstrap-recovery/run.py'),
    BOOTSTRAP_RECOVERY_INPUTS,requires_pg=True)


class GateError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise GateError(message)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def utc():
    return datetime.now(timezone.utc).isoformat()


def tool_version(command):
    try:
        process = subprocess.run(command, text=True, capture_output=True, timeout=10)
        return {"command": command, "exit": process.returncode,
                "version": process.stdout.splitlines()[0] if process.stdout else process.stderr.strip()}
    except (OSError, subprocess.TimeoutExpired) as error:
        return {"command": command, "unavailable": str(error)}


def hashes(root: Path, paths):
    result = {}
    for path in paths:
        file = root / path
        require(file.is_file(), f"missing dependency: {path}")
        result[path] = digest(file.read_bytes())
    return result


def validate_report(spec: Suite, report):
    require(spec.tier in TIERS, "unregistered evidence tier")
    require(spec.cases and len(set(spec.cases)) == len(spec.cases), "empty/duplicate required case set")
    require(isinstance(report, dict), "runner report must be an object")
    require(report.get("mode") == spec.mode, "wrong adapter mode")
    require(report.get("tier") == spec.tier, "wrong evidence tier")
    cases = report.get("cases")
    require(isinstance(cases, list) and cases, "zero tests or missing case report")
    require(all(isinstance(c, dict) and isinstance(c.get("id"), str) for c in cases), "malformed case report")
    ids = [c["id"] for c in cases]
    require(Counter(ids) == Counter(spec.cases), "required case set differs (missing/extra/duplicate)")
    require(all(c.get("status") == "passed" for c in cases), "failed, skipped or unknown required case")
    if spec.owner == 'P13.1':
        require(report.get('evidence_kind') == 'host_contract_harness' and report.get('live_provider') == 'not_executed',
                'bootstrap evidence scope inflated')
        require(report.get('input_hashes') == hashes(ROOT,spec.dependencies), 'bootstrap inputs missing/stale')
        require(isinstance(report.get('limitations'),list) and report['limitations'], 'bootstrap limitations missing')
        keys=(('read-journey','e2e'),('write-recovery','e2e'),('bootstrap-restore','pg-test'))
        parts=report.get('parts')
        require(isinstance(parts,list) and len(parts)==len(keys), 'bootstrap recovery partition missing')
        for key,part in zip(keys,parts):
            require(isinstance(part,dict) and part.get('key')==list(key) and part.get('ok') is True
                    and part.get('exit')==0 and part.get('dependency_hashes')==hashes(ROOT,SUITES[key].dependencies),
                    'bootstrap recovery partition failed/stale')
            validate_report(SUITES[key],part.get('report'))
            proof=part['report']['isolation']; overall=report.get('isolation',{})
            require(all(proof.get(k)==overall.get(k) for k in ('database','owner','allow_marker_verified')),
                    'bootstrap recovery isolation mismatch')
    if spec.owner == 'P12.5':
        require(report.get('evidence_kind') == 'contract-scripted' and report.get('readiness_claim') == 'technical-contract-only'
                and report.get('live_provider') == 'not_run', 'baseline evidence scope inflated')
        require(report.get('input_hashes') == hashes(ROOT,spec.dependencies), 'baseline inputs missing/stale')
        require(isinstance(report.get('limitations'),list) and bool(report['limitations']), 'baseline limitations missing')
        baseline = report.get('baseline', {})
        require(baseline == json.loads((ROOT/'docs/platform/baseline.json').read_text()), 'baseline record differs')
        require(baseline.get('tier') == 'contract-scripted' and baseline.get('provider_pricing') is None
                and baseline.get('production_slo') is None and baseline.get('live_provider') == 'not_run', 'baseline scope inflated')
        measurements = baseline.get('measurements', [])
        require(isinstance(measurements,list) and len(measurements)==2
                and {row.get('group') for row in measurements if isinstance(row,dict)} == {'golden','fault'}
                and all(type(row.get('test_count')) is int and row['test_count'] > 0
                        and type(row.get('elapsed_ms')) in (int,float) and row['elapsed_ms'] >= 0
                        and row.get('provider_calls') == row.get('measured_spend_minor') == row.get('failures') == row.get('skips') == 0
                        for row in measurements), 'invalid measured baseline')
    if spec.owner == 'P12.4':
        require(report.get('evidence_kind')=='contract-scripted' and report.get('readiness_claim')=='technical-contract-only'
            and report.get('live_provider')=='not_run', 'observability evidence/readiness inflated')
        require(report.get('input_hashes')==hashes(ROOT,spec.dependencies), 'observability inputs missing/stale')
        require(isinstance(report.get('limitations'),list) and report['limitations'], 'observability limitations missing')
        parts=report.get('parts')
        keys=(('observability-contract','test'),('observability-durable','pg-test'),('observability-cron','pg-test'),('observability-voice','pg-test'),('observability-accounting','pg-test'))
        require(isinstance(parts,list) and len(parts)==len(keys), 'observability required partition missing')
        for key,part in zip(keys,parts):
            require(isinstance(part,dict) and part.get('ok') is True and part.get('exit')==0
                and part.get('dependency_hashes')==hashes(ROOT,SUITES[key].dependencies), 'observability partition failed/stale')
            validate_report(SUITES[key],part.get('report'))
        require(report.get('static_analysis')=={'command':OBSERVABILITY_ANALYSIS,'exit':0}, 'observability analysis missing')
        require(report.get('composer')=={'command':['composer','validate','--strict','--working-dir=packages/agentools-core'],'exit':0},
            'observability dependency validation missing')
    if spec.owner == 'P12.3-cadence':
        observations=report.get('observations',{})
        concurrent=observations.get('CM-045',{})
        require(concurrent.get('workers')==2 and concurrent.get('exits')==[0,0]
            and concurrent.get('same_receipts') is True and concurrent.get('counts')==1,
            'operations concurrent scanner proof missing')
        crash=observations.get('scheduler.crash-after-commit',{})
        require(crash.get('signal')==9 and crash.get('signaled') is True and type(crash.get('worker_exit')) is int
            and crash['worker_exit']!=0 and crash.get('same_refs') is True and crash.get('counts')==1,
            'operations scanner crash/restart proof missing')
    if spec.owner in {'P12.3','P12.3-pg'}:
        require(report.get('evidence_kind') == spec.tier, 'operations evidence kind differs')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'operations inputs missing/stale')
        require(isinstance(report.get('limitations'),list) and report['limitations'], 'operations limitations missing')
        parts=report.get('parts')
        keys=(('operations-contract','test'),('operations-calendar','test'),('operations','pg-test')) if spec.owner=='P12.3' else (
            ('operations-facade','pg-test'),('operations-cadence','pg-test'))
        require(isinstance(parts,list) and len(parts)==len(keys), 'operations required partition missing')
        for key,part in zip(keys,parts):
            require(isinstance(part,dict) and part.get('ok') is True and part.get('exit')==0
                and part.get('dependency_hashes')==hashes(ROOT, SUITES[key].dependencies), 'operations partition failed/stale')
            validate_report(SUITES[key],part.get('report'))
        if spec.owner=='P12.3':
            require(report.get('readiness_claim')=='technical-contract-only' and report.get('live_provider')=='not_run',
                'operations readiness inflated')
            require(report.get('static_analysis')=={'command':OPERATIONS_ANALYSIS,'exit':0}, 'operations static analysis missing')
            require(report.get('composer')=={'command':['composer','validate','--strict','--working-dir=packages/agentools-laravel'],'exit':0},
                'operations dependency validation missing')
            baseline=report.get('baseline',{})
            require(baseline.get('measurement_kind')=='local-operations-harness' and baseline.get('executed_cases')==len(spec.cases)
                and baseline.get('live_provider_calls')==baseline.get('spend_minor')==0 and baseline.get('production_slo') is None
                and isinstance(baseline.get('elapsed_ms'),(int,float)) and baseline['elapsed_ms']>=0,
                'operations baseline missing/inflated')
    if spec.owner == 'P12.2':
        require(report.get('evidence_kind') == 'contract-scripted'
                and report.get('readiness_claim') == 'technical-contract-only'
                and report.get('live_provider') == 'not_run'
                and report.get('educational_qualification') == 'insufficient_evidence', 'qualification evidence scope inflated')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'qualification inputs missing/stale')
        require(isinstance(report.get('limitations'), list) and report['limitations'], 'qualification limitations missing')
        parts = report.get('contract_parts')
        require(isinstance(parts, list) and len(parts) == len(QUALIFICATION_PARTS), 'qualification contract partition missing')
        for part, cases_ in zip(parts, QUALIFICATION_PARTS.values()):
            validate_report(Suite('P12.2-part','contract-scripted','contract-scripted',cases_,(),()), part)
        pg = report.get('postgres', {})
        require(isinstance(pg,dict) and pg.get('ok') is True and pg.get('exit') == 0
                and pg.get('dependency_hashes') == hashes(ROOT, spec.dependencies), 'qualification durable receipt missing/stale')
        validate_report(SUITES[('qualification-rollout','pg-test')],pg.get('report'))
        analysis = report.get('static_analysis', {})
        require(isinstance(analysis,dict) and analysis.get('exit') == 0 and analysis.get('command') == [
            'packages/agentools-postgres/vendor/bin/phpstan','analyse','-c',
            'packages/agentools-postgres/tests/Qualification/phpstan.neon','--no-progress','--memory-limit=512M',
            'packages/agentools-core/src/Models/Qualification','packages/agentools-core/tests/Models/Qualification',
            'packages/agentools-postgres/src/Qualification','packages/agentools-postgres/tests/Qualification'],
            'qualification scoped static analysis missing')
    if spec.owner == 'P12.1' or spec.owner.startswith('P12.1-'):
        require(report.get('evidence_kind') == spec.tier, 'evaluation canonical evidence kind differs')
        require(report.get('readiness_claim') == 'technical-contract-only', 'evaluation impossible readiness claim')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'evaluation inputs missing/stale')
        require(isinstance(report.get('limitations'), list) and report['limitations'], 'evaluation limitations missing')
        if spec.owner == 'P12.1':
            baseline = report.get('baseline', {})
            require(baseline.get('measurement_kind') == 'local-evaluator-self-check'
                    and baseline.get('provider_calls') == 0 and baseline.get('spend_minor') == 0
                    and baseline.get('domain_decision') == 'insufficient_evidence'
                    and baseline.get('domain_scorer') == 'absent'
                    and baseline.get('executed_cases') == len(spec.cases)
                    and isinstance(baseline.get('elapsed_ms'), (int, float)) and baseline['elapsed_ms'] >= 0
                    and baseline.get('metrics') == dict.fromkeys(
                        ('quality','grounding','cost','latency','privacy','duplicate','unknown'), None),
                    'evaluation baseline/domain evidence inflated')
        else:
            name = spec.owner.removeprefix('P12.1-')
            children = report.get('integrations')
            keys = EVALUATION_INTEGRATIONS[name]
            require(isinstance(children, list) and len(children) == len(keys), 'evaluation integrations missing')
            manifest = json.loads((ROOT / f'fixtures/platform/v1/evaluation/{name}-suite.json').read_text())
            require(report.get('suite_hash') == 'sha-256:' + digest(json.dumps(
                manifest, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()), 'evaluation suite hash mismatch')
            for child, key in zip(children, keys):
                original = SUITES[key]
                require((child.get('suite'), child.get('partition')) == key and child.get('ok') is True
                        and child.get('exit') == 0 and child.get('dependency_hashes') == hashes(ROOT, original.dependencies),
                        'evaluation conformance receipt missing/stale')
                validate_report(original, child.get('report'))
    if spec.owner == 'P9.2-parity':
        languages = report.get('languages')
        require(isinstance(languages, dict) and set(languages) == {'php', 'dart'}, 'PHP/Dart usage evidence missing')
        for language in languages.values():
            validate_report(Suite('P9.2-language', spec.mode, spec.tier, spec.cases, (), ()), language)
            require(isinstance(language.get('observations'), dict) and
                    set(language['observations']) == set(spec.cases), 'portable usage observations missing')
        require(languages['php']['observations'] == languages['dart']['observations'], 'PHP/Dart usage facts differ')
    for field, expected in (("count", len(cases)), ("failures", 0), ("skips", 0)):
        if field in report:
            require(type(report[field]) is int and report[field] == expected,
                    f"contradictory runner total: {field}")
    if spec.owner == 'P11.1':
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'voice ingress input hashes missing/stale')
        require(report.get('evidence_kind') == 'voice_ingress_contract_harness', 'voice ingress evidence kind missing')
        require(report.get('live_provider') == 'not_executed' and report.get('production_readiness') == 'not_claimed',
                'synthetic voice ingress evidence tier inflated')
        validate_report(Suite('P11.1-pg','isolated-postgres','isolated-postgres',VOICE_INGRESS_PG_CASES,(),(),True),
                        report.get('postgres'))
    if spec.owner == 'P11.2':
        require(report.get('input_hashes') == hashes(ROOT,spec.dependencies),'voice delivery input hashes missing/stale')
        require(report.get('evidence_kind') == 'voice_delivery_contract_harness','voice delivery evidence kind missing')
        require(report.get('live_provider') == 'not_executed' and report.get('production_readiness') == 'not_claimed',
                'synthetic voice delivery evidence tier inflated')
        validate_report(Suite('P11.2-pg','isolated-postgres','isolated-postgres',VOICE_DELIVERY_PG_CASES,(),(),True),report.get('postgres'))
    if spec.owner == 'P11.3':
        inputs=hashes(ROOT,spec.dependencies)
        require(report.get('input_hashes')==inputs,'voice journey input hashes missing/stale')
        require(report.get('evidence_kind')=='voice_journey_contract_harness','voice journey evidence kind missing')
        require(all(report.get(k)==v for k,v in {
            'live_provider':'not_executed','acoustic_quality':'not_measured','child_speech_gold':'insufficient_evidence',
            'external_host_e2e':'not_executed','production_readiness':'not_claimed'}.items()),'synthetic voice qualification inflated')
        pg=report.get('postgres')
        validate_report(Suite('P11.3-pg','isolated-postgres','isolated-postgres',VOICE_JOURNEY_CASES,(),(),True),pg)
        require(pg.get('cases')==cases and pg.get('observations')==report.get('observations'),'voice journey native report differs')
        observations=report.get('observations',{})
        require(isinstance(observations,dict) and set(observations)==set(VOICE_JOURNEY_CASES),'voice journey observations missing')
        require(all(isinstance(o,dict) and type(o.get('model_calls')) is int and type(o.get('native_invocations')) is int
                    and o['model_calls']>=0 and o['model_calls']==o['native_invocations'] for o in observations.values()),
                'duplicate/unaccounted voice model IO')
        hooks=report.get('qualification_fixtures')
        validate_report(Suite('P11.3-hooks','contract-scripted','contract-scripted',('voice-journey.qualification-fixtures',),(),()),hooks)
        require(hooks.get('fixture_tests')==4,'voice qualification fixture tests missing')
        analysis=report.get('static_analysis',{})
        require(isinstance(analysis,dict) and type(analysis.get('exit')) is int and analysis['exit']==0
                and analysis.get('command')==['extensions/loqum/vendor/bin/phpstan','analyse','-c','examples/platform/voice/phpstan.neon','--no-progress'],
                'voice example static analysis missing')
        source_hash=digest(json.dumps(inputs,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode())
        native_hash=digest(json.dumps(pg,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode())
        example=observations['voice-journey.runnable-example'].get('runnable_example',{})
        require(isinstance(example,dict),'voice runnable example observations missing')
        observed=example.get('tuple',{})
        require(isinstance(observed,dict) and set(observed)=={
            'deployment_ref','model_revision','profile_ref','profile_bundle_hash','task_ref',
            'prompt_hash','skills_hash','tool_schema_hash','material_corpus_hash'}
            and all(isinstance(v,str) and v for v in observed.values())
            and all(re.fullmatch(r'sha-256:[a-f0-9]{64}',v) is not None for k,v in observed.items() if k.endswith('_hash')),
            'voice runtime tuple incomplete')
        expected=dict(observed,suite_hash='sha-256:'+source_hash)
        q=report.get('qualification',{})
        require(isinstance(q,dict) and q.get('format')=='voice-harness-qualification/v1'
                and q.get('scope')=='technical-contract-only' and q.get('decision')=='qualified' and q.get('reasons')==[]
                and q.get('tuple')==expected and q.get('source_manifest_sha256')==source_hash and q.get('runtime_report_sha256')==native_hash
                and q.get('live_provider')=='not_executed' and q.get('production_readiness')=='not_claimed'
                and q.get('evidence_tier')=='local-host-e2e' and q.get('rollout_policy_ref')=='fixture-disabled-production'
                and Counter(q.get('required_cases',[]))==Counter(VOICE_JOURNEY_CASES),'voice exact-tuple certificate missing/stale')
        domain=q.get('domain_qualification',{})
        require(isinstance(domain,dict) and domain.get('decision')=='insufficient_evidence'
                and isinstance(domain.get('prerequisites'),list) and domain['prerequisites']
                and all(isinstance(p,dict) and p.get('decision')=='insufficient_evidence'
                        and all(isinstance(p.get(k),str) and p[k] for k in ('owner','contract','data','gate')) for p in domain['prerequisites']),
                'external voice gold/owner prerequisites missing or inflated')
    if spec.owner == 'P10.4':
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'host E2E input hashes missing/stale')
        require(report.get('evidence_kind') == 'host_contract_harness', 'synthetic host evidence kind missing')
        require(report.get('external_host_e2e') == 'not_executed' and report.get('live_provider') == 'not_executed'
                and report.get('production_readiness') == 'not_claimed', 'local host evidence tier inflated')
        require(report.get('handoff_verified') is True, 'handoff evidence missing or stale')
    if spec.owner == 'P10.3':
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'second host input hashes missing/stale')
        require(report.get('evidence_kind') == 'host_contract_harness', 'second host evidence kind missing')
        isolation = report.get('isolation', {})
        require(isinstance(isolation, dict) and isolation.get('engine') == 'postgresql'
                and isolation.get('isolated') is True and isolation.get('allow_marker_verified') is True
                and isinstance(isolation.get('database'), str) and isolation['database'].startswith('agentools_test_')
                and isinstance(isolation.get('owner'), str) and bool(isolation['owner'])
                and Counter(isolation.get('case_ids', [])) == Counter(SECOND_HOST_PG_CASES),
                'second host isolated PostgreSQL evidence missing')
        require(report.get('live_provider') == 'not_executed' and report.get('production_readiness') == 'not_claimed',
                'second host evidence tier inflated')
    if spec.owner == 'P10.1':
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'host input hashes missing/stale')
        require(report.get('evidence_kind') == 'host_contract_harness', 'host evidence kind missing')
        external = report.get('external_owner', {})
        require(isinstance(external, dict) and external.get('status') == 'pending_external_owner'
                and external.get('change_request') == 'EDUFY-AGENTOOLS-HOST-001'
                and external.get('revision') == 1 and external.get('implemented_here') is False,
                'local host fixture cannot claim external owner implementation')
        require(report.get('host_e2e') == 'pending' and report.get('live_provider') == 'not_executed'
                and report.get('production_readiness') == 'not_claimed', 'host harness tier inflated')
    if spec.owner in {'P10.2', 'P10.2-pg'}:
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'Chatom input hashes missing/stale')
        require(report.get('evidence_kind') == 'host_contract_harness', 'Chatom local evidence kind missing')
        qualification = report.get('qualification', {})
        require(isinstance(qualification, dict) and qualification.get('status') == 'pending_real_chatom_pin'
                and qualification.get('immutable_real_driver_pin') is None
                and qualification.get('actual_client_key_semantics') == 'not_qualified_here'
                and qualification.get('remote_host_e2e') == 'not_executed'
                and qualification.get('live_provider') == 'not_executed'
                and qualification.get('production_readiness') == 'not_claimed', 'Chatom fixture cannot claim real driver qualification')
    if spec.owner == 'P10.2-pg':
        observed = report.get('observations', {})
        require(isinstance(observed, dict), 'Chatom durable observations missing')
        competing = observed.get('chatom.competing-claims', {})
        backends = competing.get('backends', [])
        require(len(backends) == 2 and all(type(pid) is int and pid > 0 for pid in backends)
                and backends[0] != backends[1] and competing.get('exits') == [0, 0]
                and competing.get('claims') == 1 and competing.get('rows') == 1, 'real competing Chatom claims missing')
        for name in ('chatom.crash-commit-before-dispatch', 'chatom.crash-destination-before-receipt',
                     'chatom.invalidation-partial-recovery'):
            fact = observed.get(name, {})
            require(fact.get('signaled') is True and fact.get('signal') == 9 and type(fact.get('exit')) is int
                    and fact['exit'] != 0, 'actual Chatom SIGKILL proof missing')
        commit = observed['chatom.crash-commit-before-dispatch']
        require(commit.get('pending_recovered') is True and commit.get('inbox') == commit.get('outbox') == 1,
                'committed source/delivery scanner recovery missing')
        delivery = observed['chatom.crash-destination-before-receipt']
        restarted = delivery.get('backends', [])
        require(len(restarted) == 2 and all(type(pid) is int and pid > 0 for pid in restarted)
                and restarted[0] != restarted[1] and delivery.get('recovery_exit') == 0
                and delivery.get('destination_messages') == 1 and delivery.get('invocations') == 0
                and isinstance(delivery.get('recovered_receipt'), str) and bool(delivery['recovered_receipt']),
                'same-key Chatom receipt recovery without regeneration missing')
        invalidation = observed['chatom.invalidation-partial-recovery']
        require(invalidation.get('summary_invalidated') is True and invalidation.get('context_cache_invalidated') is True,
                'partial invalidation recovery missing')
    if spec.owner in {'P9.4', 'P9.4-pg'}:
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'cache input hashes missing/stale')
        require(isinstance(report.get('observations'), dict), 'cache observations must be an object')
    if spec.owner == 'P9.5':
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'provider fallback input hashes missing/stale')
        live = report.get('live', {})
        require(isinstance(live, dict) and live.get('status') == 'pending_opt_in' and live.get('executed') is False
                and live.get('provider_support') == 'not_qualified'
                and live.get('educational_qualification') == 'insufficient_evidence', 'offline evidence cannot claim live qualification')
        packet = report.get('review_materialization', {})
        require(isinstance(packet, dict) and packet.get('status') == 'materialized_pending_coordinator_review'
                and type(packet.get('input_count')) is int and packet['input_count'] > 0
                and isinstance(packet.get('archive_sha256'), str) and re.fullmatch('[0-9a-f]{64}', packet['archive_sha256']) is not None
                and packet.get('reviewer_read_proof') == 'not_yet_available'
                and packet.get('historical_pass_is_not_current_pass') is True, 'complete honest pre-review materialization required')
    if spec.owner == 'P9.4-pg':
        observed = report['observations']
        for name in ('cache.pg-competing-quota', 'cache.pg-competing-use-revoke'):
            fact = observed.get(name, {})
            backends = fact.get('backends', [])
            require(len(backends) == 2 and all(type(pid) is int and pid > 0 for pid in backends)
                    and backends[0] != backends[1] and fact.get('exits') == [0, 0], 'real competing cache connections missing')
        quota = observed['cache.pg-competing-quota']
        require(quota.get('blocked') == 2 and quota.get('rows') == 1 and quota.get('results') == ['accepted', 'refused'], 'atomic warm quota proof missing')
        require(observed['cache.pg-competing-use-revoke'].get('after_revoke') == 'denied', 'post-revoke cache use was not denied')
        windows = observed.get('cache.pg-revoke-crash-windows', {})
        require(set(windows) == {'before-commit', 'after-commit'}, 'cache revoke crash windows missing')
        for fact in [*windows.values(), observed.get('cache.pg-delete-ack-crash', {}), observed.get('cache.pg-warm-reply-crash', {})]:
            require(fact.get('signaled') is True and fact.get('signal') == 9 and type(fact.get('exit')) is int and fact['exit'] != 0, 'actual cache SIGKILL proof missing')
        require(observed['cache.pg-delete-ack-crash'].get('owner_operations') == 1
                and observed['cache.pg-delete-ack-crash'].get('lookup_same_key') is True, 'delete ACK reconciliation proof missing')
        warm = observed['cache.pg-warm-reply-crash']
        require(warm.get('exposure') == 'unknown' and warm.get('automatic_release') is False
                and warm.get('owner_operations') == warm.get('usage_facts') == 1, 'warm unknown accounting was not retained')
    if spec.owner in {'P9.3', 'P9.3-pg'}:
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'accounting input hashes missing/stale')
    if spec.owner == 'P9.3-pg':
        observations = report.get('observations', {})
        competing = observations.get('accounting.competing-connections', {})
        backends = competing.get('backends', [])
        require(len(backends) == 2 and all(type(pid) is int and pid > 0 for pid in backends)
                and backends[0] != backends[1] and competing.get('blocked') == 2
                and competing.get('exits') == [0, 0] and competing.get('rows') == 1, 'accounting competing connection proof missing')
        windows = observations.get('accounting.crash-commit-windows', {})
        require(set(windows) == {'before-commit', 'after-commit'}, 'accounting crash windows missing')
        for proof in [*windows.values(), observations.get('accounting.crash-export-ack', {})]:
            require(proof.get('signaled') is True and proof.get('signal') == 9 and type(proof.get('exit')) is int
                    and proof['exit'] != 0, 'accounting SIGKILL proof missing')
        require(observations['accounting.crash-export-ack'].get('owner_exports') == 1
                and observations['accounting.crash-export-ack'].get('replayed_same_identity') is True, 'lost export ACK replay proof missing')
    if spec.requires_pg:
        proof = report.get("isolation")
        require(isinstance(proof, dict), "missing PostgreSQL isolation proof")
        require(proof.get("engine") == "postgresql" and proof.get("isolated") is True,
                "real isolated PostgreSQL is required")
        require(isinstance(proof.get("database"), str) and
                re.fullmatch(r"agentools_test_[a-z0-9_]+", proof["database"]) is not None,
                "database is not an isolated test database")
        require(isinstance(proof.get("owner"), str) and bool(proof["owner"]), "missing database owner")
        require(proof.get("allow_marker_verified") is True, "isolation allow marker not verified")
        require(Counter(proof.get("case_ids", [])) == Counter(spec.cases), "PG exact durable case set differs")
        if spec.owner == 'P6.5':
            observations = report.get('observations', {})
            concurrent = observations.get('CM-045', {})
            require(concurrent.get('workers') == 2 and concurrent.get('exits') == [0, 0]
                and concurrent.get('same_receipts') is True and concurrent.get('counts') == 1,
                'real concurrent scanner proof missing')
            crash = observations.get('scheduler.crash-after-commit', {})
            require(crash.get('signal') == 9 and crash.get('signaled') is True and type(crash.get('worker_exit')) is int
                and crash['worker_exit'] != 0 and crash.get('same_refs') is True and crash.get('counts') == 1,
                'SIGKILL occurrence recovery proof missing')
            unknown = observations.get('V2ALL-SCHED-FAULT', {})
            require(unknown.get('same_run') is True and unknown.get('owner_outcome') == 'unknown'
                and unknown.get('status') == 'reconciliation_required' and unknown.get('provider_calls') == 1
                and unknown.get('owner_writes') == 0, 'stopped schedule unknown-action proof missing')
    if spec.owner == 'P7.6':
        portable=report.get('portable')
        require(isinstance(portable,list) and len(portable)==4,'security behavioral partitions missing')
        for case_group,unit in zip((SECURITY_GUARD_CASES,SECURITY_DISCLOSURE_CASES,SECURITY_WORKER_CASES,SECURITY_EXAMPLE_CASES),portable):
            validate_report(Suite('P7.6-portable','contract-scripted','contract-scripted',case_group,(),()),unit)
        packaging=report.get('packaging')
        require(isinstance(packaging,dict) and packaging.get('standalone_core') is True and packaging.get('context_guards') is True
            and all(packaging.get(k) is False for k in ('testing_autoload','sdk_autoload','postgres_autoload','framework_autoload')),
            'standalone guard production no-dev proof missing')
        observed=report.get('observations',{})
        buffered=observed.get('CM-043',{})
        require(buffered.get('early_content_bytes')==0 and buffered.get('checks')==1 and buffered.get('full_result_check') is True
            and buffered.get('generated_bytes')==25 and buffered.get('delivered_before_receipt')==0
            and buffered.get('delivered_after_receipt')==25 and buffered.get('heard_bytes') is None,'sensitive buffered actual-receipt proof missing')
        worker=observed.get('worker_isolation',{})
        require(worker.get('jobs')==2 and worker.get('resource_kinds')==7 and worker.get('alpha_refs_in_beta')==0
            and worker.get('finally_reset') is True,'worker tenant reset proof missing')
        injection=observed.get('injection_surfaces',{})
        require(injection.get('surfaces')==['history','retrieval','tool','summary','memory','skill','input']
            and injection.get('unexpected_tools')==0 and injection.get('policy_disabled') is False,'untrusted surface parity proof missing')
        entries=observed.get('entry_parity',{})
        require(entries.get('entries')==['text','raw','build','stream','voice','background','scheduled'] and entries.get('cached_grants')==0,
            'entry parity/current permission proof missing')
        unknown=observed.get('unknown_delivery',{})
        require(unknown.get('owner_calls')==1 and unknown.get('delivered_bytes') is None and unknown.get('resend') is False,'unknown delivery was erased/retried')
        redaction=observed.get('redaction',{})
        require(all(redaction.get(k) is True for k in ('nested_values_redacted','exception_chain_redacted','encrypted','ttl','audit'))
            and redaction.get('diagnostic_calls')==1,'redaction/encrypted diagnostic privilege proof missing')
        require(report.get('input_hashes')==hashes(ROOT,spec.dependencies),'context guard input hashes missing/stale')
    if spec.owner in {'P8.1', 'P8.1-pg'}:
        require(report.get('evidence_kind') == 'host_contract_harness', 'knowledge evidence kind missing')
        limitations = report.get('limitations')
        require(isinstance(limitations, list) and limitations
            and all(isinstance(value, str) and value for value in limitations), 'knowledge limitations missing')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'knowledge input hashes missing/stale')
        if not spec.requires_pg:
            packaging = report.get('packaging', {})
            require(isinstance(packaging, dict) and packaging.get('knowledge') is True
                and all(packaging.get(key) is False for key in ('test_autoload', 'sdk', 'framework')),
                'knowledge production no-dev proof missing')
    if spec.owner == 'P8.2':
        require(report.get('evidence_kind') == 'host_contract_harness', 'retrieval evidence kind missing')
        limitations = report.get('limitations')
        require(isinstance(limitations, list) and limitations
            and all(isinstance(value, str) and value for value in limitations), 'retrieval limitations missing')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'retrieval input hashes missing/stale')
        packaging = report.get('packaging', {})
        require(isinstance(packaging, dict) and packaging.get('retrieval') is True
            and all(packaging.get(key) is False for key in ('test_autoload', 'sdk', 'framework', 'provider')),
            'retrieval production no-dev proof missing')
    if spec.owner == 'P8.4':
        require(report.get('evidence_kind') == 'host_contract_harness', 'golden evidence kind missing')
        require(report.get('input_hashes') == hashes(ROOT,spec.dependencies), 'golden input hashes missing/stale')
        limitations=report.get('limitations')
        require(isinstance(limitations,list) and limitations and all(isinstance(v,str) and v for v in limitations), 'golden limitations missing')
        require(report.get('transport') == 'scripted-knowledge/v1' and report.get('extractor') == 'scripted-coordinate-extractor/v1'
            and report.get('index') == 'isolated-postgresql-tsvector-gin', 'golden evidence tiers/adapter kinds differ')
        validate_report(Suite('P8.4-hooks','contract-scripted','contract-scripted',KNOWLEDGE_JOURNEY_UNIT_CASES,(),()),report.get('hooks'))
        observations=report.get('observations',{})
        require(isinstance(observations,dict), 'golden observations missing')
        for scenario in ('support','education'):
            proof=observations.get(scenario,{})
            require(isinstance(proof,dict) and proof.get('runtime') == 'AdvanceRun' and proof.get('index') == 'postgresql-tsvector-gin'
                and proof.get('provider_calls') == proof.get('responses') == proof.get('usage_versions') == 1
                and isinstance(proof.get('citations'),list) and proof['citations']
                and proof.get('educational_qualification') == 'insufficient_evidence', 'real golden journey/citation proof missing')
            require(all(re.fullmatch(r'[0-9a-f]{64}',proof.get(k,'')) for k in ('source_hash','release_hash'))
                and re.fullmatch(r'sha-256:[0-9a-f]{64}',proof.get('snapshot_hash','')) is not None, 'golden source/release/snapshot hashes missing')
        for correction_name in ('correction','education-correction'):
            correction=observations.get(correction_name,{})
            require(isinstance(correction,dict) and correction.get('old_run') != correction.get('new_run')
                and correction.get('historical_unchanged') is True and correction.get('old_snapshot_denied') is True
                and correction.get('marker') == 'source_corrected' and correction.get('new_version') == 'v2', 'golden correction propagation missing')
        refusal=observations.get('pre-send-correction',{})
        require(isinstance(refusal,dict) and refusal.get('provider_calls') == refusal.get('usage_rows') == refusal.get('request_count') == 0
            and refusal.get('outcome') == 'failed' and refusal.get('possible_cost') is False, 'known pre-send correction refusal missing')
        recovery=observations.get('recovery',{})
        require(isinstance(recovery,dict) and recovery.get('signal') == 9 and recovery.get('signaled') is True
            and type(recovery.get('worker_exit')) is int and recovery['worker_exit'] != 0
            and all(recovery.get(k) == 1 for k in ('provider_calls','responses','usage_versions','delivery_messages'))
            and recovery.get('lookup') == 'verified-same-request', 'golden real SIGKILL/recovery missing')
    if spec.owner in {'P8.3', 'P8.3-pg'}:
        require(report.get('evidence_kind') == 'host_contract_harness', 'invalidation evidence kind missing')
        limitations=report.get('limitations')
        require(isinstance(limitations,list) and limitations and all(isinstance(v,str) and v for v in limitations), 'invalidation limitations missing')
        require(report.get('input_hashes') == hashes(ROOT,spec.dependencies), 'invalidation input hashes missing/stale')
        if spec.requires_pg:
            observations=report.get('observations',{})
            require(isinstance(observations,dict), 'invalidation durable observations missing')
            race=observations.get('concurrency',{})
            require(isinstance(race,dict) and race.get('competed') is True and race.get('revocation') == 'committed'
                and race.get('final_use') == 'denied' and isinstance(race.get('backends'),list)
                and len(race['backends']) == len(set(race['backends'])) == 2
                and all(type(pid) is int and pid>0 for pid in race['backends']), 'real invalidation competing connections missing')
            for boundary in ('crash-before','crash-after'):
                proof=observations.get(boundary,{})
                require(isinstance(proof,dict) and proof.get('signal') == 9 and proof.get('signaled') is True
                    and type(proof.get('worker_exit')) is int and proof['worker_exit'] != 0
                    and proof.get('retry_tombstones') == proof.get('delete_intents') == 1
                    and proof.get('lost_response') == 'unknown' and proof.get('replayed_io') == 0
                    and proof.get('new_attempt_io') == 1, 'invalidation SIGKILL/restart proof missing')
            deletion=observations.get('delete-crash',{})
            require(isinstance(deletion,dict) and deletion.get('signal') == 9 and deletion.get('signaled') is True
                and type(deletion.get('worker_exit')) is int and deletion['worker_exit'] != 0
                and deletion.get('lost_response') == 'unknown' and deletion.get('replayed_io') == 0
                and deletion.get('new_attempt_io') == deletion.get('provider_effects') == 1
                and deletion.get('receipt') == 'succeeded', 'provider response SIGKILL/unknown retry proof missing')
        else:
            packaging=report.get('packaging',{})
            require(isinstance(packaging,dict) and packaging.get('invalidation') is True
                and all(packaging.get(k) is False for k in ('test_autoload','sdk','framework','provider')), 'invalidation production no-dev proof missing')
    if spec.owner == 'P7.5':
        portable=report.get('portable')
        require(isinstance(portable,list) and len(portable)==3,'history/memory behavioral reports missing')
        for case_group,unit in zip((HISTORY_CORE_CASES,MEMORY_CORE_CASES,HISTORY_EXAMPLE_CASES),portable):
            validate_report(Suite('P7.5-portable','contract-scripted','contract-scripted',case_group,(),()),unit)
        packaging=report.get('packaging')
        require(isinstance(packaging,dict) and packaging.get('standalone_core') is True and packaging.get('history_memory') is True
            and all(packaging.get(k) is False for k in ('testing_autoload','sdk_autoload','postgres_autoload','framework_autoload')),
            'standalone history/memory production no-dev proof missing')
        require(report.get('input_hashes')==hashes(ROOT,spec.dependencies),'history/memory input hashes missing/stale')
    if spec.owner == 'P7.5-pg':
        portable=report.get('portable')
        require(isinstance(portable,list) and len(portable)==3,'summary durable/concurrency/recovery reports missing')
        for case_group,unit in zip((SUMMARY_DURABLE_CASES,SUMMARY_CONCURRENT_CASES,SUMMARY_RECOVERY_CASES),portable):
            validate_report(Suite('P7.5-portable-pg','isolated-postgres','isolated-postgres',case_group,(),(),requires_pg=True),unit)
        observations=report.get('observations',{})
        concurrent=observations.get('competing_connections',{})
        backends=concurrent.get('backends',[])
        require(isinstance(backends,list) and len(backends)==2 and all(type(p) is int and p>0 for p in backends)
            and backends[0]!=backends[1] and concurrent.get('exits')==[0,0]
            and concurrent.get('outcomes')==['appended','integrity_conflict'] and concurrent.get('versions')==1,
            'real competing summary connections/CAS proof missing')
        crash=observations.get('crash_restart',{})
        for boundary in ('before_commit','after_commit'):
            event=crash.get(boundary,{})
            require(event.get('signaled') is True and event.get('signal')==9 and type(event.get('exit')) is int and event['exit']!=0,
                'required SIGKILL boundary proof missing')
        require(crash.get('rolled_back') is True and crash.get('replay_versions')==1 and crash.get('same_hash') is True,
            'summary restart/replay integrity proof missing')
        cascade=observations.get('source_cascade',{})
        require(cascade.get('invalidated')==4 and cascade.get('historical_markers')==1 and cascade.get('future_use_denied') is True,
            'source deletion cascade/historical marker proof missing')
        require(report.get('input_hashes')==hashes(ROOT,spec.dependencies),'summary input hashes missing/stale')
    if spec.owner == 'P7.4':
        portable=report.get('portable')
        require(isinstance(portable,list) and len(portable)==4,'context behavioral reports missing')
        for case_group,unit in zip((CONTEXT_CORE_CASES,CONTEXT_LIFECYCLE_CASES,CONTEXT_EXAMPLE_CASES,CONTEXT_READ_CASES),portable):
            tier='unit' if case_group==CONTEXT_READ_CASES else 'contract-scripted'
            validate_report(Suite('P7.4-portable',tier,tier,case_group,(),()),unit)
        validate_report(SUITES[('context-compiler','pg-test')],report.get('postgres'))
        packaging=report.get('packaging')
        require(isinstance(packaging,dict) and packaging.get('standalone_core') is True and packaging.get('context') is True
            and all(packaging.get(k) is False for k in ('testing_autoload','sdk_autoload','postgres_autoload','framework_autoload')),
            'standalone context production no-dev proof missing')
        require(report.get('input_hashes')==hashes(ROOT,spec.dependencies),'context input hashes missing/stale')
    if spec.owner == 'P7.3':
        portable = report.get('portable')
        require(isinstance(portable, list) and len(portable) == 3, 'skill behavioral reports missing')
        for unit_cases, unit in zip((SKILL_CORE_CASES, SKILL_LIFECYCLE_CASES, SKILL_EXAMPLE_CASES), portable):
            validate_report(Suite('P7.3-portable','contract-scripted','contract-scripted',unit_cases,(),()), unit)
        packaging = report.get('packaging')
        require(isinstance(packaging, dict) and packaging.get('standalone_core') is True and packaging.get('skills') is True
            and all(packaging.get(k) is False for k in ('testing_autoload','sdk_autoload','postgres_autoload','framework_autoload')),
            'standalone skill production no-dev proof missing')
        observed = report.get('observations')
        require(isinstance(observed, dict), 'skill observations missing')
        selected = observed.get('CM-007')
        require(isinstance(selected, dict) and all(type(selected.get(k)) is int and selected[k] == v
            for k,v in {'registered':100,'shortlist':1,'selected':1,'fragment_loads':1,'transport_calls':1,'qualification_calls_during_route':0}.items())
            and selected.get('tools') == ['search'], '100 manifests / one bounded full-fragment transport proof missing')
        mismatch = observed.get('CM-008')
        require(isinstance(mismatch, dict) and mismatch.get('code') == 'HASH_MISMATCH' and mismatch.get('loads') == 1
            and mismatch.get('loaded_versions') == ['1'] and mismatch.get('latest_substituted') is False, 'exact fragment mismatch proof missing')
        fault = observed.get('V2ALL-SKILL-FAULT')
        require(isinstance(fault, dict) and fault.get('revoked_loads') == 0 and fault.get('audit_marker') == 'revoked'
            and fault.get('snapshot_unchanged') is True, 'current skill revoke/audit proof missing')
        negative = observed.get('V2ALL-SKILL-NEGATIVE')
        require(isinstance(negative, dict) and negative.get('rejected_manifest_fields') == ['secret','history','corpus','curriculum','tool_catalogue']
            and negative.get('forbidden_fragment_kinds') == 5 and negative.get('qualified_publications') == 0, 'forbidden skill payload coverage missing')
        require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'skill input hashes missing/stale')
    if spec.owner == 'P7.2':
        validate_report(Suite('P7.2-pg','isolated-postgres','isolated-postgres',
            PROFILE_MERGE_PG_CASES, (), spec.dependencies, requires_pg=True), report.get('postgres'))
        pg_inputs = report['postgres'].get('input_hashes')
        require(pg_inputs == hashes(ROOT, spec.dependencies), 'typed merge publication input hashes missing/stale')
        portable = report.get('portable')
        require(isinstance(portable, list) and len(portable) == 2, 'typed merge portable reports missing')
        for unit_cases, unit in zip((PROFILE_MERGE_CORE_CASES, PROFILE_MERGE_LIVE_CASES), portable):
            validate_report(Suite('P7.2-core','contract-scripted','contract-scripted',unit_cases,(),()), unit)
        packaging = report.get('packaging')
        require(isinstance(packaging, dict) and packaging.get('composition') is True
            and packaging.get('testing_autoload') is False, 'typed merge production no-dev proof missing')
    if spec.owner == 'P7.1':
        if spec.requires_pg:
            observations = report.get('observations', {})
            require(isinstance(observations, dict), 'profile observations missing')
            for name in ('profiles.concurrent-publish', 'profiles.concurrent-pin'):
                proof = observations.get(name, {})
                require(isinstance(proof, dict), 'profile connection observation malformed')
                backends = proof.get('backends')
                require(proof.get('workers') == 2 and proof.get('exits') == [0, 0]
                    and proof.get('competed') is True and proof.get('rows') == 1
                    and isinstance(backends, list) and len(backends) == 2
                    and all(type(pid) is int and pid > 0 for pid in backends) and backends[0] != backends[1],
                    'real competing profile connections proof missing')
            require(observations.get('profiles.concurrent-pin', {}).get('same_pin') is True, 'concurrent pin identity proof missing')
            for name, rows in (('profiles.publish-sigkill-recovery', 1), ('profiles.pin-sigkill-recovery', 1),
                    ('profiles.migration-sigkill-recovery', 2)):
                proof = observations.get(name, {})
                require(isinstance(proof, dict), 'profile crash observation malformed')
                require(proof.get('signal') == 9 and proof.get('signaled') is True
                    and type(proof.get('worker_exit')) is int and proof['worker_exit'] != 0
                    and proof.get('committed') == 'committed' and proof.get('same_hash') is True
                    and proof.get('rows') == rows, 'profile SIGKILL/restart proof missing')
            rollback = observations.get('profiles.migration-commit-rollback', {})
            require(isinstance(rollback, dict), 'profile rollback observation malformed')
            require(all(rollback.get(k) is True for k in ('history_rolled_back', 'head_rolled_back', 'receipt_rolled_back'))
                and rollback.get('retry_revision') == 2, 'atomic migration commit rollback proof missing')
            inputs = report.get('input_hashes')
            require(isinstance(inputs, dict) and set(inputs) == set(spec.dependencies)
                and all(isinstance(h, str) and re.fullmatch('[0-9a-f]{64}', h) for h in inputs.values()),
                'profile test input hashes missing')
            require(inputs == hashes(ROOT, spec.dependencies), 'profile test inputs changed after execution')
        else:
            packaging = report.get('packaging', {})
            require(isinstance(packaging, dict), 'profile packaging observation malformed')
            require(packaging.get('profile_publisher') is True and packaging.get('profile_pins') is True
                and packaging.get('testing_autoload') is False, 'profile production no-dev proof missing')
            portable = report.get('portable')
            require(isinstance(portable, list) and len(portable) == 2, 'profile unit reports missing')
            for unit_cases, unit in zip((PROFILE_UNIT_CASES, PROFILE_EXAMPLE_CASES), portable):
                validate_report(Suite('P7.1-unit', 'unit', 'unit', unit_cases, (), ()), unit)
    if spec.owner in {'P6.1', 'P6.2', 'P6.3', 'P6.4', 'P6.5', 'P7.7'} and spec.mode == 'contract-scripted':
        name = {'P6.1': 'laravel-composition', 'P6.2': 'platform-definitions', 'P6.3': 'composition-testing',
                'P6.4': 'filament', 'P6.5': 'scheduler', 'P7.7': 'profile-publish-admin'}[spec.owner]
        validate_report(SUITES[(name, 'pg-test')], report.get('postgres'))
        packaging = report.get('packaging')
        require(isinstance(packaging, dict) and packaging.get('provider') is True
            and type(packaging.get('default_routes')) is int and packaging['default_routes'] == 0
            and packaging.get('default_host_bindings') is False and packaging.get('testing_autoload') is False,
            'production no-dev proof missing')
        if spec.owner == 'P6.3':
            validate_report(Suite('P6.3-ports', 'contract-scripted', 'contract-scripted',
                COMPOSITION_PORT_CASES, (), ()), report.get('portable'))
        if spec.owner in {'P6.4', 'P7.7'}:
            require(packaging.get('filament_absent_headless') is True, 'headless Filament independence proof missing')
            adapter = packaging.get('adapter')
            require(isinstance(adapter, dict) and adapter.get('provider') is True
                and type(adapter.get('default_routes')) is int and adapter['default_routes'] == 0
                and adapter.get('default_host_bindings') is False and adapter.get('testing_autoload') is False
                and adapter.get('filament') is True, 'adapter production no-dev proof missing')
            shell_cases = PROFILE_ADMIN_SHELL_CASES if spec.owner == 'P7.7' else FILAMENT_SHELL_CASES
            validate_report(Suite(spec.owner+'-shell', 'contract-scripted', 'contract-scripted',
                shell_cases, (), ()), report.get('shell'))
            if spec.owner == 'P7.7':
                require(adapter.get('profile_admin') is True, 'profile admin production autoload proof missing')
                require(report.get('input_hashes') == hashes(ROOT, spec.dependencies), 'profile admin source hashes missing/stale')
        if spec.owner == 'P6.5':
            require(packaging.get('scheduler_absent_headless') is True and packaging.get('core_port') is True,
                'independent core SchedulePort/headless proof missing')
            adapter = packaging.get('adapter')
            require(isinstance(adapter, dict) and adapter.get('provider') is True
                and type(adapter.get('default_routes')) is int and adapter['default_routes'] == 0
                and adapter.get('default_host_bindings') is False and adapter.get('testing_autoload') is False
                and adapter.get('scheduler') is True and adapter.get('filament') is False
                and adapter.get('core_port') is True, 'scheduler production no-dev proof missing')
            validate_report(Suite('P6.5-calendar', 'unit', 'unit', SCHEDULER_CALENDAR_CASES, (), ()), report.get('calendar'))
    if spec.owner == 'P5.5' and spec.requires_pg:
        observations = report.get('observations', {})
        crash = observations.get('crash-matrix', {}).get('boundaries', {})
        require(set(crash) == set(WRITE_BOUNDARIES), 'exact SIGKILL boundary matrix missing')
        for name, fact in crash.items():
            require(type(fact.get('worker_exit')) is int and fact['worker_exit'] != 0, 'worker was not killed')
            expected = 'unresolved' if name == 'invocation-intent' else 'completed'
            require(fact.get('after', {}).get('status') == expected, 'wrong crash recovery outcome')
            require(fact['after'].get('owner_writes') == (0 if expected == 'unresolved' else 1), 'duplicate or missing owner effect')
        require(crash['owner-commit'].get('before', {}).get('outcome') == 'unknown', 'lost owner result erased unknown')
        atomic = observations.get('atomic-matrix', {}).get('boundaries', {})
        require(set(atomic) == set(WRITE_ATOMIC_BOUNDARIES), 'exact deferred-commit fault matrix missing')
        require(all(f.get('rolled_back') is True and f.get('owner_writes') == 1 and f.get('messages') == 1 for f in atomic.values()), 'atomic recovery failed')
        zombie = observations.get('zombie', {})
        require(zombie.get('old_worker_alive_after_db_lease_expiry') is True and zombie.get('facts', {}).get('stale_run') is True
                and zombie['facts'].get('stale_effect') is True and zombie['facts'].get('owner_writes') == 1
                and zombie.get('accepted_epoch', 0) > zombie.get('original_epoch', 0), 'live zombie proof missing')
        rollback = observations.get('rollback', {})
        require(rollback.get('reader_revision') == '2026-09' and rollback.get('owner_writes') == 1
                and re.fullmatch(r'sha-256:[0-9a-f]{64}', rollback.get('projection_digest', '')) is not None, 'compatible reader rollback proof missing')
        validate_report(SUITES[('write-recovery', 'test')], report.get('portable'))
    return {"count": len(cases), "failures": 0, "skips": 0, "case_ids": ids}


def run_suite(root: Path, spec: Suite, *, isolated=False, timeout=120):
    require(not spec.requires_pg or isolated, "required PG suite needs --isolated")
    require(spec.command and spec.cases, "suite has no runner/cases")
    inputs = hashes(root, spec.dependencies)
    if spec.owner.startswith('P12.1-') and timeout == 120:
        timeout = 900
    started_at = utc()
    try:
        process = subprocess.run(spec.command, cwd=root, text=True, capture_output=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise GateError(f"runner unavailable/timeout: {error}") from error
    result = {"command": list(spec.command), "owner": spec.owner, "mode": spec.mode,
              "tier": spec.tier, "dependency_hashes": inputs, "exit": process.returncode,
              "stdout": process.stdout, "stderr": process.stderr}
    if spec.owner in {'P6.4', 'P6.5', 'P7.7', 'P7.7-pg', 'P8.1', 'P8.1-pg', 'P8.2', 'P12.1', 'P12.2', 'P12.2-pg', 'P12.3', 'P12.3-pg', 'P12.4'} or spec.owner.startswith('P12.1-'):
        result['suite_manifest_hash'] = digest(json.dumps({
            'owner': spec.owner, 'mode': spec.mode, 'tier': spec.tier, 'cases': spec.cases,
            'command': spec.command, 'requires_pg': spec.requires_pg, 'dependencies': inputs,
        }, sort_keys=True, separators=(',', ':')).encode())
    try:
        require(process.returncode == 0, f"runner exited {process.returncode}")
        try:
            report = json.loads(process.stdout)
        except ValueError as error:
            raise GateError("runner did not emit a JSON case report") from error
        result["report"] = report
        result.update(validate_report(spec, report))
        result["ok"] = True
    except GateError as error:
        result.update(ok=False, error=str(error))
    if spec.owner in {'P12.1','P12.2','P12.2-pg','P12.3','P12.3-pg','P12.4'} or spec.owner.startswith('P12.1-'):
        result.update(started_at=started_at, finished_at=utc(), evidence_kind=spec.tier)
    return result


def evaluation_receipt(result, envelope):
    """Normalized actual suite execution; does not issue a model qualification."""
    spec = next(s for s in SUITES.values() if s.owner == result['owner'])
    report = result['report']
    suite_hash = 'sha-256:' + result['suite_manifest_hash']
    invocation = {'command': envelope['command'], 'source': envelope['source'],
                  'started_at': result['started_at'], 'suite_hash': suite_hash,
                  'evidence_kind': result['tier']}
    artifacts = []
    for stream in ('stdout', 'stderr'):
        output = result[stream]
        artifacts.append({'path': output['path'] if isinstance(output, dict) else 'inline:' + stream,
                          'sha256': 'sha-256:' + (output['sha256'] if isinstance(output, dict) else digest(output.encode()))})
    return {'schema_version': 'evaluation/v1', 'command': envelope['command'], 'exit_code': result['exit'],
            'started_at': result['started_at'], 'finished_at': result['finished_at'], 'source': envelope['source'],
            'input_hashes': {p: 'sha-256:' + sha for p, sha in result['dependency_hashes'].items()},
            'suite_hash': suite_hash, 'run_hash': 'sha-256:' + digest(json.dumps(
                invocation, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()),
            'required_cases': list(spec.cases), 'executed_cases': result['case_ids'],
            'failures': result['failures'], 'skips': result['skips'], 'evidence_kind': result['tier'],
            'readiness_claim': 'technical-contract-only',
            'environment': {'python': sys.version, 'adapter_mode': result['mode'],
                            'isolation': report.get('isolation')},
            'artifacts': artifacts, 'limitations': report['limitations']}


# A deny-by-default language/dependency check. PHP's own tokenizer handles
# comments, strings, aliases and qualified names; candidate source is never run.
PURE_FUNCTIONS = set("checkdate get_object_vars count strlen strcmp strcasecmp str_contains str_starts_with str_ends_with "
    "substr trim ltrim rtrim strtolower strtoupper explode implode array_key_exists array_keys "
    "array_values array_map array_filter array_reduce array_unique array_merge array_diff array_intersect "
    "array_is_list array_slice in_array is_string is_int is_float is_bool is_array is_null is_object "
    "is_numeric preg_match preg_replace json_encode json_decode hash hash_equals sprintf intdiv "
    "min max abs floor ceil round sort ksort usort krsort array_reverse get_debug_type "
    "is_finite is_nan mb_strlen mb_substr bin2hex hex2bin ord chr pack unpack".split())
PURE_TYPES = set("stdClass DomainException InvalidArgumentException LogicException RuntimeException "
    "UnexpectedValueException OutOfBoundsException OverflowException UnderflowException "
    "LengthException RangeException ValueError TypeError Error Exception Throwable "
    "JsonException JsonSerializable Stringable UnitEnum BackedEnum".split())
MODULES = {
    "Common": set(), "Security": {"Common"}, "Observability": {"Common", "Security"}, "Profiles": {"Common"},
    "Context": {"Common", "Security", "Profiles"},
    "Models": {"Common", "Profiles", "Context"},
    "Actions": {"Common", "Security", "Profiles"},
    "Usage": {"Common"},
    "Budget": {"Common", "Usage"},
    "Scheduling": {"Common", "Security", "Context"},
    "Runtime": {"Common", "Security", "Profiles", "Context", "Models", "Actions", "Budget"},
}


def php_tokens(root: Path, source: str, path: str):
    process = subprocess.run(["php", str(root / TESTING / "bin/php-tokens.php")],
                             input=source, text=True, capture_output=True, timeout=15)
    require(process.returncode == 0, f"PHP parse/tokenizer failed: {path}: {process.stderr}")
    return json.loads(process.stdout)


def source_errors(root: Path, source: str, path: str):
    tokens = php_tokens(root, source, path)
    errors = []
    namespace = None
    declaration = False
    declared = set()
    for i, (kind, value, _) in enumerate(tokens):
        if kind == "T_NAMESPACE":
            namespace = tokens[i + 1][1]
            break
    prefix = "AgenToolsPlatform\\Core\\"
    if not namespace or not namespace.startswith(prefix):
        return [f"{path}: core namespace missing or not core-owned"]
    module = namespace[len(prefix):].split("\\")[0]
    if module not in MODULES:
        return [f"{path}: unknown core module {module}"]
    relative = Path(path).parts
    if "src" in relative and relative[relative.index("src") + 1] != module:
        errors.append(f"{path}: namespace/path module mismatch")
    for i, (kind, value, _) in enumerate(tokens[:-1]):
        if kind in {"T_CLASS", "T_INTERFACE", "T_TRAIT", "T_ENUM"}:
            declared.add(tokens[i + 1][1])
    forbidden = {"T_EVAL", "T_INCLUDE", "T_INCLUDE_ONCE", "T_REQUIRE", "T_REQUIRE_ONCE",
                 "T_EXIT", "T_ECHO", "T_PRINT", "T_GLOBAL", "T_HALT_COMPILER"}
    names = {"T_STRING", "T_NAME_QUALIFIED", "T_NAME_FULLY_QUALIFIED", "T_NAME_RELATIVE"}
    for i, (kind, value, line) in enumerate(tokens):
        prev = tokens[i - 1][0] if i else ""
        nxt = tokens[i + 1][0] if i + 1 < len(tokens) else ""
        where = f"{path}:{line}"
        if kind in forbidden or value == "`":
            errors.append(f"{where}: forbidden executable IO/dynamic token {kind}")
        if kind == "T_NAMESPACE":
            declaration = True
            continue
        if declaration:
            if value in {";", "{"}:
                declaration = False
            continue
        if kind == "T_VARIABLE" and (nxt == "(" or prev in {"T_NEW", "T_DOUBLE_COLON", "T_OBJECT_OPERATOR", "T_NULLSAFE_OBJECT_OPERATOR"}):
            errors.append(f"{where}: dynamic invocation/type is forbidden")
        if kind in {")", "]", "}"} and nxt == "(":
            errors.append(f"{where}: dynamic invocation is forbidden")
        if kind not in names:
            continue
        name = value.lstrip("\\")
        # PHP's built-in parameter-redaction attribute is syntax, not an IO dependency.
        # Keep construction, arbitrary attributes and every external class denied.
        if name == "SensitiveParameter" and prev == "T_ATTRIBUTE" and nxt == "]":
            continue
        if name.startswith(prefix):
            target = name[len(prefix):].split("\\")[0]
            if target != module and target not in MODULES[module]:
                errors.append(f"{where}: {module} cannot depend on {target}")
        elif "\\" in name:
            errors.append(f"{where}: core external dependency {name}")
        elif (value.startswith("\\") or prev in {"T_NEW", "T_EXTENDS", "T_IMPLEMENTS", "T_USE"}) and name not in PURE_TYPES | declared:
            if name.lower() not in PURE_FUNCTIONS:
                errors.append(f"{where}: unapproved type/dependency {name}")
        if nxt == "(" and prev not in {"T_FUNCTION", "T_DOUBLE_COLON", "T_OBJECT_OPERATOR", "T_NULLSAFE_OBJECT_OPERATOR", "T_NEW"}:
            if name.lower() not in PURE_FUNCTIONS:
                errors.append(f"{where}: unapproved function {name}")
    return errors


def host_source_errors(root: Path, source: str, path: str):
    """Host integration uses public ports; it cannot reach owner ORM/DB/container internals."""
    errors = []
    for kind, value, line in php_tokens(root, source, path):
        if kind not in {'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_STRING'}:
            continue
        name = value.lstrip('\\')
        if (re.match(r'(?:Edufy|Chatom|Loqum|Tariffler|ControLLM)(?:\\[A-Za-z0-9_]+)*'
                     r'\\(?:Models?|Entities|Database|Tables?|Repositories)(?:\\|$)', name, re.I)
                or re.match(r'(?:Illuminate\\)?(?:Database|Container|Support\\Facades)(?:\\|$)', name)
                or name.lower() in {'pdo', 'db', 'container', 'app', 'resolve', 'mysqli', 'pg_query'}):
            errors.append(f'{path}:{line}: host direct owner table/container dependency {name}')
    return errors


def check_boundaries(root=ROOT):
    core = root / CORE
    require((core / "composer.json").is_file(), "core manifest missing")
    sources = sorted((core / "src").rglob("*.php"))
    require(sources, "core source missing; absent code cannot prove purity")
    errors = []
    manifests = [root / "php/composer.json"]
    for base in ("packages", "extensions"):
        manifests.extend(sorted((root / base).glob("*/composer.json")))
    manifests = [p for p in manifests if p.is_file()]
    packages, prefixes = {}, {}
    for file in manifests:
        doc = json.loads(file.read_text())
        name = doc["name"]
        if name in packages:
            errors.append(f"duplicate package owner: {name}")
        packages[name] = doc
        is_testing = name == "agentools/platform-testing"
        for prefix, paths in doc.get("autoload", {}).get("psr-4", {}).items():
            for prior, owner in prefixes.items():
                if prefix.startswith(prior) or prior.startswith(prefix):
                    errors.append(f"overlapping namespace owners: {owner}/{name}: {prefix}")
            prefixes[prefix] = name
            for path in paths if isinstance(paths, list) else [paths]:
                if path != "src/":
                    errors.append(f"{name}: production autoload must be src/ only")
        if name.startswith("agentools/platform-"):
            if not doc.get("autoload", {}).get("psr-4") or set(doc.get("autoload", {})) != {"psr-4"}:
                errors.append(f"{name}: production autoload needs exclusive PSR-4 source ownership")
            if doc.get("license") != "proprietary":
                errors.append(f"{name}: private license boundary changed")
            if not is_testing and any(re.search(r"testing|testkit|reference|fake", dep, re.I) for dep in doc.get("require", {})):
                errors.append(f"{name}: production requires testing/reference")
        if name == "agentools/platform-core":
            if doc.get("require") != {"php": ">=8.3"}:
                errors.append("core production dependencies must be PHP >=8.3 only")
            if doc.get("autoload") != {"psr-4": {"AgenToolsPlatform\\Core\\": "src/"}}:
                errors.append("core namespace owner differs from ADR")
        if is_testing and doc.get("autoload") != {"psr-4": {"AgenToolsPlatform\\Testing\\": "src/"}}:
            errors.append("testing namespace owner differs from ADR")
        lock = file.with_name("composer.lock")
        if lock.is_file():
            # Only production packages; packages-dev never enter a --no-dev closure.
            for package in json.loads(lock.read_text()).get("packages", []):
                if not is_testing and re.search(r"testing|testkit|reference|fake", package["name"], re.I):
                    errors.append(f"{name}: locked production dependency on testing/reference")
    for name, doc in packages.items():
        if name == "agentools/platform-testing":
            continue
        pending = list(doc.get("require", {}))
        visited = set()
        while pending:
            dependency = pending.pop()
            if dependency in visited:
                continue
            visited.add(dependency)
            if re.search(r"testing|testkit|reference|fake", dependency, re.I):
                errors.append(f"{name}: transitive production testing dependency {dependency}")
            pending.extend(packages.get(dependency, {}).get("require", {}))
    production_sources = []
    for file in manifests:
        doc = json.loads(file.read_text())
        if not doc["name"].startswith("agentools/platform-") or doc["name"] == "agentools/platform-testing":
            continue
        for source in sorted((file.parent / "src").rglob("*.php")):
            production_sources.append(source)
            if source.is_relative_to(root / 'packages/agentools-laravel/src/Host'):
                errors.extend(host_source_errors(root, source.read_text(), str(source.relative_to(root))))
            # Unlike a Composer closure check, this catches an undeclared import.
            for kind, value, line in php_tokens(root, source.read_text(), str(source.relative_to(root))):
                if kind in {"T_NAME_QUALIFIED", "T_NAME_FULLY_QUALIFIED", "T_STRING"} and re.search(
                        r"(?:^|\\)(?:Testing|TestKit|Fake\w*|InMemory\w*)(?:\\|$)|AgenTools\\Reference(?:\\|$)", value):
                    errors.append(f"{source.relative_to(root)}:{line}: production testing/fake source dependency")
    for path in sources:
        errors.extend(source_errors(root, path.read_text(), str(path.relative_to(root))))
    require(not errors, "\n".join(errors))
    paths = sorted({str(p.relative_to(root)) for p in sources + production_sources + manifests}
                   | {str(p.with_name("composer.lock").relative_to(root)) for p in manifests
                      if p.with_name("composer.lock").is_file()})
    return {"mode": "source-inspection", "tier": "unit", "cases": [
        {"id": name, "status": "passed"} for name in
        ("core-purity", "namespace-owners", "production-no-testing", "core-dependencies")],
        "dependency_hashes": hashes(root, paths),
        "limitations": ["Static source/manifests/locks inspection; no runtime guard or PG qualification."]}


def source_identity(root):
    def git(*args):
        return subprocess.run(("git", *args), cwd=root, capture_output=True, timeout=20)
    head = git("rev-parse", "HEAD")
    diff = git("diff", "HEAD", "--binary")
    other = git("ls-files", "--others", "--exclude-standard", "-z")
    require(all(p.returncode == 0 for p in (head, diff, other)), "cannot determine source revision/diff")
    dirty = hashlib.sha256(diff.stdout)
    for raw in sorted(other.stdout.split(b"\0")):
        if not raw:
            continue
        path = raw.decode()
        # Evidence is an output, never an input to its own source identity.
        if path.startswith("plans/") and "/artifacts/implementation/" in path:
            continue
        file = root / path
        if file.is_file():
            dirty.update(raw + b"\0" + hashlib.sha256(file.read_bytes()).digest())
    return {"revision": head.stdout.decode().strip(), "dirty_diff_digest": dirty.hexdigest()}


def parser():
    cli = argparse.ArgumentParser(description=__doc__)
    sub = cli.add_subparsers(dest="command", required=True)
    for name in ("check-spec", "check-boundaries", "test", "pg-test", "e2e", "qualify", "acceptance", "doctor"):
        command = sub.add_parser(name)
        command.add_argument("--receipt", type=Path, help="write truthful JSON evidence and stdout/stderr sidecars")
        if name in {"test", "pg-test"}:
            command.add_argument("--suite", required=True)
        if name == "pg-test":
            command.add_argument("--isolated", action="store_true", required=True)
        if name == "e2e":
            command.add_argument("--scenario", required=True)
        if name in {"qualify", "acceptance", "doctor"}:
            mode = command.add_mutually_exclusive_group(required=True)
            mode.add_argument("--offline", action="store_true")
            if name == "qualify":
                mode.add_argument("--live", action="store_true")
                command.add_argument("--provider")
                command.add_argument("--max-cost-minor", type=int)
                command.add_argument("--currency")
    return cli


def execute(args, root=ROOT):
    require(not getattr(args, "live", False), "live qualification is unregistered and requires separate execution scope; no provider called")
    require(not any(getattr(args, name, None) is not None for name in ("provider", "max_cost_minor", "currency")),
            "live provider/cost options are invalid in offline mode")
    if args.command == "check-boundaries":
        return [dict(check_boundaries(root), ok=True)]
    if args.command in {"test", "pg-test", "e2e"}:
        selected = ((getattr(args, "suite", None) or args.scenario, args.command),)
    else:
        selected = REQUIRED[args.command]
    results = []
    missing = [key for key in selected if key not in SUITES]
    require(not missing, "required suite not implemented/registered: " + ", ".join(
        f"{name} ({partition})" for name, partition in missing))
    for key in selected:
        spec = SUITES.get(key)
        require(spec is not None, f"required suite not implemented/registered: {key[0]} ({key[1]})")
        require(key[1] != "pg-test" or spec.requires_pg, "PG partition must require real PostgreSQL")
        require(key[1] != "e2e" or (spec.requires_pg and spec.tier == "local-host-e2e"),
                "E2E requires real kernel + isolated PG partition")
        if args.command in {"qualify", "acceptance", "doctor"}:
            require(spec.mode != "tooling-self", "tooling self-check cannot qualify the platform")
        results.append(run_suite(root, spec, isolated=getattr(args, "isolated", False) or
                                 args.command in {"qualify", "acceptance", "doctor", "e2e"}))
    return results


def main(argv=None):
    args = parser().parse_args(argv)
    started = utc()
    report = {"command": [sys.executable, "tools/platform_gate.py", *(argv if argv is not None else sys.argv[1:])],
              "started_at": started, "producer": "platform_gate/P2.1", "results": [],
              "toolchain": {"python": sys.version, "platform": sys.platform},
              "cost": {"bound_minor": 0, "usage_minor": 0, "provider_calls": 0},
              "limitations": ["Private local evidence only; no live provider, host integration or production readiness claim."]}
    try:
        report["source"] = source_identity(ROOT)
        report["toolchain"].update(php=tool_version(["php", "--version"]),
                                   composer=tool_version(["composer", "--version"]))
        report["input_hashes"] = hashes(ROOT, [str(p.relative_to(ROOT)) for base in
            (ROOT / "spec/platform/v1", ROOT / "fixtures/platform/v1", ROOT / CORE, ROOT / TESTING)
            for p in sorted(base.rglob("*")) if p.is_file() and "vendor" not in p.parts
            and (p.suffix in {".json", ".lock", ".php", ".neon"})])
        report["input_hashes"].update(hashes(ROOT, (
            "spec/revisions/2026-09/revision.lock.json", "php/composer.json", "php/composer.lock",
            "docs/platform/architecture.json", "docs/platform/package-map.json",
            "docs/platform/bootstrap.json", "docs/platform/release-boundaries.json")))
        report["results"] = execute(args)
        report["ok"] = bool(report["results"]) and all(r["ok"] for r in report["results"])
    except (GateError, OSError, ValueError, subprocess.SubprocessError) as error:
        report.update(ok=False, error=str(error))
    report["finished_at"] = utc()
    if args.receipt:
        args.receipt.parent.mkdir(parents=True, exist_ok=True)
        for index, result in enumerate(report["results"]):
            for stream in ("stdout", "stderr"):
                if stream in result:
                    output = args.receipt.with_name(args.receipt.name + f".{index}.{stream}.log")
                    data = result.pop(stream).encode()
                    output.write_bytes(data)
                    result[stream] = {"path": str(output), "sha256": digest(data)}
    for result in report['results']:
        if result.get('ok') is True and (result.get('owner') in {'P12.1','P12.2'} or result.get('owner', '').startswith('P12.1-')):
            result['evaluation_receipt'] = evaluation_receipt(result, report)
    if args.receipt:
        args.receipt.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
