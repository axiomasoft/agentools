#!/usr/bin/env python3
"""Cancellation/operator partition using the proven isolated PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
spec = importlib.util.spec_from_file_location('operator_pg_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testCancellationWithUnresolvedEffect': 'CANCEL-001',
    'testCompletedEffectSurvivesTtsStop': 'V2-P5-4-TTS-POS',
    'testIndependentRunActionAndOutputCommands': 'V2ALL-CANCEL-POSITIVE',
    'testInterruptedOutputNeverClaimsWholeDelivery': 'V2ALL-CANCEL-NEGATIVE',
    'testCancellationFaultRetainsPartialDelivery': 'V2ALL-CANCEL-FAULT',
    'testManualReceiptAtomicRollback': 'cancel.manual-atomic',
    'testAuditedManualResolutionCasAndReplay': 'cancel.manual-cas-replay',
    'testAuthorityTenantVersionAndImmutableAudit': 'cancel.authority-immutable-audit',
    'testSourceClosureLeavesUnknownVisible': 'cancel.source-closure-unknown',
    'testExplicitCompensationRequiresFreshConfirmation': 'cancel.explicit-compensation',
    'testCompensationPolicyAndUnknownDenied': 'cancel.compensation-guards',
    'testCrashAfterCancellationCommit': 'cancel.crash-after-commit',
    'testCompetingCancellation': 'cancel.competing-commands',
}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'phpunit.xml', 'cancellation-resolution', CASES))
