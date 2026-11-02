#!/usr/bin/env python3
"""Golden local-host e2e: real PostgreSQL/runtime/index and explicitly scripted external ports."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[2]
TESTS=ROOT/'extensions/knowledge/tests/Golden'
sys.path.insert(0,str(ROOT/'tools'));import platform_gate as gate
unit_spec=importlib.util.spec_from_file_location('golden_unit',TESTS/'unit.py')
unit=importlib.util.module_from_spec(unit_spec);unit_spec.loader.exec_module(unit)
pg_spec=importlib.util.spec_from_file_location('golden_pg',ROOT/'packages/agentools-postgres/tests/run.py')
pg=importlib.util.module_from_spec(pg_spec);pg_spec.loader.exec_module(pg)

def run():
    matrix=json.loads((TESTS/'cases.json').read_text());inputs=gate.hashes(ROOT,gate.KNOWLEDGE_JOURNEY_INPUTS)
    focused='--filter' in sys.argv
    hooks=None
    if not focused:
        hooks=unit.run()
        argv=['extensions/knowledge/vendor/bin/phpstan','analyse','-c','conformance/platform/knowledge/phpstan.neon','--no-progress']
        result=subprocess.run(argv,cwd=ROOT,capture_output=True,text=True,timeout=90)
        sys.stderr.write(result.stdout+result.stderr)
        if result.returncode: raise RuntimeError('Golden composition analysis failed')
        hooks['cases'].append({'id':'knowledge-golden.static-analysis','status':'passed'});hooks['count']=len(hooks['cases'])
    captured=io.StringIO()
    with tempfile.TemporaryDirectory(prefix='golden_observations_') as folder:
        path=Path(folder)/'observations.jsonl';previous=os.environ.get('AGENTOOLS_KNOWLEDGE_OBSERVATIONS')
        os.environ['AGENTOOLS_KNOWLEDGE_OBSERVATIONS']=str(path)
        try:
            with contextlib.redirect_stdout(captured): code=pg.main(TESTS/'journey.xml','knowledge-golden',matrix['postgres'])
        finally:
            if previous is None: os.environ.pop('AGENTOOLS_KNOWLEDGE_OBSERVATIONS',None)
            else: os.environ['AGENTOOLS_KNOWLEDGE_OBSERVATIONS']=previous
        report=json.loads(captured.getvalue());observations={}
        if path.is_file():
            for line in path.read_text().splitlines():
                data=json.loads(line)
                if set(observations)&set(data): raise RuntimeError('Duplicate golden observation')
                observations.update(data)
    report.update(mode='local-host-e2e',tier='local-host-e2e',evidence_kind='host_contract_harness',input_hashes=inputs,
        limitations=matrix['limitations'],observations=observations,transport='scripted-knowledge/v1',extractor='scripted-coordinate-extractor/v1',index='isolated-postgresql-tsvector-gin')
    if hooks is not None: report['hooks']=hooks
    if inputs != gate.hashes(ROOT,gate.KNOWLEDGE_JOURNEY_INPUTS): code=1;report['error']='Inputs changed during execution'
    if not focused and code==0: gate.validate_report(gate.SUITES[('knowledge-journey','e2e')],report)
    return code,report
if __name__=='__main__':
    try:
        code,report=run();print(json.dumps(report));sys.exit(code)
    except Exception as error:
        print(json.dumps({'mode':'local-host-e2e','tier':'local-host-e2e','cases':[],'error':str(error)}));sys.exit(1)
