#!/usr/bin/env python3
"""Isolated database lifecycle for the synthetic support host."""
import importlib.util
from pathlib import Path
import sys
HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
spec = importlib.util.spec_from_file_location('postgres_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {'testRuntimeAndDelivery': 'support.runtime-delivery', 'testCurrentGuard': 'support.current-guard',
         'testDeliveryLostAck': 'support.delivery-lost-ack', 'testOwnerRevokedBeforeWrite': 'support.owner-revoke',
         'testSourceHashConflict': 'support.source-conflict', 'testInvalidContextProvider': 'support.invalid-context-provider', 'testIndependentConfirmedAction': 'support.direct-confirmed-action'}
if __name__ == '__main__':
    sys.exit(module.main(HERE / 'phpunit.xml', 'second-host', CASES))
