#!/usr/bin/env python3
"""Owning history/memory pure contract partition. No implicit external owner qualification."""
import json
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[4]
STATIC=['php','packages/agentools-core/vendor/bin/phpstan','analyse','-c','packages/agentools-core/phpstan.neon','--no-progress',
    'packages/agentools-core/src/Context/History','packages/agentools-core/src/Context/Memory','packages/agentools-postgres/src/Summaries',
    'packages/agentools-core/tests/Context/History','packages/agentools-core/tests/Context/Memory','packages/agentools-postgres/tests/Summaries',
    'fixtures/platform/v1/history/external-owner-adapter.php']
def command(cmd):
    p=subprocess.run(cmd,cwd=ROOT,text=True,capture_output=True,timeout=90);sys.stderr.write(p.stderr)
    if p.returncode:raise RuntimeError('Required command failed: '+str(cmd)+' '+p.stdout)
    return p.stdout
def run():
    reports=[];rows=[]
    for path in (HERE/'contract.php',HERE.parent/'Memory/contract.php',HERE/'examples.php'):
        report=json.loads(command(['php',str(path)]))
        if not report.get('cases') or report.get('failures')!=0 or report.get('skips')!=0:raise RuntimeError('History/memory behavioral proof failed')
        reports.append(report);rows.extend(report['cases'])
    for id_,cmd in [('history.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-core']),('history.static-analysis',STATIC)]:
        sys.stderr.write(command(cmd));rows.append({'id':id_,'status':'passed'})
    from no_dev import verify
    packaging=verify();rows.append({'id':'history.production-no-dev','status':'passed'})
    sys.path.insert(0,str(ROOT/'tools'));import platform_gate
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),'failures':0,'skips':0,
        'portable':reports,'packaging':packaging,'input_hashes':platform_gate.hashes(ROOT,platform_gate.HISTORY_INPUTS),
        'limitations':['External transcript/memory policies and adapters are scripted host-contract evidence, not Chatom/AiCore deployment.',
            'Host owns source/consent event delivery, retention cleanup, view byte storage and current disclosure authorization.',
            'PostgreSQL durable/concurrency/crash evidence is the separate pg-test partition. No live provider or mutation qualification.']}
if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
