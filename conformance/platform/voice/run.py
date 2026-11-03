#!/usr/bin/env python3
"""Conductor E2E partition: real kernel/PG with scripted audio/provider/host ports."""
import json
from pathlib import Path
import subprocess
import sys

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[2]
sys.path.insert(0,str(ROOT/'tools'))
import platform_gate as gate
import qualification

def run():
    p=subprocess.run([sys.executable,'-B',str(HERE/'pg.py')],cwd=ROOT,capture_output=True,text=True,timeout=105)
    sys.stderr.write(p.stderr)
    gate.require(p.returncode==0,'Required voice journey PostgreSQL class failed')
    pg=json.loads(p.stdout)
    gate.validate_report(gate.Suite('P11.3-pg','isolated-postgres','isolated-postgres',gate.VOICE_JOURNEY_CASES,(),(),True),pg)
    unit=subprocess.run([sys.executable,'-B',str(HERE/'test_qualification.py')],cwd=ROOT,capture_output=True,text=True,timeout=30)
    sys.stderr.write(unit.stderr)
    gate.require(unit.returncode==0,'Required voice qualification fixture tests failed')
    hooks=json.loads(unit.stdout)
    gate.validate_report(gate.Suite('P11.3-hooks','contract-scripted','contract-scripted',('voice-journey.qualification-fixtures',),(),()),hooks)
    analysis=['extensions/loqum/vendor/bin/phpstan','analyse','-c','examples/platform/voice/phpstan.neon','--no-progress']
    checked=subprocess.run(analysis,cwd=ROOT,capture_output=True,text=True,timeout=90)
    sys.stderr.write(checked.stdout+checked.stderr)
    gate.require(checked.returncode==0,'Required voice example static analysis failed')
    sources=gate.hashes(ROOT,gate.VOICE_JOURNEY_INPUTS)
    tuple_=qualification.runtime_tuple(pg,sources)
    certificate=qualification.assess(pg,tuple_,sources)
    report=dict(pg,mode='local-host-e2e',tier='local-host-e2e',postgres=pg,
                evidence_kind='voice_journey_contract_harness',input_hashes=sources,qualification=certificate,
                qualification_fixtures=hooks,static_analysis={'command':analysis,'exit':checked.returncode},
                live_provider='not_executed',acoustic_quality='not_measured',child_speech_gold='insufficient_evidence',
                external_host_e2e='not_executed',production_readiness='not_claimed',
                limitations=['Audio/noise/STT/TTS/model/host ports are scripted; native compiler/intake/runtime/Actions/delivery/PG are real.',
                             'Technical exact-tuple evidence only; acoustic/child speech/domain/remote host/pilot gold remains external opt-in.'])
    gate.validate_report(gate.SUITES[('voice-journey','e2e')],report)
    return report

if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)}));sys.exit(1)
