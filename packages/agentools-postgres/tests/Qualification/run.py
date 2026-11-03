#!/usr/bin/env python3
"""Focused qualification persistence tests in the existing proven isolated PG lifecycle."""
import importlib.util
from pathlib import Path
import sys
source = importlib.util.spec_from_file_location('qualification_isolated_pg', Path(__file__).resolve().parents[1] / 'run.py')
runner = importlib.util.module_from_spec(source)
source.loader.exec_module(runner)
if __name__ == '__main__':
    sys.exit(runner.main(Path(__file__).with_name('phpunit.xml'), case_prefix='qualification-pg'))
