#!/usr/bin/env python3
"""Inject a synthetic infrastructure failure into the mutation result classifier.

Only a temporary copy of one mutation target is edited. No repository source changes.
"""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from tools import mutation_gate

patch = mutation_gate.patches()[0].copy()
original = (ROOT / patch['path']).read_bytes()
patch['anchor_sha256'] = mutation_gate.sha256_bytes(original)
with tempfile.TemporaryDirectory(prefix='agentools-audit-mutant-') as tmp:
    workspace = Path(tmp)
    target = workspace / patch['path']
    target.parent.mkdir(parents=True)
    target.write_bytes(original)
    mutation_gate.run_cmd = lambda cmd, cwd: subprocess.CompletedProcess(
        cmd, 2, stdout='', stderr='Test runner dependency could not be loaded (synthetic audit failure).'
    )
    result = mutation_gate.run_patch(patch, workspace=workspace)
    print(json.dumps({'injected_failure': 'test infrastructure', 'classification': result}))
