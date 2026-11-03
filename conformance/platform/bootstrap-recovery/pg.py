#!/usr/bin/env python3
"""Run the real isolated PostgreSQL backup/restore rehearsal."""
import importlib.util
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('recovery_lifecycle', ROOT / 'packages/agentools-postgres/tests/run.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
CASES = {'testCleanInstallBackupRestoreAndProjectionRebuild': 'bootstrap.restore-projection-usage'}
if __name__ == '__main__':
    sys.exit(module.main(ROOT / 'packages/agentools-postgres/tests/Recovery/phpunit.xml', 'bootstrap-restore', CASES))
