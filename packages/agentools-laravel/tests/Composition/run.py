#!/usr/bin/env python3
"""Mandatory isolated PostgreSQL composition cases; reuses the proven safe bootstrap."""
import importlib.util
from pathlib import Path
import sys
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
spec = importlib.util.spec_from_file_location('laravel_pg_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testCrossTenantGuardEvidenceDenied': 'managed.evidence-tenant',
    'testCleanupRollsBackAndDiscardsDatabaseSession': 'managed.pg-session-reset',
    'testAcceptedSourceCannotStartSecondLoop': 'managed.single-loop-replay',
    'testEveryEntrySharesMandatoryAdmission': 'CM-041',
    'testTenantAThenBOnRecycledWorker': 'CM-042',
    'testGuardDenialStopsLaterStages': 'V2ALL-MANAGED-POSITIVE',
    'testUntrustedToolAndPluginFlagsGrantNothing': 'V2ALL-MANAGED-NEGATIVE',
    'testExceptionCancelAndOctaneResetAllState': 'V2ALL-MANAGED-FAULT',
    'testRevocationBeforeDisclosurePreventsDelivery': 'managed.disclosure-revocation',
    'testUnknownBudgetCannotReachModelOrAction': 'managed.budget-unknown',
    'testUnknownDeliveryIsNotZeroOrSuccess': 'managed.delivery-unknown',
    'testProductionAndTestingProfileFailClosed': 'managed.production-no-fake',
    'testResetFailureIsRedactedAndPoisonsScope': 'managed.reset-failure',
    'testCleanupAttemptsAllPortsAfterStoreFailure': 'managed.reset-all-ports',
    'testOctaneCleansEventSandboxAndUnusedPorts': 'managed.octane-sandbox',
    'testAccountingFailureIsNotOptionalTelemetry': 'managed.accounting-failure',
    'testCommandUsesManagedAdmission': 'managed.command-admission',
    'testProviderIsOptInScopedAndHasNoRoutes': 'managed.opt-in-provider',
}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'phpunit.xml', 'laravel-composition', CASES))
