#!/usr/bin/env python3
"""Exercise CI bootstrap in a disposable tree with all package dependencies removed."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from tools.isolated_workspace import copy_workspace
from tools.ci_matrix import LOCKFILES, sha256_file

before = {name: sha256_file(path) for name, path in LOCKFILES.items()}
with tempfile.TemporaryDirectory(prefix='agentools-clean-bootstrap-') as directory:
    tree = Path(directory)
    copy_workspace(ROOT, tree)
    for relative in ('php/vendor', 'conformance/vendor', 'dart/agentools/.dart_tool', 'dart/agentools_mcp_dart/.dart_tool', 'conformance/mcp/node_modules'):
        path = tree / relative
        if path.exists():
            shutil.rmtree(path)
    result = subprocess.run(['bash', 'scripts/ci-bootstrap.sh'], cwd=tree, check=False)
    if result.returncode:
        raise SystemExit(result.returncode)
    assert (tree / 'php/vendor/autoload.php').is_file()
    assert (tree / 'conformance/vendor/autoload.php').is_file()
    assert (tree / 'dart/agentools/.dart_tool/package_config.json').is_file()
    assert (tree / 'dart/agentools_mcp_dart/.dart_tool/package_config.json').is_file()
    assert (tree / 'conformance/mcp/node_modules/.bin/conformance').is_file()
after = {name: sha256_file(path) for name, path in LOCKFILES.items()}
if before != after:
    raise SystemExit('Source locks changed during disposable bootstrap')
print(json.dumps({'clean_bootstrap': 'passed', 'source_locks_unchanged': True}))
