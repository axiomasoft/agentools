#!/usr/bin/env python3
"""Observability durable proofs on the existing proven isolated PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
source=importlib.util.spec_from_file_location('observability_pg',ROOT/'packages/agentools-postgres/tests/run.py')
runner=importlib.util.module_from_spec(source);source.loader.exec_module(runner)
CASES={
 'testEveryEntryHasSafeTraceAndBoundedProviderMetrics':'privacy.managed-trace-entry-parity',
 'testAuditRequiredOptionalMetricsVisibleAndNoGuardBypass':'privacy.managed-failure-policy',
 'testRecycledWorkerAndRetiredTransportKeepTenantIsolation':'privacy.managed-worker-reset',
 'testReadOnlyInspectionAndAuditedRecoveryStaySeparate':'privacy.operations-readonly-audit',
 'testResetFailureQuarantinesDiagnosticScope':'privacy.diagnostic-reset-quarantine',
 'testUnknownProviderAndDeliveryKeepSeparateOutcomes':'privacy.unknown-outcomes',
 'testEncryptedSeparateGrantAndMetadataOnlyInspection':'privacy.encrypted-diagnostic-access',
 'testAuditFailureResolverOutageAndRevokeBeforeRelease':'privacy.audit-resolver-revoke',
 'testExpiryCrossTenantAndCipherBinding':'privacy.expiry-tenant-integrity',
 'testRetentionHoldAndTombstone':'privacy.retention-tombstone',
 'testAuditIdentityAndTraceGraphUseExistingDurableRefs':'privacy.audit-durable-graph',
}
if __name__=='__main__':sys.exit(runner.main(HERE/'phpunit.xml','observability',CASES))
