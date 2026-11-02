#!/usr/bin/env python3
"""P8.3 contract partition; durable proof has its own mandatory PostgreSQL partition."""
import json
from pathlib import Path
import subprocess
import sys
import no_dev
import unit

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate

def run():
    matrix=json.loads((HERE/'cases.json').read_text())
    if tuple(matrix['unit'].values()) != gate.KNOWLEDGE_INVALIDATION_UNIT_CASES:
        raise RuntimeError('Invalidation unit case set differs')
    inputs=gate.hashes(ROOT,gate.KNOWLEDGE_INVALIDATION_INPUTS)
    report=unit.run()
    gate.validate_report(gate.Suite('P8.3-unit','contract-scripted','contract-scripted',gate.KNOWLEDGE_INVALIDATION_UNIT_CASES,(),()),report)
    cases=report['cases']
    for name,argv in (
        ('invalidation.composer-valid',['composer','validate','--strict','--working-dir=extensions/knowledge']),
        ('invalidation.static-analysis',['extensions/knowledge/vendor/bin/phpstan','analyse','-c','extensions/knowledge/phpstan.neon','--no-progress']),
        ('invalidation.core-static-analysis',['packages/agentools-core/vendor/bin/phpstan','analyse','-c','packages/agentools-core/phpstan.neon','--no-progress','packages/agentools-core/src/Context']),
    ):
        result=subprocess.run(argv,cwd=ROOT,capture_output=True,text=True,timeout=90)
        sys.stderr.write(result.stdout+result.stderr)
        if result.returncode: raise RuntimeError(name+' failed')
        cases.append({'id':name,'status':'passed'})
    packaging=no_dev.verify();cases.append({'id':'invalidation.production-no-dev','status':'passed'})
    if inputs != gate.hashes(ROOT,gate.KNOWLEDGE_INVALIDATION_INPUTS): raise RuntimeError('Inputs changed during execution')
    report.update(cases=cases,count=len(cases),input_hashes=inputs,evidence_kind='host_contract_harness',limitations=matrix['limitations'],packaging=packaging)
    gate.validate_report(gate.SUITES[('knowledge-invalidation','test')],report)
    return report

if __name__=='__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
