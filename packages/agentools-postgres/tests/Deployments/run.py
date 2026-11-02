#!/usr/bin/env python3
"""Routing catalog tests using the repository's verified disposable PostgreSQL lifecycle."""
import importlib.util
from pathlib import Path
import sys
base = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('isolated_pg_runner', base / 'run.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
if __name__ == '__main__':
    sys.exit(runner.main(Path(__file__).with_name('phpunit.xml'), case_prefix='routing-pg'))
