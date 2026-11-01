#!/usr/bin/env python3
"""P6.1 mandatory composition gate. No required step is optional or substituted by metadata."""
import json
from pathlib import Path
import subprocess
import sys
from no_dev import verify
from reuse import check

ROOT = Path(__file__).resolve().parents[4]
HERE = Path(__file__).resolve().parent

def run():
    process = subprocess.run([sys.executable, '-B', str(HERE / 'run.py')],cwd=ROOT,capture_output=True,text=True,timeout=100)
    sys.stderr.write(process.stderr)
    if process.returncode:
        raise RuntimeError('Required isolated PostgreSQL cases failed: ' + process.stdout)
    pg = json.loads(process.stdout)
    # The outer gate additionally checks the exact PG case set and isolation proof.
    rows = list(pg['cases'])
    for case, command in (
        ('managed.composer-valid', ['composer','validate','--strict','--working-dir=packages/agentools-laravel']),
        ('managed.static-analysis', ['composer','analyse','--working-dir=packages/agentools-laravel']),
    ):
        process = subprocess.run(command,cwd=ROOT,capture_output=True,text=True,timeout=60)
        sys.stderr.write(process.stdout + process.stderr)
        if process.returncode: raise RuntimeError('Required check failed: ' + case)
        rows.append({'id':case,'status':'passed'})
    packaging = verify()
    rows.append({'id':'managed.production-no-dev','status':'passed'})
    check()
    rows.append({'id':'managed.reuse-map','status':'passed'})
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),
        'failures':0,'skips':0,'postgres':pg,'packaging':packaging,
        'limitations':['Scripted host/provider contracts on real Laravel and isolated PG; no live provider or external host qualification.',
            'Octane event/sandbox lifecycle exercised without a Swoole/RoadRunner server. Buffered delivery only.',
            'No classical mutation testing (D8); no production rollout or deployment rollback qualification.']}

if __name__ == '__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[], 'error':str(error)}))
        sys.exit(1)
