#!/usr/bin/env python3
"""Owning real isolated PostgreSQL scheduler tests; --filter supports scoped proof."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
spec = importlib.util.spec_from_file_location('scheduler_pg', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = json.loads((ROOT / 'fixtures/platform/v1/scheduler/cases.json').read_text())['postgres']

if __name__ == '__main__':
    with tempfile.TemporaryDirectory(prefix='agentools_sched_report_') as directory:
        observation = Path(directory) / 'observations.json'
        os.environ['AGENTOOLS_SCHEDULER_OBSERVATIONS'] = str(observation)
        stream = io.StringIO()
        with contextlib.redirect_stdout(stream):
            code = module.main(HERE / 'phpunit.xml', 'scheduler', CASES)
        report = json.loads(stream.getvalue())
        report['observations'] = json.loads(observation.read_text()) if observation.is_file() else {}
        print(json.dumps(report))
        sys.exit(code)
