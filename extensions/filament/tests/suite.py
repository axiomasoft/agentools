#!/usr/bin/env python3
"""P6.4 owning suite: real adapter tests, isolated PG and fresh no-dev installations."""
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
    matrix = json.loads((HERE / 'cases.json').read_text())
    if (matrix['tier'] != 'contract-scripted' or matrix['postgres_tier'] != 'isolated-postgres'
            or tuple(matrix['postgres'].values()) != gate.FILAMENT_PG_CASES
            or tuple(matrix['shell'].values()) != gate.FILAMENT_SHELL_CASES):
        raise RuntimeError('Fixture disagrees with required tiers/case sets')
    postgres = json.loads(command([sys.executable, '-B', str(HERE / 'run.py')]))
    gate.validate_report(gate.SUITES[('filament', 'pg-test')], postgres)
    shell = json.loads(command([sys.executable, '-B', str(HERE / 'shell.py')]))
    gate.validate_report(gate.Suite('P6.4-shell', 'contract-scripted', 'contract-scripted', gate.FILAMENT_SHELL_CASES, (), ()), shell)
    cases = postgres['cases'] + shell['cases']
    for name, argv in (
        ('admin.composer-valid', ['composer', 'validate', '--strict', '--working-dir=extensions/filament']),
        ('admin.static-analysis', ['extensions/filament/vendor/bin/phpstan', 'analyse', '-c',
            'extensions/filament/phpstan.neon', '--no-progress', '--memory-limit=512M']),
    ):
        sys.stderr.write(command(argv))
        cases.append({'id': name, 'status': 'passed'})
    packaging = no_dev.verify()
    cases.append({'id': 'admin.production-no-dev', 'status': 'passed'})
    result = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases,
        'count': len(cases), 'failures': 0, 'skips': 0, 'postgres': postgres, 'shell': shell,
        'packaging': packaging, 'limitations': matrix['limitations']}
    gate.validate_report(gate.SUITES[('filament', 'test')], result)
    return result

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': [], 'error': str(error)}))
        sys.exit(1)
