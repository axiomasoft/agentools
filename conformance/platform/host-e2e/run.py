#!/usr/bin/env python3
"""Local E2E with real isolated PostgreSQL; external owners remain scripted."""
import json
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[2]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate

def run():
    checked=subprocess.run([sys.executable,'-B',str(ROOT/'docs/platform/integrations/handoff.py'),'--check'],cwd=ROOT,capture_output=True,text=True,timeout=30)
    if checked.returncode: raise RuntimeError('Handoff missing or stale: '+checked.stderr)
    process=subprocess.run([sys.executable,'-B',str(HERE/'pg.py')],cwd=ROOT,capture_output=True,text=True,timeout=110)
    sys.stderr.write(process.stderr)
    if process.returncode: raise RuntimeError('Isolated host journey failed')
    report=json.loads(process.stdout)
    report.update(mode='local-host-e2e',tier='local-host-e2e', evidence_kind='host_contract_harness',
        input_hashes=gate.hashes(ROOT,gate.HOST_E2E_INPUTS),handoff_verified=True,
        external_host_e2e='not_executed',live_provider='not_executed',production_readiness='not_claimed',
        limitations=['School helpdesk and support owners are scripted; no remote host or provider exercised.',
                     'School helpdesk read is not an EducationApi report or confirmation/effect proof.'])
    gate.validate_report(gate.SUITES[('host-e2e','e2e')],report)
    return report
if __name__=='__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)}));sys.exit(1)
