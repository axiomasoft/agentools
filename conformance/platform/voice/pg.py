#!/usr/bin/env python3
"""Focused combined voice journey class; isolated PostgreSQL is mandatory."""
import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[2]
spec=importlib.util.spec_from_file_location('voice_journey_pg',ROOT/'packages/agentools-postgres/tests/run.py')
runner=importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
if __name__=='__main__':
    names=json.loads((HERE/'case-map.json').read_text())
    with tempfile.TemporaryDirectory(prefix='agentools_voice_observations_') as directory:
        path=Path(directory)/'observations.jsonl'
        old=os.environ.get('AGENTOOLS_VOICE_JOURNEY_OBSERVATIONS')
        os.environ['AGENTOOLS_VOICE_JOURNEY_OBSERVATIONS']=str(path)
        captured=io.StringIO()
        try:
            with contextlib.redirect_stdout(captured):
                result=runner.main(HERE/'phpunit.xml',case_names=names)
        finally:
            if old is None:os.environ.pop('AGENTOOLS_VOICE_JOURNEY_OBSERVATIONS',None)
            else:os.environ['AGENTOOLS_VOICE_JOURNEY_OBSERVATIONS']=old
        report=json.loads(captured.getvalue())
        observations={}
        for line in path.read_text().splitlines() if path.is_file() else []:
            row=json.loads(line);case=names[row['method']]
            if case in observations:raise RuntimeError('Duplicate observed case')
            observations[case]=row['facts']
        report['observations']=observations
        if set(observations)!={c['id'] for c in report['cases']}:result=1;report['error']='Missing actual case observations'
        print(json.dumps(report));sys.exit(result)
