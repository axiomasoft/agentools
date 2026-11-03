#!/usr/bin/env python3
"""Synthetic negative qualification fixtures; these test the checker, not a live deployment."""
import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parent))
import qualification as q

class QualificationTests(unittest.TestCase):
    def setUp(self):
        root=Path(__file__).resolve().parents[3]
        self.hashes={q.CORPUS:hashlib.sha256((root/q.CORPUS).read_bytes()).hexdigest(),'fixture':'a'*64}
        tuple_={k:('sha-256:'+'b'*64 if k.endswith('_hash') else 'scripted-fixture') for k in q.TUPLE_FIELDS-{'suite_hash'}}
        tuple_['material_corpus_hash']='sha-256:'+self.hashes[q.CORPUS]
        self.report={'mode':'isolated-postgres','tier':'isolated-postgres','cases':[{'id':c,'status':'passed'} for c in q.CASES],
                     'count':len(q.CASES),'failures':0,'skips':0,
                     'isolation':{'engine':'postgresql','isolated':True,'allow_marker_verified':True,'case_ids':list(q.CASES),'database':'agentools_test_fixture','owner':'fixture'},
                     'observations':{c:{'model_calls':1,'native_invocations':1} for c in q.CASES}}
        self.report['observations']['voice-journey.runnable-example']['runnable_example']={'tuple':tuple_}
        self.tuple=q.runtime_tuple(self.report,self.hashes)
    def test_exact_tuple_and_external_limits(self):
        cert=q.assess(self.report,self.tuple,self.hashes)
        self.assertTrue(q.applicable(cert,self.tuple));self.assertEqual(cert['decision'],'qualified')
        self.assertEqual(cert['domain_qualification']['decision'],'insufficient_evidence')
        self.assertEqual(cert['live_provider'],'not_executed');self.assertEqual(cert['production_readiness'],'not_claimed')
        self.assertTrue(all(p['owner'] and p['contract'] and p['data'] and p['gate'] for p in cert['domain_qualification']['prerequisites']))
    def test_every_tuple_change_invalidates(self):
        cert=q.assess(self.report,self.tuple,self.hashes)
        for field in q.TUPLE_FIELDS:
            changed=dict(self.tuple);changed[field]+='changed'
            self.assertFalse(q.applicable(cert,changed),field)
            self.assertEqual(q.assess(self.report,changed,self.hashes)['decision'],'rejected',field)
    def test_missing_failed_skipped_cannot_qualify(self):
        for status in ['failed','skipped','unknown']:
            report=copy.deepcopy(self.report);report['cases'][0]['status']=status
            self.assertEqual(q.assess(report,self.tuple,self.hashes)['decision'],'rejected')
        for field,value in [('cases',[]),('observations',{}),('isolation',{}),('count',0),('skips',1)]:
            report=dict(self.report,**{field:value});self.assertEqual(q.assess(report,self.tuple,self.hashes)['decision'],'rejected')
    def test_duplicate_model_io_and_stale_source_reject(self):
        report=copy.deepcopy(self.report);report['observations']['ECO-VOICE-002']['model_calls']=2
        self.assertIn('duplicate_or_unaccounted_model_io',q.assess(report,self.tuple,self.hashes)['reasons'])
        changed=dict(self.hashes,fixture='c'*64);self.assertEqual(q.assess(self.report,self.tuple,changed)['decision'],'rejected')

if __name__=='__main__':
    suite=unittest.defaultTestLoader.loadTestsFromTestCase(QualificationTests)
    result=unittest.TextTestRunner(stream=sys.stderr).run(suite)
    passed=result.wasSuccessful() and result.testsRun==4 and not result.skipped
    print(json.dumps({'mode':'contract-scripted','tier':'contract-scripted',
                      'cases':[{'id':'voice-journey.qualification-fixtures','status':'passed' if passed else 'failed'}],
                      'count':1,'failures':0 if passed else 1,'skips':0,'fixture_tests':result.testsRun}))
    sys.exit(0 if passed else 1)
