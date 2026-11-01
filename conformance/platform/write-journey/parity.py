#!/usr/bin/env python3
"""Exact declared portable subset; independent reference and actual current PHP/Dart SDKs."""
import argparse
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[3]
CONSUMERS = ('reference', 'php-sdk', 'dart-sdk')

def validate(fixtures, reports):
    expected = {item['id']: item['expected'] for item in fixtures}
    if len(expected) != 39 or len(fixtures) != 39:
        raise ValueError('Exact required portable fixture set changed')
    if len(reports) != 3 or {r['consumer'] for r in reports} != set(CONSUMERS):
        raise ValueError('All three independent consumers are required')
    cases = []
    for report in reports:
        ids = [row['id'] for row in report['cases']]
        if len(ids) != len(set(ids)) or set(ids) != set(expected) or report['tier'] != 'contract-scripted':
            raise ValueError('Missing, extra, duplicate or misqualified portable cases')
        for row in report['cases']:
            if json.dumps(row['observed'], sort_keys=True) != json.dumps(expected[row['id']], sort_keys=True):
                raise ValueError(report['consumer'] + ' disagrees on ' + row['id'])
            cases.append({'id': report['consumer'] + '.' + row['id'], 'status': 'passed'})
    return {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases,
            'count': len(cases), 'failures': 0, 'skips': 0, 'consumers': reports}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--reports', nargs=3, type=Path, help='Validate previously captured actual worker outputs')
    args = parser.parse_args()
    try:
        fixtures = json.loads((ROOT / 'fixtures/platform/v1/recovery/portable.json').read_text())['cases']
        if args.reports:
            reports = [json.loads(p.read_text()) for p in args.reports]
        else:
            commands = [(('php', 'conformance/platform/write-journey/reference.php'), ROOT),
                        (('php', 'php/tests/Recovery/worker.php'), ROOT),
                        (('dart', 'run', 'test/platform/write_recovery_worker.dart'), ROOT / 'dart/agentools')]
            reports = []
            for command, cwd in commands:
                result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, timeout=90)
                sys.stderr.write(result.stderr)
                if result.returncode:
                    raise ValueError('Required portable worker failed: ' + command[0])
                reports.append(json.loads(result.stdout))
        print(json.dumps(validate(fixtures, reports)))
        return 0
    except (ValueError, KeyError, OSError, subprocess.SubprocessError) as error:
        print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': [], 'error': str(error)}))
        return 1

if __name__ == '__main__':
    sys.exit(main())
