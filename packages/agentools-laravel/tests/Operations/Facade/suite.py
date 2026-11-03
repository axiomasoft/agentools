#!/usr/bin/env python3
"""Owning operations acceptance composition. Conductor runs this; focused files remain independently runnable."""
import json
from pathlib import Path
import subprocess
import sys
import time
ROOT=Path(__file__).resolve().parents[5]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate

def main():
    started=time.monotonic(); cases=[]; parts=[]
    for key in (('operations-contract','test'),('operations-calendar','test'),('operations','pg-test')):
        result=gate.run_suite(ROOT,gate.SUITES[key],isolated=key[1]=='pg-test')
        parts.append(result)
        if not result.get('ok'): raise gate.GateError('Required operation partition failed: '+str(key))
        cases.extend(result['report']['cases'])
    analysis=subprocess.run(gate.OPERATIONS_ANALYSIS,cwd=ROOT,text=True,capture_output=True,timeout=90)
    sys.stderr.write(analysis.stdout+analysis.stderr)
    if analysis.returncode: raise gate.GateError('Operations static analysis failed')
    composer=['composer','validate','--strict','--working-dir=packages/agentools-laravel']
    validate=subprocess.run(composer,cwd=ROOT,text=True,capture_output=True,timeout=30)
    sys.stderr.write(validate.stdout+validate.stderr)
    if validate.returncode: raise gate.GateError('Operations Composer validation failed')
    cases.extend([{'id':'operations.static-analysis','status':'passed'},{'id':'operations.composer-valid','status':'passed'}])
    return {'mode':'contract-scripted','tier':'contract-scripted','evidence_kind':'contract-scripted',
        'readiness_claim':'technical-contract-only','live_provider':'not_run','cases':cases,'count':len(cases),'failures':0,'skips':0,
        'parts':parts,'input_hashes':gate.hashes(ROOT,gate.OPERATIONS_INPUTS),
        'static_analysis':{'command':gate.OPERATIONS_ANALYSIS,'exit':analysis.returncode},
        'composer':{'command':composer,'exit':validate.returncode},
        'baseline':{'measurement_kind':'local-operations-harness','elapsed_ms':(time.monotonic()-started)*1000,
            'executed_cases':len(cases),'live_provider_calls':0,'spend_minor':0,'production_slo':None},
        'limitations':['Scripted external ports; no live provider, remote owner integration or production readiness.',
            'Harness elapsed time is a local test measurement; production latency/SLO remains unmeasured.']}
if __name__=='__main__':
    try: print(json.dumps(main()))
    except Exception as error:
        print(json.dumps({'error':type(error).__name__+': operations acceptance failed'})); sys.exit(1)
