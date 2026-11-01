#!/usr/bin/env python3
"""Owning current-context/disclosure partition, independent of plan/review state."""
import json
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
STATIC=['php','packages/agentools-core/vendor/bin/phpstan','analyse','-c','packages/agentools-core/phpstan.neon','--no-progress',
    'packages/agentools-core/src/Security','packages/agentools-core/tests/Security','packages/agentools-core/src/Context/GuardSnapshot.php',
    'packages/agentools-core/src/Runtime/RequestRenderer.php','packages/agentools-core/src/Runtime/GuardedTransport.php']
def command(cmd):
    p=subprocess.run(cmd,cwd=ROOT,text=True,capture_output=True,timeout=90);sys.stderr.write(p.stderr)
    if p.returncode:raise RuntimeError('Required command failed: '+str(cmd)+' '+p.stdout)
    return p.stdout
def run():
    portable=[];rows=[];observations={}
    for name in ('guards.php','disclosure.php','worker.php','examples.php'):
        report=json.loads(command(['php',str(HERE/name)]))
        if not report.get('cases') or report.get('failures')!=0 or report.get('skips')!=0:raise RuntimeError('Required context guard proof failed: '+name)
        portable.append(report);rows.extend(report['cases']);observations.update(report.get('observations',{}))
    for id_,cmd in [('security.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-core']),('security.static-analysis',STATIC)]:
        sys.stderr.write(command(cmd));rows.append({'id':id_,'status':'passed'})
    from no_dev import verify
    packaging=verify();rows.append({'id':'security.production-no-dev','status':'passed'})
    sys.path.insert(0,str(ROOT/'tools'));import platform_gate
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),'failures':0,'skips':0,
        'portable':portable,'observations':observations,'packaging':packaging,'input_hashes':platform_gate.hashes(ROOT,platform_gate.SECURITY_INPUTS),
        'limitations':['Scripted provider/delivery/host policies and explicit qualified time; no live provider/voice/domain/production qualification.',
            'Host composition must install actual transport/disclosure guards, reauthorize owner IO and enforce durable event/delivery reconciliation.',
            'Grok aggregate review and upstream acceptance evidence remain conductor owned; this behavioral runner never fabricates review completion.',
            'D8: no classical mutation testing; historical scheduler/ManualClock/audit/reset risks are not resolved by this partition.']}
if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
