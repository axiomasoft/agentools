#!/usr/bin/env python3
"""Focused invalidation class/file runner. The acceptance wrapper enforces its full exact set."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]

def run(filter_name=None):
    names = json.loads((HERE / 'cases.json').read_text())['unit']
    with tempfile.TemporaryDirectory(prefix='invalidation_unit_') as directory:
        junit = Path(directory) / 'junit.xml'
        argv = ['php', str(HERE.parents[1] / 'vendor/bin/phpunit'), '-c', str(HERE / 'phpunit.xml'), '--log-junit', str(junit)]
        if filter_name:
            argv += ['--filter', filter_name]
        result = subprocess.run(argv, cwd=ROOT, capture_output=True, text=True, timeout=60)
        sys.stderr.write(result.stdout + result.stderr)
        cases = []
        if junit.is_file():
            for case in ET.parse(junit).iter('testcase'):
                status = 'skipped' if case.find('skipped') is not None else 'failed' if case.find('failure') is not None or case.find('error') is not None else 'passed'
                cases.append({'id':names.get(case.attrib['name'],case.attrib['name']), 'status':status})
        report = {'mode':'contract-scripted', 'tier':'contract-scripted', 'cases':cases, 'count':len(cases),
            'failures':sum(c['status']=='failed' for c in cases), 'skips':sum(c['status']=='skipped' for c in cases)}
        if result.returncode or not cases or report['failures'] or report['skips']:
            raise RuntimeError('Invalidation tests failed: ' + json.dumps(report))
        return report

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--filter')
    args = parser.parse_args()
    print(json.dumps(run(args.filter)))
