#!/usr/bin/env python3
"""Required isolated summary storage, competing connections and SIGKILL/restart partitions."""
import json
from pathlib import Path
import subprocess
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
def run():
    reports=[];rows=[];observations={}
    for name in ('durable.php','concurrency.php','recovery.php'):
        p=subprocess.run(['php',str(HERE/name)],cwd=ROOT,text=True,capture_output=True,timeout=45);sys.stderr.write(p.stderr)
        report=json.loads(p.stdout)
        if p.returncode or not report.get('cases') or report.get('failures')!=0 or report.get('skips')!=0:raise RuntimeError('Required isolated summary proof failed: '+name)
        reports.append(report);rows.extend(report['cases']);observations.update(report.get('observations',{}))
    isolation=dict(reports[0]['isolation']);isolation['case_ids']=[r['id'] for r in rows]
    if any(any(r['isolation'].get(k)!=isolation[k] for k in ('database','owner','allow_marker_verified')) for r in reports):raise RuntimeError('Inconsistent database isolation proof')
    sys.path.insert(0,str(ROOT/'tools'));import platform_gate
    return {'mode':'isolated-postgres','tier':'isolated-postgres','cases':rows,'count':len(rows),'failures':0,'skips':0,'isolation':isolation,
        'portable':reports,'observations':observations,'input_hashes':platform_gate.hashes(ROOT,platform_gate.HISTORY_INPUTS),
        'limitations':['Real isolated PostgreSQL with scripted external owner policies; no external service/live provider qualification.',
            'SIGKILL before commit and after commit; no claim that historical ManualClock/audit/reset findings are resolved.']}
if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:print(json.dumps({'mode':'isolated-postgres','tier':'isolated-postgres','cases':[],'error':str(error)}));sys.exit(1)
