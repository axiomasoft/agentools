#!/usr/bin/env python3
"""Exact calendar unit cases; no PostgreSQL or provider needed."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]

def run(method=None):
    matrix = json.loads((ROOT / 'fixtures/platform/v1/scheduler/cases.json').read_text())['calendar']
    with tempfile.TemporaryDirectory(prefix='agentools_schedule_calendar_') as directory:
        junit = Path(directory) / 'junit.xml'
        argv = ['php', str(HERE.parent / 'vendor/bin/phpunit'), '-c', str(HERE / 'calendar.xml'), '--log-junit', str(junit)]
        if method:
            argv += ['--filter', method]
        process = subprocess.run(argv, cwd=ROOT, text=True, capture_output=True, timeout=40)
        sys.stderr.write(process.stdout + process.stderr)
        cases = []
        if junit.is_file():
            for case in ET.parse(junit).iter('testcase'):
                status = ('skipped' if case.find('skipped') is not None else
                    'failed' if case.find('failure') is not None or case.find('error') is not None else 'passed')
                cases.append({'id': matrix.get(case.attrib['name'], case.attrib['name']), 'status': status})
        report = {'mode': 'unit', 'tier': 'unit', 'cases': cases,
            'count': len(cases), 'failures': sum(c['status'] == 'failed' for c in cases), 'skips': sum(c['status'] == 'skipped' for c in cases)}
        return report, 0 if process.returncode == 0 and cases and all(c['status'] == 'passed' for c in cases) else 1

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--filter')
    args = parser.parse_args()
    report, code = run(args.filter)
    print(json.dumps(report))
    sys.exit(code)
