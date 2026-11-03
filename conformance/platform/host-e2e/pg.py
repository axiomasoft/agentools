#!/usr/bin/env python3
"""Actual isolated PostgreSQL lifecycle for local host journey cases."""
import importlib.util
from pathlib import Path
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[2]
spec=importlib.util.spec_from_file_location('postgres_lifecycle',ROOT/'packages/agentools-postgres/tests/run.py')
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES={'testSchoolJourneyAndDuplicate':'host-e2e.school-duplicate', 'testSchoolRevocation':'host-e2e.school-revoke', 'testSchoolRevocationBeforeModelIo':'host-e2e.school-revoke-preflight',
       'testSupportJourneyAndHashConflict':'host-e2e.support-conflict', 'testLostDeliveryReceipt':'host-e2e.lost-receipt',
       'testDeliveryOutageBeforeDispatch':'host-e2e.delivery-outage'}
if __name__=='__main__': sys.exit(module.main(HERE/'phpunit.xml','host-e2e',CASES))
