#!/usr/bin/env python3
"""P8.1 portable ingest/validation, packaging and analysis; no provider or PG substitution."""
import json
from pathlib import Path
import subprocess
import sys
import no_dev
import unit

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate

def run():
    inputs = gate.hashes(ROOT, gate.KNOWLEDGE_INPUTS)
    matrix = json.loads((HERE / 'cases.json').read_text())
    if tuple(matrix['unit'].values()) != gate.KNOWLEDGE_UNIT_CASES or tuple(matrix['postgres'].values()) != gate.KNOWLEDGE_PG_CASES:
        raise RuntimeError('Knowledge declared case set differs')
    portable = unit.run()
    gate.validate_report(gate.Suite('P8.1-unit', 'contract-scripted', 'contract-scripted', gate.KNOWLEDGE_UNIT_CASES, (), ()), portable)
    cases = portable['cases']
    for name, argv in (
        ('knowledge.composer-valid', ['composer', 'validate', '--strict', '--working-dir=extensions/knowledge']),
        ('knowledge.static-analysis', ['extensions/knowledge/vendor/bin/phpstan', 'analyse', '-c', 'extensions/knowledge/phpstan.neon', '--no-progress']),
    ):
        result = subprocess.run(argv, cwd=ROOT, capture_output=True, text=True, timeout=90)
        sys.stderr.write(result.stdout + result.stderr)
        if result.returncode:
            raise RuntimeError(name + ' failed')
        cases.append({'id':name, 'status':'passed'})
    packaging = no_dev.verify()
    cases.append({'id':'knowledge.production-no-dev', 'status':'passed'})
    if inputs != gate.hashes(ROOT, gate.KNOWLEDGE_INPUTS):
        raise RuntimeError('Knowledge inputs changed during execution')
    report = {'mode':'contract-scripted', 'tier':'contract-scripted', 'cases':cases, 'count':len(cases), 'failures':0, 'skips':0,
        'packaging':packaging, 'input_hashes':inputs,
        'evidence_kind':'host_contract_harness', 'limitations':matrix['limitations']}
    gate.validate_report(gate.SUITES[('knowledge-ingest','test')], report)
    return report

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[], 'error':str(error)}))
        sys.exit(1)
