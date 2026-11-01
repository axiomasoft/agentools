#!/usr/bin/env python3
"""Durable invocation partition using the proven isolated PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("postgres_test_lifecycle", HERE.parent / "run.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {
    "testLostProviderReply": "RUN-005",
    "testPersistedProposalsAfterKill": "RUN-006",
    "testMappedSubmission": "V2-P4-3-PROPOSAL-POS",
    "testNoReconstruction": "V2-P4-3-PROPOSAL-NEG",
    "testVerifiedLookup": "V2-P4-3-PROVIDER-POS",
    "testUnknownCannotBecomeZeroOrSuccess": "V2-P4-3-PROVIDER-NEG",
    "testOrderedBoundaries": "V2ALL-RUN-POSITIVE",
    "testGuardsAndBounds": "V2ALL-RUN-NEGATIVE",
    "testKilledWorkerBoundaries": "V2ALL-RUN-FAULT",
}
if __name__ == "__main__":
    sys.exit(module.main(HERE / "phpunit.xml", "pg-models", CASES))
