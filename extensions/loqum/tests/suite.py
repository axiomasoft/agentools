#!/usr/bin/env python3
"""Conductor-owned acceptance partition; never installs dependencies or contacts providers."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT/'tools'))
import platform_gate as gate


def run():
    cases = []
    pg = None
    for file, command in (
        ('contract.php', ['php', str(HERE/'contract.php')]),
        ('schema.py', [sys.executable, '-B', str(HERE/'schema.py')]),
        ('no-dev/check.php', ['php', str(HERE/'no-dev/check.php')]),
        ('pg.py', [sys.executable, '-B', str(HERE/'pg.py')]),
    ):
        process = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=100)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        expected = gate.VOICE_INGRESS_PG_CASES if file == 'pg.py' else {
            'contract.php': gate.VOICE_INGRESS_CONTRACT_CASES,
            'schema.py': gate.VOICE_INGRESS_SCHEMA_CASES,
            'no-dev/check.php': ('voice.production-no-dev',),
        }[file]
        tier = 'isolated-postgres' if file == 'pg.py' else 'contract-scripted'
        gate.require(process.returncode == 0, 'Voice component failed: '+file)
        gate.validate_report(gate.Suite('P11.1-component', tier, tier, expected, (), (), file == 'pg.py'), report)
        cases.extend(report['cases'])
        if file == 'pg.py':
            pg = report
    for case, commands in (
        ('voice.composer-valid', [['composer','validate','--strict','--working-dir=extensions/loqum'],
                                  ['composer','validate','--strict','--working-dir=extensions/loqum/tests/no-dev']]),
        ('voice.static-analysis', [['extensions/loqum/vendor/bin/phpstan','analyse','-c','extensions/loqum/phpstan.neon','--no-progress']]),
    ):
        for command in commands:
            process = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=90)
            sys.stderr.write(process.stdout+process.stderr)
            gate.require(process.returncode == 0, 'Voice required check failed: '+case)
        cases.append({'id':case,'status':'passed'})
    report = {'mode':'contract-scripted','tier':'contract-scripted','cases':cases,
              'count':len(cases),'failures':0,'skips':0,
              'input_hashes':gate.hashes(ROOT,gate.VOICE_INGRESS_INPUTS),
              'evidence_kind':'voice_ingress_contract_harness', 'postgres':pg,
              'live_provider':'not_executed','production_readiness':'not_claimed',
              'limitations':['Authenticated host/media owner is scripted; shared durable PostgreSQL intake is real.',
                             'No STT/TTS/acoustic qualification, playout receipts or Action cancellation evidence.']}
    gate.validate_report(gate.SUITES[('voice-ingress','test')],report)
    return report


if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)}))
        sys.exit(1)
