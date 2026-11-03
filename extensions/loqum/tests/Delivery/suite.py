#!/usr/bin/env python3
"""Conductor-owned voice delivery acceptance partition; no installs or live providers."""
import json
from pathlib import Path
import subprocess
import sys

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate

def run():
    cases=[];pg=None
    for name,argv,expected,tier in (
        ('postgres',[sys.executable,'-B',str(HERE/'pg.py')],gate.VOICE_DELIVERY_PG_CASES,'isolated-postgres'),
        ('disclosure',['php',str(HERE/'disclosure.php')],('voice-delivery.core-disclosure',),'contract-scripted'),
        ('schema',[sys.executable,'-B',str(HERE/'schema.py')],('voice-delivery.schema',),'contract-scripted'),
        ('production-no-dev',['php',str(HERE/'no-dev/check.php')],('voice-delivery.production-no-dev',),'contract-scripted'),
    ):
        p=subprocess.run(argv,cwd=ROOT,capture_output=True,text=True,timeout=100);sys.stderr.write(p.stderr)
        gate.require(p.returncode==0,'Voice delivery file failed: '+name)
        report=json.loads(p.stdout)
        gate.validate_report(gate.Suite('P11.2-file',tier,tier,expected,(),(),name=='postgres'),report)
        cases.extend(report['cases'])
        if name=='postgres':pg=report
    for case,commands in (
        ('voice-delivery.composer-valid',[
            ['composer','validate','--strict','--working-dir=extensions/loqum'],
            ['composer','validate','--strict','--working-dir=extensions/loqum/tests/Delivery/no-dev']]),
        ('voice-delivery.static-analysis',[
            ['extensions/loqum/vendor/bin/phpstan','analyse','-c','extensions/loqum/phpstan.neon','--no-progress']]),
    ):
        for argv in commands:
            p=subprocess.run(argv,cwd=ROOT,capture_output=True,text=True,timeout=90);sys.stderr.write(p.stdout+p.stderr)
            gate.require(p.returncode==0,'Voice delivery check failed: '+case)
        cases.append({'id':case,'status':'passed'})
    report={'mode':'contract-scripted','tier':'contract-scripted','cases':cases,'count':len(cases),'failures':0,'skips':0,
            'evidence_kind':'voice_delivery_contract_harness','input_hashes':gate.hashes(ROOT,gate.VOICE_DELIVERY_INPUTS),
            'postgres':pg,'live_provider':'not_executed','production_readiness':'not_claimed',
            'limitations':['Real isolated PostgreSQL/checkpoint/confirmation/effect boundary, scripted media/host owners.',
                           'No acoustic, real client playout, recording/export/retention or live provider qualification.']}
    gate.validate_report(gate.SUITES[('voice-delivery','test')],report);return report

if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)}));sys.exit(1)
