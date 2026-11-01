#!/usr/bin/env python3
"""Real Laravel/kernel/PG profile checks, with scripted external owners only."""
import importlib.util
from pathlib import Path
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
spec=importlib.util.spec_from_file_location('testing_profile_pg',ROOT/'packages/agentools-postgres/tests/run.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
import json
CASES=json.loads((ROOT/'fixtures/platform/v1/composition/testing-profile.json').read_text())['class_cases']
if __name__=='__main__':sys.exit(module.main(HERE/'phpunit.xml','composition-testing',CASES))
