#!/usr/bin/env python3
"""Owning compiler partition; no missing/skipped behavioral or isolated storage evidence."""
import json
from pathlib import Path
import subprocess
import sys

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
STATIC=['php','packages/agentools-core/vendor/bin/phpstan','analyse','-c','packages/agentools-core/phpstan.neon','--no-progress',
    'packages/agentools-core/src/Context','packages/agentools-core/tests/Context/Fixture.php',
    'packages/agentools-core/tests/Context/contract.php','packages/agentools-core/tests/Context/lifecycle.php',
    'packages/agentools-core/tests/Context/examples.php','packages/agentools-core/tests/Context/generate.php',
    'packages/agentools-postgres/src/Context','packages/agentools-postgres/tests/Context']

def command(cmd):
    p=subprocess.run(cmd,cwd=ROOT,text=True,capture_output=True,timeout=90)
    sys.stderr.write(p.stderr)
    if p.returncode:raise RuntimeError('Required command failed: '+str(cmd)+' '+p.stdout)
    return p.stdout

def run():
    portable=[];rows=[]
    for name in ('contract.php','lifecycle.php','examples.php','compiler.php'):
        report=json.loads(command(['php',str(HERE/name)]))
        if not report.get('cases') or report.get('failures')!=0 or report.get('skips')!=0:raise RuntimeError('Context behavioral proof missing/failed')
        portable.append(report);rows.extend(report['cases'])
    postgres=json.loads(command(['php','packages/agentools-postgres/tests/Context/snapshots.php']))
    if not postgres.get('cases') or postgres.get('failures')!=0 or postgres.get('skips')!=0:raise RuntimeError('Context isolated storage proof missing/failed')
    rows.extend(postgres['cases'])
    for id_,cmd in [('context.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-core']),('context.static-analysis',STATIC)]:
        sys.stderr.write(command(cmd));rows.append({'id':id_,'status':'passed'})
    from no_dev import verify
    packaging=verify();rows.append({'id':'context.production-no-dev','status':'passed'})
    sys.path.insert(0,str(ROOT/'tools'));import platform_gate
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),'failures':0,'skips':0,
        'portable':portable,'postgres':postgres,'packaging':packaging,'input_hashes':platform_gate.hashes(ROOT,platform_gate.CONTEXT_INPUTS),
        'limitations':['Scripted renderer/byte bound; no live provider/tokenizer qualification or production readiness.',
            'Text/decoded voice equivalence covers identical pinned plan, not live voice transport.',
            'Host owns current access, deployment/capability policy, encryption/diagnostic authorization/TTL and disclosure checks.',
            'D8: no classical mutation testing. Existing upstream review and host risks remain unresolved.']}

if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
