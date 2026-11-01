#!/usr/bin/env python3
"""P6.2 gate runner: required real PG cases plus actual packaging/static checks."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]

def run():
    process=subprocess.run([sys.executable,'-B',str(HERE/'run.py')],cwd=ROOT,text=True,capture_output=True,timeout=100)
    sys.stderr.write(process.stderr)
    if process.returncode:raise RuntimeError('Required definitions cases failed: '+process.stdout)
    pg=json.loads(process.stdout);rows=list(pg['cases'])
    for case,command in (
        ('definitions.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-laravel']),
        ('definitions.static-analysis',['composer','analyse','--working-dir=packages/agentools-laravel']),
    ):
        process=subprocess.run(command,cwd=ROOT,text=True,capture_output=True,timeout=60)
        sys.stderr.write(process.stdout+process.stderr)
        if process.returncode:raise RuntimeError('Required check failed: '+case)
        rows.append({'id':case,'status':'passed'})
    spec=importlib.util.spec_from_file_location('definitions_no_dev',HERE.parent/'Composition/no_dev.py')
    no_dev=importlib.util.module_from_spec(spec);spec.loader.exec_module(no_dev)
    packaging=no_dev.verify();rows.append({'id':'definitions.production-no-dev','status':'passed'})
    from attribution import check
    check();rows.append({'id':'definitions.attribution','status':'passed'})
    return {'mode':'contract-scripted','tier':'contract-scripted','postgres':pg,'packaging':packaging,
        'cases':rows,'count':len(rows),'failures':0,'skips':0,'limitations':[
        'Code registry/runtime and isolated PG assignment/pin/fault checks with scripted host ports.',
        'Full Profiles publication/compiler and skill routing/fragment loading remain P7 owners; no donor API parity claim.',
        'No live provider, external owner, production rollout or deployment rollback qualification; D8 excludes classical mutation testing.']}

if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
