#!/usr/bin/env python3
"""Exact durable invalidation partition; --filter remains a focused class/method proof."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate
spec=importlib.util.spec_from_file_location('invalidation_pg',ROOT/'packages/agentools-postgres/tests/run.py')
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

if __name__=='__main__':
    matrix=json.loads((HERE/'cases.json').read_text());inputs=gate.hashes(ROOT,gate.KNOWLEDGE_INVALIDATION_INPUTS)
    output=io.StringIO()
    with tempfile.TemporaryDirectory(prefix='invalidation_observations_') as folder:
        observations=Path(folder)/'observations.jsonl'
        previous=os.environ.get('AGENTOOLS_INVALIDATION_OBSERVATIONS')
        os.environ['AGENTOOLS_INVALIDATION_OBSERVATIONS']=str(observations)
        try:
            with contextlib.redirect_stdout(output): code=module.main(HERE/'durable.xml','invalidation',matrix['postgres'])
        finally:
            if previous is None: os.environ.pop('AGENTOOLS_INVALIDATION_OBSERVATIONS',None)
            else: os.environ['AGENTOOLS_INVALIDATION_OBSERVATIONS']=previous
        report=json.loads(output.getvalue());details={}
        if observations.is_file():
            for line in observations.read_text().splitlines():
                observation=json.loads(line)
                if set(details)&set(observation): raise RuntimeError('Duplicate observation')
                details.update(observation)
        report.update(observations=details,input_hashes=inputs,evidence_kind='host_contract_harness',limitations=matrix['limitations'])
    if inputs != gate.hashes(ROOT,gate.KNOWLEDGE_INVALIDATION_INPUTS): report['error']='Inputs changed during execution';code=1
    print(json.dumps(report));sys.exit(code)
