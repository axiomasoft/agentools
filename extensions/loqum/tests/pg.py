#!/usr/bin/env python3
"""Only the ingress test file, in the repository-proven disposable PostgreSQL."""
import importlib.util
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
spec = importlib.util.spec_from_file_location('voice_pg_runner', ROOT/'packages/agentools-postgres/tests/run.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)

CASES = {
    'testReconnectLostReceipt': 'voice.pg-reconnect-lost-receipt',
    'testFinalHashAndRevisionConflict': 'voice.pg-final-hash-revision-conflict',
    'testInterimAndStaleDoNotEnqueue': 'voice.pg-interim-stale-no-enqueue',
    'testTextFallbackAndBargeIn': 'voice.pg-text-fallback-barge-in',
    'testTenantAndSessionIsolation': 'voice.pg-tenant-session-isolation',
}

if __name__ == '__main__':
    sys.exit(runner.main(HERE/'phpunit.xml', case_names=CASES))
