#!/usr/bin/env python3
"""Exact local host contracts; external Edufy implementation explicitly pending."""
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


def run():
    rows = []
    for name in ('identity.php', 'boundary.php', 'report.php', 'ownership.php', 'boundary_test.py'):
        command = [sys.executable, '-B', str(HERE / name)] if name.endswith('.py') else ['php', str(HERE / name)]
        process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=45)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        if process.returncode or report.get('mode') != 'contract-scripted' or report.get('tier') != 'contract-scripted' or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required host contract file failed: ' + name)
        rows.extend(report['cases'])
    exported = json.loads((ROOT / 'examples/platform/edufy-host/change-request.json').read_text())
    report = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'evidence_kind': 'host_contract_harness',
              'cases': rows, 'count': len(rows), 'failures': 0, 'skips': 0,
              'input_hashes': gate.hashes(ROOT, gate.HOST_INPUTS),
              'external_owner': {'status': exported['status'], 'change_request': exported['id'],
                                 'revision': exported['revision'], 'implemented_here': exported['implemented_here']},
              'host_e2e': 'pending', 'live_provider': 'not_executed', 'production_readiness': 'not_claimed',
              'limitations': ['Synthetic single-process owner/intake/confirmation-evidence stubs; no PG durability, crash recovery or dated confirmation token proof.',
                              'No external Edufy implementation, provider support, educational qualification, pilot or production release.']}
    gate.validate_report(gate.SUITES[('host', 'test')], report)
    return report


if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases': [], 'error': str(error)}))
        sys.exit(1)
