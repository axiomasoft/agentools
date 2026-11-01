#!/usr/bin/env python3
"""Real Runtime/Actions + isolated PostgreSQL; external provider/owner/destination are scripted."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[3]
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('write_pg_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testConfirmedWriteJourney': 'WRITE-001',
    'testDirectAndAssistantShareGuardsAndIdentity': 'WRITE-DIRECT-PARITY',
    'testRevokeAndEmergencyBeforeOwnerWrite': 'WRITE-REVOKE',
    'testF2OwnerCommitLocalJournalFailure': 'WRITE-F2-OWNER-COMMIT',
    'testTenantInstanceSwitchAndHashConflict': 'WRITE-TENANT-INSTANCE',
    'testCrashAtEveryBoundary': 'WRITE-CRASH-MATRIX',
    'testLiveZombieAfterLeaseExpiry': 'WRITE-ZOMBIE',
    'testConcurrentSourceDelivery': 'WRITE-SOURCE-RACE',
    'testDrainUnknownEffect': 'WRITE-DRAIN',
    'testAtomicDurableBoundaries': 'WRITE-ATOMIC-MATRIX',
    'testCompatibleRollbackReader': 'WRITE-ROLLBACK',
    'testPrivacyAndResultBinding': 'WRITE-PRIVACY',
}
def main(configuration=HERE / 'phpunit.xml', case_names=CASES):
    output = io.StringIO()
    with tempfile.TemporaryDirectory(prefix='agentools_write_evidence_') as directory:
        os.environ['AGENTOOLS_WRITE_EVIDENCE'] = directory
        with contextlib.redirect_stdout(output):
            exit_code = module.main(configuration, 'write-recovery', case_names)
        report = json.loads(output.getvalue())
        report['observations'] = {p.stem: json.loads(p.read_text()) for p in Path(directory).glob('*.json')}
    ids = {case['id'] for case in report['cases']}
    for required, name in [('WRITE-CRASH-MATRIX', 'crash-matrix'), ('WRITE-ATOMIC-MATRIX', 'atomic-matrix'),
                           ('WRITE-ZOMBIE', 'zombie'), ('WRITE-ROLLBACK', 'rollback')]:
        if required in ids and name not in report['observations']:
            report['error'] = 'Missing required substantive observations: ' + name
            exit_code = 1
    report.update(mode='local-host-e2e', tier='local-host-e2e', transport='scripted-write/v1')
    print(json.dumps(report))
    return exit_code

if __name__ == '__main__':
    sys.exit(main())
