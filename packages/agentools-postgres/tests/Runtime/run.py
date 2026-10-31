#!/usr/bin/env python3
"""Owning intake partition; reuse the proven database lifecycle unchanged."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("postgres_test_lifecycle", HERE.parent / "run.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

CASES = {
    "testConcurrentSameSource": "RUN-001",
    "testSourceHashConflict": "RUN-002",
    "testConflictResponseLost": "V2-P3-2-INTAKE-FAULT",
    "testStableIdentityRoundtrip": "V2ALL-IDENTITY-POSITIVE",
    "testIdentitySchemeConflict": "V2ALL-IDENTITY-NEGATIVE",
    "testCommitBeforeLostWakeup": "V2ALL-IDENTITY-FAULT",
}

if __name__ == "__main__":
    sys.exit(module.main(HERE / "phpunit.xml", "pg-intake", CASES))
