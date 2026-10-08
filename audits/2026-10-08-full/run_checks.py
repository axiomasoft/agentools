#!/usr/bin/env python3
"""Record audit checks without changing source or installing dependencies.

Usage: python3 audits/2026-10-08-full/run_checks.py ID CWD TIMEOUT COMMAND...
Use a proven isolated database via AGENTOOLS_TEST_PG_* for database tests.
"""
import datetime
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
OUT = Path(__file__).resolve().parent / 'evidence'


def main():
    name, cwd, timeout, *command = sys.argv[1:]
    OUT.mkdir(exist_ok=True)
    started = time.monotonic()
    record = {'id': name, 'cwd': cwd, 'command': command,
              'started_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()}
    with (OUT / f'{name}.log').open('w') as log:
        process = subprocess.Popen(command, cwd=ROOT / cwd, stdout=log,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            record['exit_code'] = process.wait(timeout=int(timeout))
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
            record['exit_code'] = 124
            record['timed_out'] = True
    record['duration_seconds'] = round(time.monotonic() - started, 3)
    (OUT / f'{name}.json').write_text(json.dumps(record, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(record, ensure_ascii=False), flush=True)
    return record['exit_code']


if __name__ == '__main__':
    raise SystemExit(main())
