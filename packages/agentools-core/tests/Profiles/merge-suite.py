#!/usr/bin/env python3
"""P7.2 gate partition; actual pure composition plus isolated publication integration."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]

def run():
    cases = []
    portable = []
    for name in ('merge.php','merge-live.php'):
        process = subprocess.run(['php', str(HERE/name)], cwd=ROOT, text=True, capture_output=True, timeout=20)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        if process.returncode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required typed merge partition failed')
        portable.append(report); cases.extend(report['cases'])
    process = subprocess.run([sys.executable,'-B',str(HERE/'merge-pg.py')], cwd=ROOT, text=True, capture_output=True, timeout=110)
    sys.stderr.write(process.stderr)
    pg = json.loads(process.stdout)
    if process.returncode or not pg.get('cases') or pg.get('failures') != 0 or pg.get('skips') != 0:
        raise RuntimeError('Required typed merge publication integration failed')
    cases.extend(pg['cases'])
    for id_, command in (
        ('merge.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-core']),
        ('merge.static-analysis',['php','packages/agentools-core/vendor/bin/phpstan','analyse',
            '-c','packages/agentools-core/phpstan.neon','--no-progress','packages/agentools-core/src/Profiles/Composition','packages/agentools-core/tests/Profiles']),
    ):
        process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=60)
        sys.stderr.write(process.stdout+process.stderr)
        if process.returncode: raise RuntimeError('Required check failed: '+id_)
        cases.append({'id':id_, 'status':'passed'})
    from no_dev import verify
    packaging = verify(); cases.append({'id':'merge.production-no-dev','status':'passed'})
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':cases,'count':len(cases),
        'failures':0,'skips':0,'portable':portable,'postgres':pg,'packaging':packaging,
        'limitations':['Pure typed composition and real isolated PostgreSQL with scripted host/material owners.',
            'No LLM arbitration, live provider, production rollout or mutation-test evidence.',
            'Hosts must apply current overrides and existing execution/material/disclosure guards over immutable pins.']}

if __name__ == '__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)})); sys.exit(1)
