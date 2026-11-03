"""Focused technical evaluator regressions; no endpoint or domain qualification."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'packages/agentools-testing/src/Evaluation'))
sys.path.insert(0, str(ROOT / 'tools'))
import harness as h
import platform_gate as gate

CASES = {
    'test_schema_examples': 'evaluation.schema-examples',
    'test_schema_negative': 'evaluation.closed-schema-negative',
    'test_teacher_golden': 'evaluation.teacher-technical-golden',
    'test_support_golden': 'evaluation.second-host-technical-golden',
    'test_domain_absent': 'evaluation.domain-insufficient-evidence',
    'test_deterministic': 'evaluation.deterministic-scorers',
    'test_hard_faults': 'evaluation.hard-invariant-faults',
    'test_unknown_evidence': 'evaluation.unknown-not-success',
    'test_not_run': 'evaluation.not-run-distinct',
    'test_foreign_evidence': 'evaluation.tenant-audience-access-negative',
    'test_budget': 'evaluation.budget-negative-and-unknown',
    'test_case_hash': 'evaluation.case-hash-conflict',
    'test_run_hash': 'evaluation.run-hash-conflict',
    'test_registry_identity': 'evaluation.tenant-scoped-immutable-identity',
    'test_missing_cases': 'evaluation.registry-empty-missing-duplicate-negative',
    'test_integrations': 'evaluation.existing-conformance-exact-links',
    'test_integration_faults': 'evaluation.integration-missing-skip-fail-negative',
    'test_exact_tuple': 'evaluation.required-tuple-invalidation',
    'test_qualification_cases': 'evaluation.qualification-case-set-negative',
    'test_result_hash': 'evaluation.result-config-hash-negative',
    'test_claim': 'evaluation.fake-production-claim-negative',
    'test_no_global_threshold': 'evaluation.no-invented-domain-threshold',
    'test_missing_receipt': 'evaluation.integration-receipt-required',
    'test_local_refs': 'evaluation.local-reference-escape-negative',
    'test_missing_scorer_dependency': 'evaluation.missing-scorer-dependency-negative',
}


def load(name):
    return json.loads((ROOT / h.FIXTURES / name).read_text())


class EvaluationTests(unittest.TestCase):
    def setUp(self):
        self.case = load('teacher-case.json')
        self.suite = load('teacher-suite.json')
        self.run = load('teacher-run.json')
        self.observation = load('teacher-observation.json')

    def result(self):
        return h.score(self.case, self.run, self.observation)

    def certificate(self, *, suite=None, cases=None, run=None, results=None, tuple_=None, domain=False):
        return h.qualify(suite or self.suite, cases if cases is not None else [self.case], run or self.run,
                         results if results is not None else [self.result()],
                         tuple_ if tuple_ is not None else {k: self.run[k] for k in h.TUPLE_FIELDS},
                         qualification_id='test-certificate', issued_at=self.run['started_at'],
                         rollout_policy_ref='synthetic-disabled-production', domain=domain)

    def test_schema_examples(self):
        from jsonschema import Draft202012Validator
        for schema in h.SCHEMAS.glob('*.schema.json'):
            Draft202012Validator.check_schema(json.loads(schema.read_text()))
        for host in ('teacher', 'support'):
            for kind in ('case', 'suite', 'run', 'result', 'qualification', 'observation'):
                h.validate(kind, load(host + '-' + kind + '.json'))
            h.validate('qualification', load(host + '-insufficient-evidence.json'))

    def test_schema_negative(self):
        for kind in ('case', 'suite', 'run', 'result', 'qualification', 'observation'):
            value = load('teacher-' + kind + '.json')
            value['unexpected'] = 'must reject'
            with self.assertRaises(h.EvaluationError):
                h.validate(kind, value)
        for field in self.case:
            value = deepcopy(self.case)
            del value[field]
            with self.assertRaises(h.EvaluationError):
                h.validate('case', value)

    def test_teacher_golden(self):
        suite, cases, sha = h.load_suite(ROOT, h.FIXTURES + '/teacher-suite.json')
        self.assertEqual(sha, self.run['suite_hash'])
        result = self.result()
        self.assertEqual(result['scorer_outputs'][0]['status'], 'pass')
        self.assertEqual(result['status'], 'insufficient_evidence')
        q = self.certificate(suite=suite, cases=cases, results=[result])
        self.assertEqual(q['decision'], 'qualified')
        self.assertEqual(q['evidence_kind'], 'contract-scripted')
        self.assertEqual(q['readiness_claim'], 'technical-contract-only')

    def test_support_golden(self):
        self.case, self.suite, self.run, self.observation = [load('support-' + k + '.json') for k in ('case','suite','run','observation')]
        q = self.certificate()
        self.assertEqual(q['decision'], 'qualified')
        self.assertEqual(q['tuple']['profile_ref'], 'generic-support/v1')
        self.assertNotIn('lesson', json.dumps(load('support-input.json')))

    def test_domain_absent(self):
        self.assertEqual(self.certificate(domain=True)['decision'], 'insufficient_evidence')
        output = self.result()['scorer_outputs'][1]
        self.assertIsNone(output['value'])
        self.assertEqual(output['reason'], 'owner_domain_scorer_absent')

    def test_deterministic(self):
        self.assertEqual(h.canonical(self.result()), h.canonical(self.result()))
        reversed_ = dict(reversed(list(self.observation.items())))
        self.assertEqual(h.score(self.case, self.run, reversed_), self.result())

    def test_hard_faults(self):
        for invariant in h.HARD:
            with self.subTest(invariant=invariant):
                obs = deepcopy(self.observation)
                obs['invariants'][invariant] = False
                result = h.score(self.case, self.run, obs)
                self.assertEqual(result['status'], 'fail')
                self.assertEqual(self.certificate(results=[result])['decision'], 'rejected')

    def test_unknown_evidence(self):
        for invariant in h.HARD:
            obs = deepcopy(self.observation)
            obs['invariants'][invariant] = None
            self.assertEqual(h.score(self.case,self.run,obs)['scorer_outputs'][0]['status'], 'insufficient_evidence')
            del obs['invariants'][invariant]
            self.assertEqual(h.score(self.case,self.run,obs)['status'], 'insufficient_evidence')
        obs['evidence_refs'] = []
        self.assertEqual(h.score(self.case,self.run,obs)['status'], 'insufficient_evidence')

    def test_not_run(self):
        result = h.score(self.case, self.run, None)
        self.assertEqual(result['status'], 'not_run')
        self.assertEqual(self.certificate(results=[result])['decision'], 'insufficient_evidence')

    def test_foreign_evidence(self):
        for section, field, value in (('observation','tenant_ref','foreign'),('observation','audience_ref','foreign'),
                ('ref','tenant_ref','foreign'),('ref','audience_ref','foreign'),('ref','ref','not-allowed'),('ref','redacted',False)):
            obs = deepcopy(self.observation)
            target = obs if section == 'observation' else obs['evidence_refs'][0]
            target[field] = value
            with self.assertRaises(h.EvaluationError):
                h.score(self.case, self.run, obs)

    def test_budget(self):
        for field in ('cost_minor','latency_ms','provider_calls'):
            obs = deepcopy(self.observation)
            obs['usage'][field] = 1001
            self.assertEqual(h.score(self.case,self.run,obs)['status'], 'fail')
            obs['usage'][field] = None
            self.assertEqual(h.score(self.case,self.run,obs)['status'], 'insufficient_evidence')
        obs['usage']['currency'] = 'EUR'
        with self.assertRaises(h.EvaluationError):
            h.score(self.case,self.run,obs)
        run = h.seal_run(dict(self.run,budget=dict(self.run['budget'],max_latency_ms=0)))
        obs = deepcopy(self.observation)
        obs['usage']['latency_ms'] = 1
        self.assertEqual(h.score(self.case,run,obs)['status'],'fail')

    def test_case_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            fixture = root / self.case['input_fixture']['path']
            fixture.parent.mkdir(parents=True)
            fixture.write_text('changed stimulus')
            (root/'case.json').write_text(json.dumps(self.case))
            with self.assertRaisesRegex(h.EvaluationError, 'stimulus hash'):
                h.load_case(root,'case.json')
        self.case['config']['changed'] = True
        self.assertNotEqual(h.content_hash(self.case), self.suite['case_refs'][0]['case_hash'])
        self.assertEqual(self.certificate()['decision'], 'rejected')
        changed = deepcopy(self.case)
        changed['scorers'][0]['config']['implementation_hash'] = h.content_hash('obsolete-scorer')
        with self.assertRaisesRegex(h.EvaluationError,'scorer missing/stale'):
            h.score(changed,self.run,self.observation)

    def test_run_hash(self):
        for field in h.TUPLE_FIELDS:
            run = deepcopy(self.run)
            run[field] += 'changed'
            with self.assertRaises(h.EvaluationError):
                h.verify_run(run,self.run['suite_hash'])

    def test_registry_identity(self):
        registry = h.ImmutableRegistry()
        first = registry.register('tenant-synthetic','run',self.run['run_id'],self.run)
        self.assertEqual(first,registry.register('tenant-synthetic','run',self.run['run_id'],self.run))
        first['seed'] = 3
        changed = h.seal_run(dict(self.run,seed=3))
        with self.assertRaisesRegex(h.EvaluationError,'hash conflict'):
            registry.register('tenant-synthetic','run',self.run['run_id'],changed)
        other = h.seal_run(dict(changed,tenant_ref='other-tenant'))
        registry.register('other-tenant','run',other['run_id'],other)
        with self.assertRaises(h.EvaluationError):
            registry.register('foreign','run',self.run['run_id'],self.run)
        registry.register('tenant-synthetic','suite',self.suite['suite_id'],self.suite)
        registry.register('tenant-synthetic','suite',self.suite['suite_id'],dict(self.suite,version='2'))
        with self.assertRaisesRegex(h.EvaluationError,'hash conflict'):
            registry.register('tenant-synthetic','suite',self.suite['suite_id'],dict(self.suite,required_scorers=['technical.hard','new.scorer']))

    def test_missing_cases(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for refs in ([],[dict(self.suite['case_refs'][0],path='absent.json')],self.suite['case_refs']*2):
                (root/'suite.json').write_text(json.dumps(dict(self.suite,case_refs=refs)))
                with self.assertRaises(h.EvaluationError):
                    h.load_suite(root,'suite.json')

    def test_integrations(self):
        for name in ('technical-golden','evaluation-faults'):
            suite = load(name + '-suite.json')
            h.load_suite(ROOT, h.FIXTURES + '/' + name + '-suite.json')
            keys = h.validate_integrations(suite, gate.SUITES)
            self.assertTrue(keys)
            self.assertEqual(keys,[(r['suite'],r['partition']) for r in suite['integration_refs']])
        faults = load('evaluation-faults-suite.json')['integration_refs']
        self.assertEqual(faults[0]['source_receipt_sha256'], 'sha-256:76eb0dc5a3e484b9adafbbe15d1e862479c91e2fb1a9f116e401a9083ea2e00d')
        self.assertEqual(faults[1]['source_receipt_sha256'], 'sha-256:003b6be9c7d387883e9928a25ee75771c3659e88b38995ffe8062632ad3e1437')

    def test_integration_faults(self):
        suite = load('evaluation-faults-suite.json')
        with self.assertRaises(h.EvaluationError):
            h.validate_integrations(suite,{})
        for ids in ([],suite['integration_refs'][0]['required_case_ids'][:-1],['fake']):
            bad = deepcopy(suite)
            bad['integration_refs'][0]['required_case_ids'] = ids
            with self.assertRaises(h.EvaluationError):
                h.validate_integrations(bad,gate.SUITES)
        spec = gate.SUITES[('provider-fallback','test')]
        for status in ('failed','skipped','unknown'):
            report = {'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases]}
            report['cases'][0]['status'] = status
            with self.assertRaises(gate.GateError):
                gate.validate_report(spec,report)

    def test_exact_tuple(self):
        q = self.certificate()
        current = deepcopy(q['tuple'])
        self.assertTrue(h.applicable(q,current))
        for field in h.TUPLE_FIELDS:
            changed = dict(current)
            changed[field] = h.content_hash('changed') if field.endswith('_hash') else 'changed'
            self.assertFalse(h.applicable(q,changed),field)
            self.assertEqual(self.certificate(tuple_=changed)['decision'], 'rejected')
        self.assertFalse(h.applicable(q,dict(current,extra='unbound')))

    def test_qualification_cases(self):
        for results in ([],[self.result(),self.result()]):
            self.assertEqual(self.certificate(results=results)['decision'],'rejected')
        self.suite['required_scorers'] = ['missing.scorer']
        run = h.seal_run(dict(self.run,suite_hash=h.content_hash(self.suite)))
        result = h.score(self.case,run,self.observation)
        with self.assertRaises(h.EvaluationError):
            self.certificate(run=run,results=[result])

    def test_result_hash(self):
        result = self.result()
        result['scorer_outputs'][0]['status'] = 'fail'
        with self.assertRaisesRegex(h.EvaluationError,'result hash'):
            self.certificate(results=[result])
        result = self.result()
        result['scorer_outputs'][0]['config_hash'] = h.content_hash('foreign-config')
        result['result_hash'] = h.content_hash({k:v for k,v in result.items() if k!='result_hash'})
        with self.assertRaisesRegex(h.EvaluationError,'config hash'):
            self.certificate(results=[result])

    def test_claim(self):
        for tier in h.TIERS[:-1]:
            with self.assertRaises(h.EvaluationError):
                h.validate_claim(tier,'production')
            h.validate_claim(tier,'technical-contract-only')
        for alias in ('fixture','host_contract_harness','live_provider'):
            with self.assertRaises(h.EvaluationError):
                h.validate_claim(alias,'technical-contract-only')
        q = self.certificate()
        q['readiness_claim'] = 'production'
        with self.assertRaises(h.EvaluationError):
            h.validate('qualification',q)
        for tier in ('design-schema','unit'):
            run = h.seal_run(dict(self.run,evidence_kind=tier))
            result = h.score(self.case,run,self.observation)
            self.assertEqual(self.certificate(run=run,results=[result])['decision'],'insufficient_evidence')

    def test_no_global_threshold(self):
        self.assertEqual(set(self.result()['metrics']),set(h.METRICS))
        self.assertTrue(all(v is None for v in self.result()['metrics'].values()))
        self.observation['metrics'].update(quality=0,grounding=0)
        self.assertEqual(self.certificate()['decision'],'qualified')
        self.assertEqual(self.certificate(domain=True)['decision'],'insufficient_evidence')

    def test_missing_receipt(self):
        ref = load('evaluation-faults-suite.json')['integration_refs'][0]
        suite = dict(self.suite,integration_refs=[ref])
        run = h.seal_run(dict(self.run,suite_hash=h.content_hash(suite)))
        result = h.score(self.case,run,self.observation)
        q = self.certificate(suite=suite,run=run,results=[result],tuple_={k:run[k] for k in h.TUPLE_FIELDS})
        self.assertEqual(q['decision'],'insufficient_evidence')
        receipt = {'suite':ref['suite'], 'partition':ref['partition'], 'receipt_hash':h.content_hash('actual-receipt'),
                   'evidence_kind':'contract-scripted','required_case_ids':ref['required_case_ids'],
                   'executed_case_ids':ref['required_case_ids'], 'failures':0,'skips':0,
                   'input_hashes':{'test-source':h.content_hash('source')}}
        for field, value in (('skips',1),('failures',1),('executed_case_ids',ref['required_case_ids'][:-1])):
            bad = dict(receipt,**{field:value})
            changed_run = h.seal_run(dict(run,integration_receipts=[bad]))
            result = h.score(self.case,changed_run,self.observation)
            q = self.certificate(suite=suite,run=changed_run,results=[result],
                                 tuple_={k:changed_run[k] for k in h.TUPLE_FIELDS})
            self.assertEqual(q['decision'],'rejected')

    def test_missing_scorer_dependency(self):
        from unittest.mock import patch
        import builtins
        original = builtins.__import__
        def unavailable(name, *args, **kwargs):
            if name == 'jsonschema':
                raise ImportError('deliberately absent')
            return original(name, *args, **kwargs)
        with patch('builtins.__import__',side_effect=unavailable), self.assertRaisesRegex(h.EvaluationError,'dependency missing'):
            h.validate('case', self.case)

    def test_local_refs(self):
        for ref in ('/etc/passwd','../../etc/passwd','absent.json'):
            with self.assertRaises(h.EvaluationError):
                h.local_file(ROOT,ref)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'escape').symlink_to(ROOT/'DEVELOPMENT.md')
            with self.assertRaises(h.EvaluationError):
                h.local_file(root,'escape')


if __name__ == '__main__':
    unittest.main()
