#!/usr/bin/env python3
"""Owning bounded operations facade cases in the proven isolated PG lifecycle."""
import importlib.util
from pathlib import Path
import sys
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[4]
source = importlib.util.spec_from_file_location('ops_pg', ROOT / 'packages/agentools-postgres/tests/run.py')
runner = importlib.util.module_from_spec(source)
source.loader.exec_module(runner)
CASES = {
    'testDoctorReadOnlyFakeExpiryAndMissingProbes': 'CM-049',
    'testDryRunAuditWaitingReasonsAndCursor': 'V2ALL-OPS-POSITIVE',
    'testSameRunRecoveryReplayConflictAndUnknownOwner': 'CM-050',
    'testRevocationBeforeDispatchAndCrossSessionDenied': 'V2ALL-OPS-NEGATIVE',
    'testStableDeliveryUnknownAckAndAlreadyDelivered': 'operations.stable-delivery',
    'testFailureBetweenCommitsRemainsUnknownAndRetrySameIdentity': 'V2ALL-OPS-FAULT',
    'testUnknownInvocationAndMissingProposalAreDistinct': 'operations.invocation-proposal-identities',
    'testDependencyFaultAndCorruptReceiptFailClosed': 'operations.dependency-corrupt-proof',
    'testRelayOnlyExplicitUsageKeyAndRecoverLostAck': 'operations.usage-lost-ack',
    'testKeyedDispatcherCannotConsumeAnotherPendingExport': 'operations.usage-exact-key',
    'testCliTrustedHandleDryRunApplyAndRedactedFault': 'operations.cli-trusted-handle',
    'testPruneExpiredPayloadKeepsTombstoneWithoutDereference': 'operations.prune-tombstone',
    'testOwnerLookupRecordsOriginalEffectWithoutReplay': 'operations.owner-lookup-no-replay',
    'testPruneRetainsRetentionAndLegalHold': 'operations.retention-holds',
}
if __name__ == '__main__':
    sys.exit(runner.main(HERE / 'phpunit.xml', 'operations', CASES))
