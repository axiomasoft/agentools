#!/usr/bin/env python3
"""Exact scripted-port and isolated durable partitions; missing tests/skips fail closed."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]


def run(postgres=False):
    reports = []
    for name in (('durable', 'recovery', 'concurrency') if postgres else ('ports', 'policy')):
        p = subprocess.run(['php', str(HERE / (name + '.php'))], cwd=ROOT, text=True, capture_output=True, timeout=60)
        sys.stderr.write(p.stderr)
        report = json.loads(p.stdout)
        if p.returncode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required accounting partition failed: ' + name)
        reports.append(report)
    rows = [row for report in reports for row in report['cases']]
    observations = {k: v for report in reports for k, v in report.get('observations', {}).items()}
    tier = 'isolated-postgres' if postgres else 'contract-scripted'
    isolation = None
    if postgres:
        isolation = dict(reports[0]['isolation'])
        if any(any(r['isolation'].get(k) != isolation[k] for k in ('database', 'owner', 'allow_marker_verified')) for r in reports):
            raise RuntimeError('Inconsistent database isolation proof')
        isolation['case_ids'] = [r['id'] for r in rows]
    sys.path.insert(0, str(ROOT / 'tools'))
    import platform_gate
    return {'mode': tier, 'tier': tier, 'cases': rows, 'count': len(rows), 'failures': 0, 'skips': 0,
            'isolation': isolation, 'observations': observations,
            'input_hashes': platform_gate.hashes(ROOT, platform_gate.USAGE_RESERVATIONS_INPUTS),
            'limitations': ['Scripted external owner ports; no actual ControLLM/Tariffler SDK, money ledger or live provider qualification.',
                            'Opt-in accounting composition; the unconfigured host path retains its earlier behavior.']}


if __name__ == '__main__':
    try:
        print(json.dumps(run('--postgres' in sys.argv[1:])))
    except Exception as error:
        print(json.dumps({'error': str(error), 'cases': []}))
        sys.exit(1)
