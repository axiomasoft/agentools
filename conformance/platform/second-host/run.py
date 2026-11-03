#!/usr/bin/env python3
"""Exact support-host contracts plus isolated PostgreSQL; scripted owners only."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


def run():
    rows = []
    isolated = None
    for command in (['php', str(HERE / 'boundary.php')], [sys.executable, '-B', str(HERE / 'pg.py')]):
        proc = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=120)
        sys.stderr.write(proc.stderr)
        if proc.returncode:
            raise RuntimeError('Required second host partition failed: ' + command[-1])
        part = json.loads(proc.stdout)
        if not part.get('cases') or part.get('failures') != 0 or part.get('skips') != 0:
            raise RuntimeError('Missing or failed second host cases')
        rows.extend(part['cases'])
        if 'isolation' in part:
            isolated = part['isolation']
    report = {'mode':'contract-scripted', 'tier':'contract-scripted', 'evidence_kind':'host_contract_harness',
              'cases':rows, 'count':len(rows), 'failures':0, 'skips':0, 'isolation':isolated,
              'input_hashes':gate.hashes(ROOT, gate.SECOND_HOST_INPUTS), 'live_provider':'not_executed',
              'production_readiness':'not_claimed',
              'limitations':['Synthetic owner and provider; no external host, live provider, pilot or production qualification.',
                             'Read and direct confirmed write are separate entry points; no support-specific assistant write is claimed.']}
    gate.validate_report(gate.SUITES[('second-host','test')],report)
    return report

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[], 'error':str(error)}))
        sys.exit(1)
