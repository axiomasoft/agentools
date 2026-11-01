#!/usr/bin/env python3
"""Owning skill-selection gate partition; real scripted behavioral cases, packaging and static checks."""
import json
from pathlib import Path
import subprocess
import sys

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[4]

def run():
    rows=[];portable=[];observations={}
    for name in ('contract.php','lifecycle.php','examples.php'):
        process=subprocess.run(['php',str(HERE/name)],cwd=ROOT,text=True,capture_output=True,timeout=30)
        sys.stderr.write(process.stderr)
        report=json.loads(process.stdout)
        if process.returncode or not report.get('cases') or report.get('failures')!=0 or report.get('skips')!=0:
            raise RuntimeError('Required skill behavioral partition failed')
        portable.append(report);rows.extend(report['cases']);observations.update(report.get('observations',{}))
    for id_,command in (
        ('skills.composer-valid',['composer','validate','--strict','--working-dir=packages/agentools-core']),
        ('skills.static-analysis',['php','packages/agentools-core/vendor/bin/phpstan','analyse','-c','packages/agentools-core/phpstan.neon',
            '--no-progress','packages/agentools-core/src/Profiles/Skills','packages/agentools-core/tests/Profiles/Skills']),
    ):
        process=subprocess.run(command,cwd=ROOT,text=True,capture_output=True,timeout=60)
        sys.stderr.write(process.stdout+process.stderr)
        if process.returncode:raise RuntimeError('Required check failed: '+id_)
        rows.append({'id':id_,'status':'passed'})
    from no_dev import verify
    packaging=verify();rows.append({'id':'skills.production-no-dev','status':'passed'})
    sys.path.insert(0,str(ROOT/'tools'))
    import platform_gate
    return {'mode':'contract-scripted','tier':'contract-scripted','cases':rows,'count':len(rows),'failures':0,'skips':0,
        'portable':portable,'observations':observations,'packaging':packaging,
        'input_hashes':platform_gate.hashes(ROOT,platform_gate.SKILL_INPUTS),
        'limitations':['Scripted authenticated publication/evaluation/content policy and recording transport; no live provider qualification.',
            'One selected skill per invocation; shortlist <=16. Host owns durable publication and selection snapshot storage.',
            'Real authorization, material/disclosure guards and qualified token counting remain required host ports; D8 excludes classical mutation tests.']}

if __name__=='__main__':
    try:print(json.dumps(run()))
    except Exception as error:print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':[],'error':str(error)}));sys.exit(1)
