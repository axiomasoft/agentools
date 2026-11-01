#!/usr/bin/env python3
"""Bound confirmation partition; delegates proven isolated PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[4]
spec = importlib.util.spec_from_file_location('confirmation_pg_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testValidConsumesOnce': 'V2-P5-2-CONFIRM-POS',
    'testRevokeBeforeOwnerWrite': 'CNF-002',
    'testConcurrentConsume': 'CNF-004',
    'testExactBindings': 'V2ALL-CONFIRM-NEGATIVE',
    'testPositiveBindingsAndVoice': 'V2ALL-CONFIRM-POSITIVE',
    'testCrashResponseLossAndEpoch': 'V2ALL-CONFIRM-FAULT',
    'testDurablePreparationRollback': 'confirmation.atomic-journal-proof',
    'testCancellationAndCompensation': 'confirmation.cancel-compensation',
    'testRunnableExample': 'confirmation.runnable-example',
    'testOwnerCommitJournalFailureReconcilesSameKey': 'confirmation.owner-commit-journal-failure',
    'testOwnerReceiptOutageRetainsApplyingUnknown': 'confirmation.owner-receipt-outage',
    'testOwnerReconciliationRequiresBoundReceiptAndCurrentAuthority': 'confirmation.owner-reconciliation-guards',
}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'phpunit.xml', 'confirmation-write', CASES))
