#!/usr/bin/env python3
"""Knowledge durable partition; reuse the proven isolated PostgreSQL bootstrap."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate

spec = importlib.util.spec_from_file_location('knowledge_pg', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

if __name__ == '__main__':
    matrix = json.loads((HERE / 'cases.json').read_text())
    inputs = gate.hashes(ROOT, gate.KNOWLEDGE_INPUTS)
    output = io.StringIO()
    try:
        with contextlib.redirect_stdout(output):
            code = module.main(HERE / 'durable.xml', 'knowledge', matrix['postgres'])
    except SystemExit:
        sys.stdout.write(output.getvalue())
        raise
    report = json.loads(output.getvalue())
    report.update(input_hashes=inputs, evidence_kind='host_contract_harness', limitations=matrix['limitations'])
    if inputs != gate.hashes(ROOT, gate.KNOWLEDGE_INPUTS):
        report['error'] = 'Knowledge inputs changed during execution'
        code = 1
    print(json.dumps(report))
    sys.exit(code)
