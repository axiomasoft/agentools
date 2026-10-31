#!/usr/bin/env python3
"""Conductor-owned complete adapter partition: exact child results, no skips."""
from pathlib import Path
import json
import subprocess
import sys
ROOT=Path(__file__).resolve().parents[3]
COMMANDS=[['php','packages/agentools-model-adapter/tests/run.php'],
          [sys.executable,'-B','packages/agentools-model-adapter/tests/loopback.py'],
          [sys.executable,'-B','packages/agentools-model-adapter/tests/no_dev.py']]

def main():
    cases=[];reports=[];failed=False
    for cmd in COMMANDS:
        p=subprocess.run(cmd,cwd=ROOT,capture_output=True,text=True,timeout=120)
        if p.stderr: print(p.stderr,end='',file=sys.stderr)
        try:
            report=json.loads(p.stdout)
            if not isinstance(report.get('cases'),list) or not report['cases']:raise ValueError('Missing actual cases')
            failed=failed or p.returncode!=0 or any(c.get('status')!='passed' for c in report['cases']) or report.get('skips')!=0 or report.get('failures')!=0
            cases.extend(report['cases']);reports.append({'cmd':cmd,'exit':p.returncode,'report':report})
        except (ValueError,TypeError,KeyError):
            print(p.stdout,end='',file=sys.stderr);failed=True
    print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted','cases':cases,'count':len(cases),
                     'failures':sum(c['status']=='failed' for c in cases),'skips':sum(c['status']=='skipped' for c in cases),'subreports':reports}))
    return 1 if failed else 0
if __name__=='__main__':sys.exit(main())
