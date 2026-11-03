#!/usr/bin/env python3
"""Exact qualification contract + real isolated PostgreSQL partitions; offline only."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[5]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


def main():
    parts = []
    logs = []
    report = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': [],
              'evidence_kind': 'contract-scripted', 'readiness_claim': 'technical-contract-only',
              'live_provider': 'not_run', 'educational_qualification': 'insufficient_evidence',
              'limitations': ['Scripted catalog/host/measurements; real Router and isolated PostgreSQL.',
                              'No production rollout, live provider, actual remote host, expert domain scorer or pilot.']}
    try:
        for name in ('evidence', 'routing', 'canary'):
            command = ['php', f'packages/agentools-core/tests/Models/Qualification/{name}.php']
            process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=60)
            logs.append({'command': command, 'exit': process.returncode, 'stdout': process.stdout, 'stderr': process.stderr})
            gate.require(process.returncode == 0, f'{name} contract tests failed')
            part = json.loads(process.stdout)
            gate.validate_report(gate.Suite('P12.2-part', 'contract-scripted', 'contract-scripted',
                gate.QUALIFICATION_PARTS[name], (), ()), part)
            parts.append(part)
            report['cases'].extend(part['cases'])
        pg = gate.run_suite(ROOT, gate.SUITES[('qualification-rollout', 'pg-test')], isolated=True, timeout=120)
        logs.append(pg)
        gate.require(pg['ok'], 'qualification isolated PostgreSQL tests failed')
        report['postgres'] = pg
        report['cases'].extend(pg['report']['cases'])
        command = ['packages/agentools-postgres/vendor/bin/phpstan', 'analyse', '-c',
            'packages/agentools-postgres/tests/Qualification/phpstan.neon', '--no-progress', '--memory-limit=512M',
            'packages/agentools-core/src/Models/Qualification', 'packages/agentools-core/tests/Models/Qualification',
            'packages/agentools-postgres/src/Qualification', 'packages/agentools-postgres/tests/Qualification']
        process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=120)
        logs.append({'command': command, 'exit': process.returncode, 'stdout': process.stdout, 'stderr': process.stderr})
        gate.require(process.returncode == 0, 'qualification scoped static analysis failed')
        report['static_analysis'] = {'command': command, 'exit': process.returncode}
        report['cases'].append({'id': 'qualification.static-analysis', 'status': 'passed'})
        report.update(contract_parts=parts, count=len(report['cases']), failures=0, skips=0,
                      input_hashes=gate.hashes(ROOT, gate.QUALIFICATION_INPUTS))
        return_code = 0
    except (gate.GateError, OSError, ValueError, subprocess.SubprocessError) as error:
        report.update(error=str(error), failures=1, skips=0, count=len(report['cases']))
        return_code = 1
    for log in logs:
        sys.stderr.write(json.dumps(log, ensure_ascii=False) + '\n')
    print(json.dumps(report, ensure_ascii=False))
    return return_code


if __name__ == '__main__':
    sys.exit(main())
