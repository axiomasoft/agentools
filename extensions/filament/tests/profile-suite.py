#!/usr/bin/env python3
"""P7.7 canonical publisher integration, optional Filament shell and no-dev proof."""
import json
import sys
from pathlib import Path
import suite

HERE = Path(__file__).resolve().parent
gate = suite.gate

def run():
    matrix = json.loads((HERE / 'profile-cases.json').read_text())
    if (matrix['tier'] != 'contract-scripted' or matrix['postgres_tier'] != 'isolated-postgres'
            or tuple(matrix['postgres'].values()) != gate.PROFILE_ADMIN_PG_CASES
            or tuple(matrix['shell'].values()) != gate.PROFILE_ADMIN_SHELL_CASES):
        raise RuntimeError('Profile admin case set/tier mismatch')
    postgres = json.loads(suite.command([sys.executable, '-B', str(HERE / 'profile-run.py')]))
    gate.validate_report(gate.SUITES[('profile-publish-admin', 'pg-test')], postgres)
    shell = json.loads(suite.command([sys.executable, '-B', str(HERE / 'profile-shell.py')]))
    gate.validate_report(gate.Suite('P7.7-shell', 'contract-scripted', 'contract-scripted', gate.PROFILE_ADMIN_SHELL_CASES, (), ()), shell)
    cases = postgres['cases'] + shell['cases']
    for name, argv in (
        ('profile-admin.composer-valid', ['composer', 'validate', '--strict', '--working-dir=extensions/filament']),
        ('profile-admin.static-analysis', ['extensions/filament/vendor/bin/phpstan', 'analyse', '-c',
            'extensions/filament/phpstan.neon', '--no-progress', '--memory-limit=512M']),
    ):
        sys.stderr.write(suite.command(argv)); cases.append({'id':name, 'status':'passed'})
    packaging = suite.no_dev.verify()
    cases.append({'id':'profile-admin.production-no-dev', 'status':'passed'})
    report = {'mode':'contract-scripted', 'tier':'contract-scripted', 'cases':cases, 'count':len(cases),
        'failures':0, 'skips':0, 'postgres':postgres, 'shell':shell, 'packaging':packaging,
        'input_hashes':gate.hashes(gate.ROOT, gate.PROFILE_ADMIN_INPUTS), 'limitations':matrix['limitations']}
    gate.validate_report(gate.SUITES[('profile-publish-admin', 'test')], report)
    return report

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'mode':'contract-scripted', 'tier':'contract-scripted', 'cases':[], 'error':str(error)}))
        sys.exit(1)
