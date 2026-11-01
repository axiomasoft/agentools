#!/usr/bin/env python3
"""Real isolated PostgreSQL + headless host pipeline; external owners/provider are scripted."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[3]
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('postgres_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testSyntheticReadRequest': 'READ-001',
    'testToolAllowlistBeforeModel': 'READ-ALLOWLIST-NEG',
    'testCurrentOwnerAclBeforeResult': 'READ-DISCLOSURE-NEG',
    'testCrashAtEveryBoundary': 'READ-RECOVERY-FAULT',
    'testDeliveryAckLoss': 'READ-DELIVERY-FAULT',
    'testDeliveryCurrentVisibility': 'READ-DELIVERY-NEG',
    'testStableIdentityAndHashConflict': 'READ-IDENTITY-NEG',
    'testBudgetOutageAndCurrentEmergency': 'READ-GUARDS-NEG',
    'testSecondGenericHost': 'READ-SECOND-HOST',
    'testRunnableExample': 'READ-EXAMPLE',
    'testNoRawOwnerDataAndEncryptedVault': 'READ-PRIVACY',
    'testSnapshotPinsAndTokenManifest': 'READ-CONTEXT',
    'testF1PreSendDenialIsRecoverable': 'READ-F1-NOT-SENT',
    'testF2PreSendDeliveryDoesNotExhaust': 'READ-F2-PRE-SEND',
}
if __name__ == '__main__':
    output = io.StringIO()
    with contextlib.redirect_stdout(output):
        exit_code = module.main(HERE / 'phpunit.xml', 'read-journey', CASES)
    report = json.loads(output.getvalue())
    report.update(mode='local-host-e2e', tier='local-host-e2e', transport='scripted-support/v1')
    print(json.dumps(report))
    sys.exit(exit_code)
