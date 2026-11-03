#!/usr/bin/env python3
"""Clean target bootstrap with real isolated PostgreSQL and scripted owners."""
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate

def run():
    keys = [('read-journey','e2e'), ('write-recovery','e2e'), ('bootstrap-restore','pg-test')]
    parts=[]
    for key in keys:
        result=gate.run_suite(ROOT,gate.SUITES[key],isolated=True,timeout=180)
        sys.stderr.write(result['stderr'])
        gate.require(result.get('ok') is True, 'Required recovery partition failed: '+str(key)+' '+str(result.get('error')))
        parts.append({'key':list(key),'exit':result['exit'],'ok':result['ok'],
                      'dependency_hashes':result['dependency_hashes'],'report':result['report']})
    first=parts[0]['report']['isolation']
    for part in parts[1:]:
        proof=part['report']['isolation']
        gate.require(all(proof.get(k)==first.get(k) for k in ('database','owner','allow_marker_verified')),
                     'Recovery partitions must use the same proven isolated database')
    cases=[{'id':case,'status':'passed'} for case in gate.BOOTSTRAP_RECOVERY_CASES]
    isolation=dict(first,case_ids=[case['id'] for case in cases])
    return {'mode':'local-host-e2e','tier':'local-host-e2e','cases':cases,'count':len(cases),
            'failures':0,'skips':0,'isolation':isolation,'parts':parts,
            'input_hashes':gate.hashes(ROOT,gate.BOOTSTRAP_RECOVERY_INPUTS),
            'evidence_kind':'host_contract_harness','live_provider':'not_executed',
            'limitations':['Scripted host/provider and destination; no live owner/provider or production qualification.',
                           'Backup restores a dedicated schema in an isolated test database, not a production cluster.',
                           'Usage correction export rows are preserved; external metering service is not exercised.']}

if __name__ == '__main__':
    try: print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases':[],'error':str(error)})); sys.exit(1)
