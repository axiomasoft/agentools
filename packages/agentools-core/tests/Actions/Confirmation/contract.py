#!/usr/bin/env python3
"""Exact portable confirmation partition; no database or provider IO."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[5]
rows = []
for command, cwd in (
    (['php', 'packages/agentools-core/tests/Actions/Confirmation/binding.php'], ROOT),
    (['php', 'php/tests/Confirmation/platform-binding.php'], ROOT),
    (['dart', 'test', '--reporter=json', 'test/confirmation/platform_binding_test.dart'], ROOT / 'dart/agentools'),
):
    process = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=45)
    sys.stderr.write(process.stderr)
    if process.returncode:
        sys.stderr.write(process.stdout)
        sys.exit(process.returncode)
    if command[0] == 'php':
        report = json.loads(process.stdout)
        if report['count'] < 1 or report['failures'] or report['skips']:
            raise RuntimeError('Missing or non-green required PHP evidence')
        rows.extend(report['cases'])
    else:
        names = {}
        for line in process.stdout.splitlines():
            event = json.loads(line)
            if event['type'] == 'testStart' and event['test']['name'].startswith('dart.confirmation.'):
                names[event['test']['id']] = event['test']['name']
            if event['type'] == 'testDone' and event['testID'] in names:
                rows.append({'id': names[event['testID']], 'status': 'passed'
                    if event['result'] == 'success' and not event['skipped'] else 'failed'})
print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': rows,
                  'count': len(rows), 'failures': sum(c['status'] != 'passed' for c in rows), 'skips': 0}))
