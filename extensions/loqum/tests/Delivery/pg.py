#!/usr/bin/env python3
"""Focused delivery classes in the proven isolated PostgreSQL environment."""
import importlib.util
from pathlib import Path
import sys

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
spec=importlib.util.spec_from_file_location('voice_delivery_pg',ROOT/'packages/agentools-postgres/tests/run.py')
runner=importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
CASES={
    'testIndependentPortions':'voice-delivery.independent-portions',
    'testSuffixRetry':'voice-delivery.suffix-retry',
    'testLostReceiptRecovery':'voice-delivery.lost-receipt',
    'testUnknownReconnectAndStaleReceipt':'voice-delivery.unknown-reconnect',
    'testBargeInAndCommands':'voice-delivery.barge-in',
    'testDisclosureAndRevoke':'voice-delivery.disclosure-revoke',
    'testTtsOutageTextContinuity':'voice-delivery.tts-outage-text',
    'testIdentityConflictsAndSchema':'voice-delivery.identity-schema-conflicts',
    'testUiCommandGuards':'voice-delivery.command-guards',
    'testReceiptBindingNegatives':'voice-delivery.receipt-binding',
    'testCrashBeforePlaybackRecovery':'voice-delivery.sigkill-before-playback',
    'testExactVoiceApproval':'voice-delivery.exact-approval',
    'testUnboundApprovalDenials':'voice-delivery.approval-denials',
    'testInterruptionDoesNotCancelEffect':'voice-delivery.effect-survives-interruption',
    'testApprovalRevokeBeforeOwnerCommit':'voice-delivery.owner-revoke-after-approval',
}
if __name__=='__main__':
    sys.exit(runner.main(HERE/'phpunit.xml',case_names=CASES))
