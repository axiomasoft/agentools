#!/usr/bin/env python3
"""P6.3: portable doubles plus real kernel/PG profile checks, never a tier upgrade."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


def command(argv, timeout=100):
    process = subprocess.run(argv, cwd=ROOT, text=True, capture_output=True, timeout=timeout)
    sys.stderr.write(process.stderr)
    if process.returncode:
        raise RuntimeError('Required check failed: ' + repr(argv) + '\n' + process.stdout)
    return process.stdout


def run():
    portable = json.loads(command(['php', 'packages/agentools-testing/tests/Composition/ports.php']))
    gate.validate_report(gate.Suite('P6.3-ports', 'contract-scripted', 'contract-scripted',
        gate.COMPOSITION_PORT_CASES, (), ()), portable)
    postgres = json.loads(command([sys.executable, '-B', str(HERE / 'run.py')]))
    gate.validate_report(gate.SUITES[('composition-testing', 'pg-test')], postgres)
    matrix = json.loads((ROOT / 'fixtures/platform/v1/composition/testing-profile.json').read_text())
    if (matrix['tier'] != 'contract-scripted' or matrix['postgres_partition_tier'] != 'isolated-postgres'
            or tuple(matrix['portable_cases']) != gate.COMPOSITION_PORT_CASES
            or set(matrix['class_cases'].values()) != set(gate.COMPOSITION_TESTING_PG_CASES)):
        raise RuntimeError('Fixture disagrees with required tiers/case sets')
    cases = portable['cases'] + postgres['cases']
    for case, argv in (
        ('fake-profile.composer-valid', ['composer', 'validate', '--strict', '--working-dir=packages/agentools-testing']),
        ('fake-profile.static-analysis', ['packages/agentools-testing/vendor/bin/phpstan', 'analyse',
            '-c', 'packages/agentools-testing/phpstan.neon', '--no-progress', '--memory-limit=512M',
            'packages/agentools-testing/src/Composition', 'packages/agentools-testing/tests/Composition']),
        ('fake-profile.consumer-analysis', ['packages/agentools-laravel/vendor/bin/phpstan', 'analyse',
            '-c', 'packages/agentools-laravel/phpstan.neon', '--no-progress', '--memory-limit=512M',
            'packages/agentools-laravel/tests/Testing']),
    ):
        sys.stderr.write(command(argv))
        cases.append({'id': case, 'status': 'passed'})
    spec = importlib.util.spec_from_file_location('testing_no_dev', HERE.parent / 'Composition/no_dev.py')
    no_dev = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(no_dev)
    packaging = no_dev.verify()
    cases.append({'id': 'fake-profile.production-no-dev', 'status': 'passed'})
    result = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases,
        'count': len(cases), 'failures': 0, 'skips': 0, 'portable': portable,
        'postgres': postgres, 'packaging': packaging, 'limitations': matrix['limitations']}
    gate.validate_report(gate.SUITES[('composition-testing', 'test')], result)
    return result


if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': [], 'error': str(error)}))
        sys.exit(1)
