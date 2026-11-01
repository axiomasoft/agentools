#!/usr/bin/env python3
"""Scoped ProfilePublishTest runner using the verified isolated PostgreSQL bootstrap."""
import importlib.util
import json
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
spec = importlib.util.spec_from_file_location('profile_admin_pg', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = json.loads((HERE / 'profile-cases.json').read_text())['postgres']

if __name__ == '__main__':
    sys.exit(module.main(HERE / 'profile-publish.xml', 'profile-admin', CASES))
