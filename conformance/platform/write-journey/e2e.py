#!/usr/bin/env python3
"""Exact write matrix plus P5.2/P5.3/P5.4 regressions in one verified PG lifecycle."""
import importlib.util
import contextlib
import io
import json
from pathlib import Path
import subprocess
import sys
from run import CASES, main

ROOT = Path(__file__).resolve().parents[3]
ALL_CASES = dict(CASES)
for index, relative in enumerate((
    'packages/agentools-core/tests/Actions/Confirmation/pg.py',
    'packages/agentools-postgres/tests/Effects/run.py',
    'packages/agentools-laravel/tests/Operations/run.py',
)):
    spec = importlib.util.spec_from_file_location('write_partition_' + str(index), ROOT / relative)
    partition = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(partition)
    if set(ALL_CASES).intersection(partition.CASES):
        raise RuntimeError('Duplicate required method in write matrix')
    ALL_CASES.update(partition.CASES)
if len(ALL_CASES) != 52 or len(set(ALL_CASES.values())) != 52:
    raise RuntimeError('Exact 52-case write recovery matrix changed')

if __name__ == '__main__':
    output = io.StringIO()
    with contextlib.redirect_stdout(output):
        exit_code = main(Path(__file__).with_name('full.xml'), ALL_CASES)
    report = json.loads(output.getvalue())
    portable = subprocess.run([sys.executable, '-B', str(Path(__file__).with_name('parity.py'))],
                              cwd=ROOT, capture_output=True, text=True, timeout=100)
    sys.stderr.write(portable.stderr)
    report['portable'] = json.loads(portable.stdout)
    print(json.dumps(report))
    sys.exit(exit_code or portable.returncode)
