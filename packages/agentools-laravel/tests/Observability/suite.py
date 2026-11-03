#!/usr/bin/env python3
"""Conductor-owned observability/security acceptance composition; never treats scripted owners as live."""
import json
from pathlib import Path
import subprocess
import sys
ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate

def main():
    parts=[];cases=[]
    for key in (('observability-contract','test'),('observability-durable','pg-test'),('observability-cron','pg-test'),('observability-voice','pg-test'),('observability-accounting','pg-test')):
        part=gate.run_suite(ROOT,gate.SUITES[key],isolated=key[1]=='pg-test')
        parts.append(part)
        if not part.get('ok'):
            sys.stderr.write(part.get('stdout','')+part.get('stderr',''))
            raise gate.GateError('Required observability partition failed: '+str(key))
        cases.extend(part['report']['cases'])
    analysis=subprocess.run(gate.OBSERVABILITY_ANALYSIS,cwd=ROOT,capture_output=True,text=True,timeout=90)
    sys.stderr.write(analysis.stdout+analysis.stderr)
    if analysis.returncode:raise gate.GateError('Observability scoped static analysis failed')
    composer=['composer','validate','--strict','--working-dir=packages/agentools-core']
    dependency=subprocess.run(composer,cwd=ROOT,capture_output=True,text=True,timeout=30)
    sys.stderr.write(dependency.stdout+dependency.stderr)
    if dependency.returncode:raise gate.GateError('Observability Composer validation failed')
    cases.extend([{'id':'observability.static-analysis','status':'passed'},{'id':'observability.composer-valid','status':'passed'}])
    return {'mode':'contract-scripted','tier':'contract-scripted','evidence_kind':'contract-scripted',
        'readiness_claim':'technical-contract-only','live_provider':'not_run','cases':cases,'count':len(cases),'failures':0,'skips':0,
        'parts':parts,'input_hashes':gate.hashes(ROOT,gate.OBSERVABILITY_INPUTS),
        'static_analysis':{'command':gate.OBSERVABILITY_ANALYSIS,'exit':analysis.returncode},
        'composer':{'command':composer,'exit':dependency.returncode},
        'limitations':['Synthetic sensitive sentinels and scripted external providers/owners; real isolated PostgreSQL.',
            'No remote monitoring exporter, live provider, external credential service or production readiness qualification.']}
if __name__=='__main__':
    try:print(json.dumps(main()))
    except Exception as error:
        print(json.dumps({'error':type(error).__name__+': observability acceptance failed'}));sys.exit(1)
