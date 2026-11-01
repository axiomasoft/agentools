"""Aggregate only the direct-action contract files; no provider or database IO."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[4]
rows = []
for command, cwd in (
    (['php', 'packages/agentools-core/tests/Actions/submit.php'], ROOT),
    (['dart', 'test', '--reporter=json', 'test/platform/action_submit_test.dart'], ROOT / 'dart/agentools'),
):
    process = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=40)
    sys.stderr.write(process.stderr)
    if process.returncode:
        sys.stderr.write(process.stdout)
        sys.exit(process.returncode)
    if command[0] == 'php':
        rows.extend(json.loads(process.stdout)['cases'])
    else:
        names = {}
        for line in process.stdout.splitlines():
            event = json.loads(line)
            if event['type'] == 'testStart' and event['test']['name'].startswith('dart.action.'):
                names[event['test']['id']] = event['test']['name']
            if event['type'] == 'testDone' and event['testID'] in names:
                rows.append({'id': names[event['testID']], 'status': 'passed'
                    if event['result'] == 'success' and not event['skipped'] else 'failed'})
print(json.dumps({'mode': 'unit', 'tier': 'unit', 'cases': rows, 'count': len(rows),
                  'failures': sum(row['status'] != 'passed' for row in rows), 'skips': 0}))
