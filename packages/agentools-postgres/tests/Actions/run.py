#!/usr/bin/env python3
"""Owning Action journal partition on the proven isolated PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("postgres_test_lifecycle", HERE.parent / "run.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

CASES = {
    "testDurableIntentBeforeEffect": "ACT-001",
    "testProjectionRebuild": "ACT-005",
    "testCommittedIntentOutsideLock": "V2-P3-4-INTENT-POS",
    "testDatedJournalAtomicReplay": "V2ALL-JOURNAL-POSITIVE",
    "testRejectMutableOrUncommittedState": "V2ALL-JOURNAL-NEGATIVE",
    "testRollbackAndCrashRecovery": "V2ALL-JOURNAL-FAULT",
}

if __name__ == "__main__":
    sys.exit(module.main(HERE / "phpunit.xml", "pg-action-journal", CASES))
