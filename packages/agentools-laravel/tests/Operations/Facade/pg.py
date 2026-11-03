#!/usr/bin/env python3
"""Exact operations + reused scheduler durable partition; never a contract substitute for PG."""
import json
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[5]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate

def main():
    cases=[]; parts=[]
    for key in (('operations-facade','pg-test'),('operations-cadence','pg-test')):
        result=gate.run_suite(ROOT,gate.SUITES[key],isolated=True)
        parts.append(result)
        if not result.get('ok'): raise gate.GateError('Required durable partition failed: ' + str(key))
        cases.extend(result['report']['cases'])
    proof=dict(parts[0]['report']['isolation'],case_ids=[c['id'] for c in cases],
        scope='partitioned-see-parts',partitions=[p['report']['isolation'] for p in parts])
    return {'mode':'isolated-postgres','tier':'isolated-postgres','cases':cases,'count':len(cases),
        'failures':0,'skips':0,'isolation':proof,'parts':parts,
        'evidence_kind':'isolated-postgres','input_hashes':gate.hashes(ROOT,gate.OPERATIONS_INPUTS),
        'limitations':['Scripted external owners/providers; actual isolated PostgreSQL and existing scheduler.']}
if __name__=='__main__':
    try: print(json.dumps(main()))
    except Exception as error:
        print(json.dumps({'error':type(error).__name__ + ': operations durable partition failed'})); sys.exit(1)
