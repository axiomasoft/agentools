#!/usr/bin/env python3
"""Exact PHP/Dart technical usage parity; no provider or financial owner calls."""
import json
from pathlib import Path
import subprocess
import sys
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate

def main():
    reports = {}
    for language, command, cwd in (
        ('php', ['php', 'packages/agentools-core/tests/Usage/portable.php'], ROOT),
        ('dart', ['dart', 'run', 'test/platform/usage_worker.dart'], ROOT / 'dart/agentools'),
    ):
        process = subprocess.run(command, cwd=cwd, text=True, capture_output=True, timeout=90)
        sys.stderr.write(process.stderr)
        if process.returncode != 0:
            raise RuntimeError(language + ' portable worker failed')
        reports[language] = json.loads(process.stdout)
    report = {'mode':'contract-scripted','tier':'contract-scripted', 'languages':reports,
              'cases':[{'id':name,'status':'passed'} for name in gate.USAGE_PORTABLE_CASES],
              'count':len(gate.USAGE_PORTABLE_CASES),'failures':0,'skips':0}
    gate.validate_report(gate.SUITES[('usage-parity','test')], report)
    print(json.dumps(report))
    return 0

if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        print(json.dumps({'error':str(error),'cases':[],'failures':1,'skips':0}))
        sys.exit(1)
