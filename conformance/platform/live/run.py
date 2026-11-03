#!/usr/bin/env python3
"""Fail-closed runner for P12.5 offline baseline and incident contract."""
import hashlib
import io
import json
from pathlib import Path
import sys
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(ROOT/'tools'))
import test_baseline
import platform_gate as gate

MAP = {
    'test_baseline_record': 'baseline.recorded-measurements',
    'test_actual_golden_fault': 'baseline.actual-golden-fault',
    'test_playbooks': 'incidents.required-playbooks',
    'test_probe_complete_is_not_live': 'live.complete-is-not-qualified',
    'test_probe_missing_binding': 'live.missing-bindings-refused',
    'test_probe_data_and_spend': 'live.data-spend-fail-closed',
    'test_probe_sources_stale_or_missing': 'live.stale-incomplete-evidence-refused',
    'test_probe_never_resolves_credentials': 'live.offline-no-io',
}


class Recorder(unittest.TextTestResult):
    rows = None
    def addSuccess(self,test):
        super().addSuccess(test); self.rows.append({'id':MAP[test._testMethodName],'status':'passed'})
    def addError(self,test,error):
        super().addError(test,error); self.rows.append({'id':MAP[test._testMethodName],'status':'failed'})
    def addFailure(self,test,error):
        super().addFailure(test,error); self.rows.append({'id':MAP[test._testMethodName],'status':'failed'})
    def addSkip(self,test,reason):
        super().addSkip(test,reason); self.rows.append({'id':MAP[test._testMethodName],'status':'skipped'})


def main():
    discovered=unittest.defaultTestLoader.getTestCaseNames(test_baseline.Cases)
    if set(discovered)!=set(MAP): raise ValueError('test methods differ from case manifest')
    rows=[]
    class Result(Recorder): pass
    Result.rows=rows
    result=unittest.TextTestRunner(stream=sys.stderr,verbosity=2,resultclass=Result).run(
        unittest.TestSuite(test_baseline.Cases(method) for method in MAP))
    baseline=json.loads((ROOT/'docs/platform/baseline.json').read_text())
    return {'mode':'contract-scripted','tier':'contract-scripted','evidence_kind':'contract-scripted',
            'readiness_claim':'technical-contract-only','live_provider':'not_run',
            'cases':rows,'count':len(rows),'failures':len(result.failures)+len(result.errors),'skips':len(result.skipped),
            'input_hashes':gate.hashes(ROOT,gate.BASELINE_INPUTS),'baseline':baseline,
            'limitations':['Local scripted baseline and offline handoff; no live provider, real host or production qualification.',
                           'Live proof requires independently verified current primary sources and owner data/spend scope.']}

if __name__=='__main__':
    try:
        report=main(); print(json.dumps(report)); sys.exit(0 if report['count'] and not report['failures'] and not report['skips'] else 1)
    except Exception as exc:
        print(json.dumps({'error':str(exc),'cases':[]}));sys.exit(1)
