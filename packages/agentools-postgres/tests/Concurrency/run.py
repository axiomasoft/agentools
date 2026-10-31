#!/usr/bin/env python3
"""Owning claims/outbox partition using the proven isolated database lifecycle."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("postgres_test_lifecycle", HERE.parent / "run.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

CASES = {
    "testLostWakeScanner": "RUN-003",
    "testTwoWorkerStaleFence": "RUN-004",
    "testCommittedWake": "V2-P3-3-WAKE-POS",
    "testDuplicateAndRolledBackWake": "V2-P3-3-WAKE-NEG",
    "testCurrentEpochCheckpoint": "V2-P3-3-CLAIM-POS",
    "testAtomicStorageAndTenantConstraints": "V2ALL-STORAGE-POSITIVE",
    "testFenceDimensionsAndAuthorization": "V2ALL-STORAGE-NEGATIVE",
    "testRollbackAndRelayCrash": "V2ALL-STORAGE-FAULT",
}

if __name__ == "__main__":
    sys.exit(module.main(HERE / "phpunit.xml", "pg-claims-outbox", CASES))
