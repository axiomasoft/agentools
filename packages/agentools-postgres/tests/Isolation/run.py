#!/usr/bin/env python3
"""Exact full PostgreSQL contract, or a focused payload-only partition."""
import argparse
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent


def read_module(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


module = read_module(HERE.parent / "run.py", "postgres_test_lifecycle")
sys.path.insert(0, str(HERE.parents[3] / "tools"))
import platform_gate as gate

CASES = {}
for family in (gate.PG_SCHEMA_CASES, gate.PG_INTAKE_CASES, gate.PG_CLAIMS_OUTBOX_CASES, gate.PG_ACTION_JOURNAL_CASES):
    for case in family:
        if case.startswith("pg-"):
            CASES[case.split(".", 1)[1]] = case
for directory in ("Runtime", "Concurrency", "Actions"):
    CASES.update(read_module(HERE.parent / directory / "run.py", "partition_" + directory).CASES)
CASES.update({
    "testTenantFkStatusAndSequentialJobs": "TEN-001",
    "testValidTenantGraphAndAuditedPayload": "V2-P3-5-TENANT-POS",
    "testRollbackAndWorkerReset": "V2-P3-5-TENANT-FAULT",
    "testAtomicPayloadAndNoLockIo": "V2ALL-TENANT-POSITIVE",
    "testRejectForeignStaleAndExpiredPayload": "V2ALL-TENANT-NEGATIVE",
    "testPurgeCrashRollbackAndUnsupportedOwner": "V2ALL-TENANT-FAULT",
})

if __name__ == "__main__":
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--partition", choices=("full", "payloads"), default="full")
    selected, remaining = parser.parse_known_args()
    sys.argv = [sys.argv[0]] + remaining
    sys.exit(module.main(HERE / ("full.xml" if selected.partition == "full" else "phpunit.xml"),
                         "pg-isolation-retention", CASES))
