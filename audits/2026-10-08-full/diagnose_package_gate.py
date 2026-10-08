#!/usr/bin/env python3
"""Run package staging alone and retain diagnostics normally dropped by receipts.

Usage: python3 diagnose_package_gate.py /absolute/path/to/disposable/repo
The gate uses disposable package directories; its source tree must be quiescent.
"""
import json
from pathlib import Path
import sys

sys.path.insert(0, sys.argv[1])
from tools import package_gate

original = package_gate.run_command


def logged(*args, **kwargs):
    result = original(*args, **kwargs)
    if result.exit_code:
        print(json.dumps({'command': result.argv, 'exit_code': result.exit_code,
                          'stdout': result.stdout[-5000:], 'stderr': result.stderr[-5000:]}), flush=True)
    return result


package_gate.run_command = logged
payload = package_gate.run_gate()
destination = Path(__file__).resolve().parent / 'evidence' / 'package-diagnostic-receipt.json'
destination.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + '\n')
print(json.dumps({'status': payload['status'], 'errors': payload['errors']}))
raise SystemExit(0 if payload['status'] == 'green' else 1)
