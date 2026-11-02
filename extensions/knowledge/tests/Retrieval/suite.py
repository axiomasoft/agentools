#!/usr/bin/env python3
"""P8.2 contract-scripted acceptance partition; never substitutes schema or fake durable evidence."""
import json
from pathlib import Path
import subprocess
import sys
import no_dev
import unit

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
sys.path.insert(0,str(ROOT / 'tools'))
import platform_gate as gate

def run():
    matrix = json.loads((HERE / 'cases.json').read_text())
    if tuple(matrix['cases'].values()) != gate.KNOWLEDGE_RETRIEVAL_UNIT_CASES:
        raise RuntimeError('Declared retrieval case set differs')
    inputs = gate.hashes(ROOT,gate.KNOWLEDGE_RETRIEVAL_INPUTS)
    portable = unit.run()
    gate.validate_report(gate.Suite('P8.2-unit','contract-scripted','contract-scripted',gate.KNOWLEDGE_RETRIEVAL_UNIT_CASES,(),()),portable)
    cases = portable['cases']
    for name, argv in (
        ('retrieval.composer-valid',['composer','validate','--strict','--working-dir=extensions/knowledge']),
        ('retrieval.static-analysis',['extensions/knowledge/vendor/bin/phpstan','analyse','-c','extensions/knowledge/phpstan.neon','--no-progress']),
    ):
        result = subprocess.run(argv,cwd=ROOT,capture_output=True,text=True,timeout=90)
        sys.stderr.write(result.stdout + result.stderr)
        if result.returncode: raise RuntimeError(name + ' failed')
        cases.append({'id':name,'status':'passed'})
    packaging = no_dev.verify()
    cases.append({'id':'retrieval.production-no-dev','status':'passed'})
    if inputs != gate.hashes(ROOT,gate.KNOWLEDGE_RETRIEVAL_INPUTS): raise RuntimeError('Inputs changed during execution')
    report = {'mode':'contract-scripted','tier':'contract-scripted','cases':cases,'count':len(cases),'failures':0,'skips':0,
        'input_hashes':inputs,'evidence_kind':'host_contract_harness','limitations':matrix['limitations'],'packaging':packaging}
    gate.validate_report(gate.SUITES[('knowledge-retrieval','test')],report)
    return report

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}))
        sys.exit(1)
