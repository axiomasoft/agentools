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
    "architecture_fixture", "boundary_current_tree", "boundary_missing_source",
    "boundary_production_testing", "boundary_duplicate_owner", "boundary_core_dependency",
    "boundary_autoload_escape", "boundary_transitive_testing", "boundary_namespace_path",
    "boundary_production_source", "contradictory_totals",
    "sdk_adapter_registry", "model_transport_registry", "platform_registry", "fsm_registry", "testkit_registry", "pg_schema_registry", "pg_intake_registry", "aggregate_preflight",
    "cli_missing_suite", "cli_wrong_mode", "cli_missing_pg_isolation", "cli_future_commands",
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
    'migrations/0005_delivery_resolution.sql',
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
    for field, expected in (("count", len(cases)), ("failures", 0), ("skips", 0)):
        if field in report:
            require(type(report[field]) is int and report[field] == expected,
                    f"contradictory runner total: {field}")
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
    return {"count": len(cases), "failures": 0, "skips": 0, "case_ids": ids}


def run_suite(root: Path, spec: Suite, *, isolated=False, timeout=120):
    require(not spec.requires_pg or isolated, "required PG suite needs --isolated")
    require(spec.command and spec.cases, "suite has no runner/cases")
    inputs = hashes(root, spec.dependencies)
    try:
        process = subprocess.run(spec.command, cwd=root, text=True, capture_output=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise GateError(f"runner unavailable/timeout: {error}") from error
    result = {"command": list(spec.command), "owner": spec.owner, "mode": spec.mode,
              "tier": spec.tier, "dependency_hashes": inputs, "exit": process.returncode,
              "stdout": process.stdout, "stderr": process.stderr}
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
    return result


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
    "Common": set(), "Security": {"Common"}, "Profiles": {"Common"},
    "Context": {"Common", "Security", "Profiles"},
    "Models": {"Common", "Profiles", "Context"},
    "Actions": {"Common", "Security", "Profiles"},
    "Runtime": {"Common", "Security", "Profiles", "Context", "Models", "Actions"},
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
        args.receipt.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
