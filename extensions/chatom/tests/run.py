#!/usr/bin/env python3
"""Conductor partitions: public contracts and real isolated PostgreSQL boundary journeys."""
import argparse
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


def run(pg=False):
    rows = []
    observations = {}
    isolation = None
    for name in (('delivery.php', 'recovery.php') if pg else ('contracts.php', 'no-dev/check.php')):
        process = subprocess.run(['php', str(HERE / name)], cwd=ROOT, text=True, capture_output=True, timeout=120)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        mode = 'isolated-postgres' if pg else 'contract-scripted'
        if process.returncode or report.get('mode') != mode or report.get('tier') != mode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required Chatom file failed: ' + name)
        rows.extend(report['cases'])
        if pg:
            fact = report['isolation']
            if isolation is not None and any(fact.get(k) != isolation.get(k) for k in ('engine', 'isolated', 'database', 'owner', 'allow_marker_verified')):
                raise RuntimeError('Chatom component isolation proofs differ')
            isolation = fact
            observations.update(report['observations'])
    mode = 'isolated-postgres' if pg else 'contract-scripted'
    qualification = json.loads((HERE.parent / 'pin-matrix.json').read_text())['qualification']
    result = {'mode': mode, 'tier': mode, 'evidence_kind': 'host_contract_harness',
              'cases': rows, 'count': len(rows), 'failures': 0, 'skips': 0,
              'input_hashes': gate.hashes(ROOT, gate.CHATOM_INPUTS), 'qualification': qualification,
              'limitations': ['Scripted host/destination boundaries; completed-generation precondition is seeded, not a provider/kernel execution.',
                              'Native PG repositories/encrypted bytes/derivation graph are real. No actual Chatom driver/pin or remote host/live/pilot qualification.']}
    if pg:
        result.update(isolation=dict(isolation, case_ids=[r['id'] for r in rows]), observations=observations)
    gate.validate_report(gate.SUITES[('chatom', 'pg-test' if pg else 'test')], result)
    return result


if __name__ == '__main__':
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('--pg', action='store_true')
    args = cli.parse_args()
    try:
        print(json.dumps(run(args.pg)))
    except Exception as error:
        print(json.dumps({'cases': [], 'error': str(error)}))
        sys.exit(1)
