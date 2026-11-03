#!/usr/bin/env python3
"""Exact cache contract/durability partitions. Never substitutes mocks for the PostgreSQL tier."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]


def run(postgres=False):
    reports = []
    names = ('durable', 'processes') if postgres else ('keys', 'lifecycle', 'warm')
    for name in names:
        process = subprocess.run(['php', str(HERE / (name + '.php'))], cwd=ROOT, text=True, capture_output=True, timeout=60)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        if process.returncode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required cache partition failed: ' + name)
        if not isinstance(report.get('observations'), dict):
            raise RuntimeError('Cache observations must be an object: ' + name)
        reports.append(report)
    rows = [row for report in reports for row in report['cases']]
    observations = {key: value for report in reports for key, value in report['observations'].items()}
    isolation = None
    if postgres:
        isolation = dict(reports[0]['isolation'])
        if any(any(report['isolation'].get(k) != isolation[k] for k in ('database', 'owner', 'allow_marker_verified')) for report in reports):
            raise RuntimeError('Inconsistent isolated PostgreSQL proof')
        isolation['case_ids'] = [row['id'] for row in rows]
    sys.path.insert(0, str(ROOT / 'tools'))
    import platform_gate
    tier = 'isolated-postgres' if postgres else 'contract-scripted'
    return {'mode': tier, 'tier': tier, 'cases': rows, 'count': len(rows), 'failures': 0, 'skips': 0,
            'isolation': isolation, 'observations': observations,
            'input_hashes': platform_gate.hashes(ROOT, platform_gate.CACHE_MANAGER_INPUTS),
            'limitations': ['Scripted provider cache/authorization/budget/pricing ports; no actual provider cache API or price qualification.',
                            'Real PostgreSQL durability only in the pg-test partition; no live provider spend or production rollout.',
                            'Warm unknown facts and original admission keys are retained for the authoritative reservation reconciler.']}


if __name__ == '__main__':
    try:
        print(json.dumps(run('--postgres' in sys.argv[1:])))
    except Exception as error:
        print(json.dumps({'cases': [], 'error': str(error)}))
        sys.exit(1)
