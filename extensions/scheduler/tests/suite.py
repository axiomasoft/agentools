#!/usr/bin/env python3
"""P6.5 exact owning suite. Synthetic external owners are never promoted to live evidence."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate
import no_dev

def command(argv, timeout=150):
    process = subprocess.run(argv, cwd=ROOT, text=True, capture_output=True, timeout=timeout)
    sys.stderr.write(process.stderr)
    if process.returncode:
        raise RuntimeError('Required check failed: ' + repr(argv) + '\n' + process.stdout)
    return process.stdout

def run():
    matrix = json.loads((ROOT / 'fixtures/platform/v1/scheduler/cases.json').read_text())
    if (matrix['tier'] != 'contract-scripted' or matrix['postgres_tier'] != 'isolated-postgres'
            or tuple(matrix['postgres'].values()) != gate.SCHEDULER_PG_CASES
            or tuple(matrix['calendar'].values()) != gate.SCHEDULER_CALENDAR_CASES):
        raise RuntimeError('Fixture disagrees with required tiers/case sets')
    postgres = json.loads(command([sys.executable, '-B', str(HERE / 'run.py')]))
    gate.validate_report(gate.SUITES[('scheduler', 'pg-test')], postgres)
    calendar = json.loads(command([sys.executable, '-B', str(HERE / 'calendar.py')]))
    gate.validate_report(gate.Suite('P6.5-calendar', 'unit', 'unit', gate.SCHEDULER_CALENDAR_CASES, (), ()), calendar)
    cases = postgres['cases'] + calendar['cases']
    for name, argv in (
        ('scheduler.composer-valid', ['composer', 'validate', '--strict', '--working-dir=extensions/scheduler']),
        ('scheduler.static-analysis', ['extensions/scheduler/vendor/bin/phpstan', 'analyse', '-c',
            'extensions/scheduler/phpstan.neon', '--no-progress', '--memory-limit=512M']),
        ('scheduler.core-port-analysis', ['packages/agentools-core/vendor/bin/phpstan', 'analyse', '-c',
            'packages/agentools-core/phpstan.neon', '--no-progress', '--memory-limit=512M',
            'packages/agentools-core/src/Scheduling/SchedulePort.php']),
    ):
        sys.stderr.write(command(argv))
        cases.append({'id': name, 'status': 'passed'})
    packaging = no_dev.verify()
    cases.append({'id': 'scheduler.production-no-dev', 'status': 'passed'})
    result = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases,
        'count': len(cases), 'failures': 0, 'skips': 0, 'postgres': postgres, 'calendar': calendar,
        'packaging': packaging, 'limitations': matrix['limitations']}
    gate.validate_report(gate.SUITES[('scheduler', 'test')], result)
    return result

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': [], 'error': str(error)}))
        sys.exit(1)
