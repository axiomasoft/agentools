#!/usr/bin/env python3
"""P7.1 contract partition. Durable cases have their own required PostgreSQL partition."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]

def run():
    rows = []
    reports = []
    for file in ('publication.php', 'examples.php'):
        p = subprocess.run(['php', str(HERE / file)], cwd=ROOT, text=True, capture_output=True, timeout=20)
        sys.stderr.write(p.stderr)
        report = json.loads(p.stdout)
        if p.returncode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required Profiles unit partition failed')
        reports.append(report); rows.extend(report['cases'])
    for id_, command, cwd in (
        ('profiles.core-composer-valid', ['composer','validate','--strict'], ROOT/'packages/agentools-core'),
        ('profiles.pg-composer-valid', ['composer','validate','--strict'], ROOT/'packages/agentools-postgres'),
        ('profiles.core-static-analysis', ['php','vendor/bin/phpstan','analyse','-c','phpstan.neon','--no-progress','src/Profiles','tests/Profiles'], ROOT/'packages/agentools-core'),
        ('profiles.pg-static-analysis', ['php','vendor/bin/phpstan','analyse','-c','phpstan.neon','--no-progress','src/Profiles','tests/Profiles'], ROOT/'packages/agentools-postgres'),
    ):
        p = subprocess.run(command, cwd=cwd, text=True, capture_output=True, timeout=60)
        sys.stderr.write(p.stdout + p.stderr)
        if p.returncode: raise RuntimeError('Required check failed: ' + id_)
        rows.append({'id':id_, 'status':'passed'})
    from no_dev import verify
    packaging = verify(); rows.append({'id':'profiles.production-no-dev', 'status':'passed'})
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),'failures':0,'skips':0,
        'portable':reports,'packaging':packaging,'limitations':[
            'Scripted compiler/host for publication boundaries; typed merge and skill selection have separate owners P7.2/P7.3.',
            'PostgreSQL recovery is a separate required partition; no live provider or production qualification.',
            'D8: no classical mutation testing or mutation score.']}

if __name__ == '__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)})); sys.exit(1)
