#!/usr/bin/env python3
"""P7.2 scoped publication integration on the proven isolated PostgreSQL lifecycle."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
spec = importlib.util.spec_from_file_location('profile_merge_pg_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
lifecycle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lifecycle)

CASES = {
    'testPinnedVersionReproducible': 'CM-003',
    'testCrossTenantPublicationDenied': 'CM-005',
    'testPublishedHashMutationDenied': 'CM-006',
    'testSameTenantPublication': 'V2-P7-2-TENANT-POS',
    'testCachedOwnerCorruption': 'V2-P7-2-TENANT-FAULT',
}

if __name__ == '__main__':
    output = io.StringIO()
    with contextlib.redirect_stdout(output):
        code = lifecycle.main(HERE / 'merge-phpunit.xml', 'profile-merge', CASES)
    report = json.loads(output.getvalue())
    import platform_gate
    report['input_hashes'] = platform_gate.hashes(ROOT, platform_gate.PROFILE_MERGE_INPUTS)
    print(json.dumps(report))
    sys.exit(code)
