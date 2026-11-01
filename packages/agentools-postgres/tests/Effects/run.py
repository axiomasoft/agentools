#!/usr/bin/env python3
"""P5.3 exact durable partition, including injected F2 and actual SIGKILL recovery."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('effects_pg_lifecycle', HERE.parent / 'run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    'testOwnerCommitThenLocalJournalFailure': 'ACT-002',
    'testUnsupportedTimeoutNeverReplays': 'ACT-003',
    'testNativeKeyHashAndImmutableOutcome': 'ACT-004',
    'testAuthenticatedManualResolution': 'V2-P5-3-MANUAL-POS',
    'testCurrentEpochLookupAndStaleWorker': 'V2-P5-3-LOOKUP-POS',
    'testCommittedIntentAndReadBeforeWrite': 'V2ALL-EFFECT-POSITIVE',
    'testUnknownAndWrongBindingDenyReplay': 'V2ALL-EFFECT-NEGATIVE',
    'testResponseLossRetainsUnknownAndKnownFailure': 'V2ALL-EFFECT-FAULT',
    'testRetryBoundsAndCancellation': 'effect.retry-bounds-cancellation',
    'testProjectionRebuildAndCurrentAuthority': 'effect.projection-current-authority',
    'testNativeUnknownRetryUsesSameKey': 'effect.native-unknown-retry',
    'testReadAbsentRecoveryAndCapabilityBinding': 'effect.read-absent-recovery',
    'testDefinitionLimitAndCurrentEmergencyPolicy': 'effect.definition-current-policy',
    'testSigkillAfterOwnerCommit': 'effect.sigkill-after-owner-commit',
    'testCompetingRecovery': 'effect.competing-recovery',
}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'phpunit.xml', 'effect-reconcile', CASES))
