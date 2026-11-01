#!/usr/bin/env python3
"""Scoped direct Action intake/status partition on proven isolated PostgreSQL."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('postgres_lifecycle', HERE.parent / 'run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testReplayAndConflict': 'ACT-SUBMIT-REPLAY',
    'testStatusScope': 'ACT-STATUS-DISCLOSURE',
    'testCurrentGuardsAndInvalidInput': 'ACT-SUBMIT-GUARDS',
    'testAtomicRollbackAndImmutableOrigin': 'ACT-SUBMIT-ATOMIC',
    'testProposalMappingSurvivesRetry': 'ACT-SUBMIT-PROPOSAL',
    'testCompetingConnections': 'ACT-SUBMIT-RACE',
    'testCrashRestart': 'ACT-SUBMIT-CRASH',
    'testUnknownAndRecoveryFailClosed': 'ACT-STATUS-UNKNOWN',
    'testDirectExample': 'ACT-DIRECT-NO-MODEL',
}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'submit-phpunit.xml', 'action-submit', CASES))
