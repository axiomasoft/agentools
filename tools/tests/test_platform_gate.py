"""Focused offline tests of tooling, not evidence of future platform runtime."""
import contextlib
from dataclasses import replace
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools"))
import platform_gate as gate


class GateTests(unittest.TestCase):
    def test_provider_fallback_registry(self):
        self.assertIn('GateTests.test_provider_fallback_registry', gate.SUITES[('gate-self', 'test')].cases)
        contract = gate.SUITES[('provider-fallback', 'test')]
        self.assertEqual((contract.owner, contract.mode, contract.tier), ('P9.5', 'contract-scripted', 'contract-scripted'))
        self.assertEqual(len(contract.cases), 22)
        self.assertEqual(len(set(contract.cases)), 22)
        self.assertIn('fallback.unknown-effect-before-continuation', contract.cases)
        self.assertIn('cost.unknown-correction-replaces-once', contract.cases)
        self.assertTrue(all(not path.startswith('plans/') for path in contract.dependencies))
        self.assertIn('packages/agentools-core/src/Models/Routing/OutputBoundary.php', contract.dependencies)
        inputs = gate.hashes(ROOT, contract.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier, 'input_hashes': inputs,
                  'cases': [{'id': name, 'status': 'passed'} for name in contract.cases],
                  'live': {'status': 'pending_opt_in', 'executed': False, 'provider_support': 'not_qualified',
                           'educational_qualification': 'insufficient_evidence'},
                  'review_materialization': {'status': 'materialized_pending_coordinator_review', 'input_count': 100,
                      'archive_sha256': '1' * 64, 'reviewer_read_proof': 'not_yet_available', 'historical_pass_is_not_current_pass': True}}
        gate.validate_report(contract, report)
        for change in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]), dict(report, input_hashes={}),
                       dict(report, cases=[dict(c, status='skipped') for c in report['cases']]),
                       dict(report, live={}), dict(report, live=dict(report['live'], executed=True)),
                       dict(report, review_materialization={}),
                       dict(report, review_materialization=dict(report['review_materialization'], reviewer_read_proof='claimed_verified'))):
            with self.assertRaises(gate.GateError): gate.validate_report(contract, change)
        args = gate.parser().parse_args(['test', '--suite', 'provider-fallback'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, contract, isolated=False)

    def test_cache_manager_registry(self):
        self.assertIn('GateTests.test_cache_manager_registry', gate.SUITES[('gate-self', 'test')].cases)
        contract = gate.SUITES[('cache-manager', 'test')]
        pg = gate.SUITES[('cache-manager', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier), ('P9.4', 'contract-scripted'))
        self.assertEqual((pg.owner, pg.tier, pg.requires_pg), ('P9.4-pg', 'isolated-postgres', True))
        self.assertEqual((len(contract.cases), len(pg.cases)), (17, 11))
        self.assertEqual(len(set(pg.cases)), len(pg.cases))
        self.assertTrue({'CM-031', 'CM-032', 'CM-033', 'CM-034', 'V2ALL-CACHE-FAULT'}.issubset(contract.cases))
        self.assertTrue(all(not path.startswith('plans/') for path in pg.dependencies))
        hashes = gate.hashes(ROOT, contract.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier, 'input_hashes': hashes, 'observations': {},
                  'cases': [{'id': name, 'status': 'passed'} for name in contract.cases]}
        gate.validate_report(contract, report)
        for change in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]), dict(report, observations=[]),
                       dict(report, input_hashes={}), dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(contract, change)
        args = gate.parser().parse_args(['pg-test', '--suite', 'cache-manager', '--isolated'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, pg, isolated=True)
        signal = {'signaled': True, 'signal': 9, 'exit': -1}
        proof = {'mode': pg.mode, 'tier': pg.tier, 'input_hashes': hashes,
                 'cases': [{'id': name, 'status': 'passed'} for name in pg.cases],
                 'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                               'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(pg.cases)},
                 'observations': {
                     'cache.pg-competing-quota': {'backends': [1, 2], 'blocked': 2, 'exits': [0, 0], 'results': ['accepted', 'refused'], 'rows': 1},
                     'cache.pg-competing-use-revoke': {'backends': [1, 2], 'exits': [0, 0], 'after_revoke': 'denied'},
                     'cache.pg-revoke-crash-windows': {'before-commit': signal, 'after-commit': signal},
                     'cache.pg-delete-ack-crash': dict(signal, owner_operations=1, lookup_same_key=True),
                     'cache.pg-warm-reply-crash': dict(signal, owner_operations=1, usage_facts=1, exposure='unknown', automatic_release=False)}}
        gate.validate_report(pg, proof)
        for missing in ('observations', 'isolation', 'input_hashes'):
            with self.assertRaises(gate.GateError): gate.validate_report(pg, dict(proof, **{missing: {}}))
        malformed = dict(proof['observations'])
        malformed['cache.pg-revoke-crash-windows'] = {'before-commit': signal, 'after-commit': dict(signal, signal=0)}
        with self.assertRaises(gate.GateError): gate.validate_report(pg, dict(proof, observations=malformed))

    def test_usage_reservations_registry(self):
        self.assertIn('GateTests.test_usage_reservations_registry', gate.SUITES[('gate-self', 'test')].cases)
        contract = gate.SUITES[('usage-reservations', 'test')]
        pg = gate.SUITES[('usage-reservations', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier), ('P9.3', 'contract-scripted'))
        self.assertEqual((pg.owner, pg.tier, pg.requires_pg), ('P9.3-pg', 'isolated-postgres', True))
        self.assertEqual(len(contract.cases), 9)
        self.assertEqual(len(pg.cases), 16)
        self.assertEqual(len(set(pg.cases)), len(pg.cases))
        self.assertIn('ECO-ADM-003', pg.cases)
        self.assertIn('accounting.crash-export-ack', pg.cases)
        self.assertIn('accounting.atomic-response-export', pg.cases)
        self.assertTrue(all(not p.startswith('plans/') for p in pg.dependencies))
        inputs = gate.hashes(ROOT, pg.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier,
                  'cases': [{'id': c, 'status': 'passed'} for c in contract.cases], 'input_hashes': inputs}
        gate.validate_report(contract, report)
        for altered in (dict(report, cases=[]), dict(report, input_hashes={}),
                        dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(contract, altered)
        args = gate.parser().parse_args(['pg-test', '--suite', 'usage-reservations', '--isolated'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, pg, isolated=True)
        crash = {'signaled': True, 'signal': 9, 'exit': -1}
        pg_report = {'mode': pg.mode, 'tier': pg.tier, 'input_hashes': inputs,
                     'cases': [{'id': c, 'status': 'passed'} for c in pg.cases],
                     'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                                   'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(pg.cases)},
                     'observations': {
                         'accounting.competing-connections': {'backends': [1, 2], 'blocked': 2, 'exits': [0, 0], 'rows': 1},
                         'accounting.crash-commit-windows': {'before-commit': crash, 'after-commit': crash},
                         'accounting.crash-export-ack': dict(crash, owner_exports=1, replayed_same_identity=True)}}
        gate.validate_report(pg, pg_report)
        with self.assertRaises(gate.GateError): gate.validate_report(pg, dict(pg_report, observations={}))

    def setUp(self):
        self.spec = gate.Suite("self-test", "contract-scripted", "contract-scripted",
                               ("one", "two"), (sys.executable, "runner.py"), ())
        self.report = {"mode": "contract-scripted", "tier": "contract-scripted", "cases": [
            {"id": name, "status": "passed"} for name in self.spec.cases]}

    def rejects(self, report=None, spec=None):
        with self.assertRaises(gate.GateError):
            gate.validate_report(spec or self.spec, self.report if report is None else report)

    @contextlib.contextmanager
    def tree(self):
        with tempfile.TemporaryDirectory(prefix="agentools_gate_") as directory:
            root = Path(directory)
            for folder in (gate.CORE, gate.TESTING):
                target = root / folder
                target.mkdir(parents=True)
                shutil.copyfile(ROOT / folder / "composer.json", target / "composer.json")
            (root / gate.CORE / "src/Common").mkdir(parents=True)
            shutil.copyfile(ROOT / gate.CORE / "src/Common/IntegrityConflict.php",
                            root / gate.CORE / "src/Common/IntegrityConflict.php")
            (root / gate.TESTING / "bin").mkdir()
            shutil.copyfile(ROOT / gate.TESTING / "bin/php-tokens.php",
                            root / gate.TESTING / "bin/php-tokens.php")
            yield root

    def mutate(self, root, folder, change):
        path = root / folder / "composer.json"
        doc = json.loads(path.read_text())
        change(doc)
        path.write_text(json.dumps(doc))

    def test_architecture_fixture(self):
        fixture = json.loads((ROOT / gate.TESTING / "fixtures/architecture.json").read_text())
        for source in fixture["allowed"]:
            self.assertEqual(gate.source_errors(ROOT, source, "fixture.php"), [])
        for name, source in fixture["denied"].items():
            with self.subTest(name=name):
                self.assertTrue(gate.source_errors(ROOT, source, "fixture.php"), name)

    def test_boundary_current_tree(self):
        self.assertEqual(len(gate.check_boundaries()["cases"]), 4)

    def test_boundary_missing_source(self):
        with self.tree() as root:
            (root / gate.CORE / "src/Common/IntegrityConflict.php").unlink()
            with self.assertRaisesRegex(gate.GateError, "source missing"):
                gate.check_boundaries(root)

    def test_boundary_production_testing(self):
        with self.tree() as root:
            self.mutate(root, gate.CORE, lambda d: d["require"].update({"agentools/platform-testing": "*"}))
            with self.assertRaisesRegex(gate.GateError, "production.*testing"):
                gate.check_boundaries(root)

    def test_boundary_duplicate_owner(self):
        with self.tree() as root:
            self.mutate(root, gate.TESTING, lambda d: d.update(autoload={"psr-4": {"AgenToolsPlatform\\": "src/"}}))
            with self.assertRaisesRegex(gate.GateError, "overlapping namespace"):
                gate.check_boundaries(root)

    def test_boundary_core_dependency(self):
        with self.tree() as root:
            self.mutate(root, gate.CORE, lambda d: d["require"].update({"ext-pdo": "*"}))
            with self.assertRaisesRegex(gate.GateError, "core production dependencies"):
                gate.check_boundaries(root)

    def test_boundary_autoload_escape(self):
        with self.tree() as root:
            self.mutate(root, gate.CORE, lambda d: d["autoload"].update(files=["tests/fake.php"]))
            with self.assertRaisesRegex(gate.GateError, "production autoload"):
                gate.check_boundaries(root)

    def test_boundary_transitive_testing(self):
        with self.tree() as root:
            for name, dependency in (("adapter", "agentools/platform-helper"), ("helper", "agentools/platform-testing")):
                path = root / f"packages/{name}"
                path.mkdir()
                (path / "composer.json").write_text(json.dumps({
                    "name": f"agentools/platform-{name}", "license": "proprietary",
                    "autoload": {"psr-4": {f"Example\\{name}\\": "src/"}},
                    "require": {dependency: "*"}}))
            with self.assertRaisesRegex(gate.GateError, "transitive production testing"):
                gate.check_boundaries(root)

    def test_boundary_namespace_path(self):
        source = "<?php namespace AgenToolsPlatform\\Core\\Runtime; final class Kernel {}"
        self.assertTrue(gate.source_errors(ROOT, source, gate.CORE + "/src/Actions/Kernel.php"))

    def test_boundary_production_source(self):
        with self.tree() as root:
            path = root / "packages/adapter"
            (path / "src").mkdir(parents=True)
            (path / "composer.json").write_text(json.dumps({
                "name": "agentools/platform-adapter", "license": "proprietary",
                "autoload": {"psr-4": {"Example\\Adapter\\": "src/"}}, "require": {"php": ">=8.3"}}))
            (path / "src/Adapter.php").write_text(
                "<?php namespace Example\\Adapter; use AgenToolsPlatform\\Testing\\CaseResult;")
            with self.assertRaisesRegex(gate.GateError, "production testing/fake source"):
                gate.check_boundaries(root)

    def test_contradictory_totals(self):
        for name, value in (("failures", 1), ("skips", 1), ("count", 0), ("count", True)):
            with self.subTest(name=name, value=value):
                self.report[name] = value
                self.rejects()
                del self.report[name]

    def cli(self, argv):
        return subprocess.run([sys.executable, "-B", str(ROOT / "tools/platform_gate.py"), *argv],
                              cwd=ROOT, text=True, capture_output=True, timeout=30)

    def test_cli_missing_suite(self):
        result = self.cli(["test", "--suite", "not-registered"])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("not implemented/registered", json.loads(result.stdout)["error"])

    def test_cli_wrong_mode(self):
        for argv in (["test", "--suite", "gate-self", "--offline"], ["doctor"],
                     ["qualify", "--offline", "--live"], ["unknown-command"]):
            with self.subTest(argv=argv):
                self.assertNotEqual(self.cli(argv).returncode, 0)

    def test_cli_missing_pg_isolation(self):
        self.assertNotEqual(self.cli(["pg-test", "--suite", "gate-self"]).returncode, 0)
        result = self.cli(["pg-test", "--suite", "gate-self", "--isolated"])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("pg-test", result.stdout)

    def test_cli_future_commands(self):
        for argv in (["e2e", "--scenario", "bootstrap-recovery"],
                     ["qualify", "--offline"], ["acceptance", "--offline"], ["doctor", "--offline"]):
            with self.subTest(argv=argv):
                result = self.cli(argv)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(json.loads(result.stdout)["ok"])

    def test_knowledge_ingest_registry(self):
        matrix = json.loads((ROOT / 'extensions/knowledge/tests/cases.json').read_text())
        for partition, group in (('test', 'unit'), ('pg-test', 'postgres')):
            spec = gate.SUITES[('knowledge-ingest', partition)]
            self.assertEqual(spec.requires_pg, partition == 'pg-test')
            self.assertEqual(spec.owner, 'P8.1-pg' if spec.requires_pg else 'P8.1')
            self.assertEqual(spec.tier, 'isolated-postgres' if spec.requires_pg else 'contract-scripted')
            self.assertEqual(tuple(matrix[group].values()), gate.KNOWLEDGE_PG_CASES if spec.requires_pg else gate.KNOWLEDGE_UNIT_CASES)
            gate.hashes(ROOT, spec.dependencies)
            report = {'mode':spec.mode, 'tier':spec.tier, 'cases':[{'id':name,'status':'passed'} for name in spec.cases]}
            report.update(evidence_kind='host_contract_harness', limitations=matrix['limitations'],
                input_hashes=gate.hashes(ROOT, spec.dependencies),
                packaging={'knowledge':True, 'test_autoload':False, 'sdk':False, 'framework':False})
            if spec.requires_pg:
                report['isolation'] = {'engine':'postgresql', 'isolated':True, 'database':'agentools_test_knowledge',
                    'owner':'test', 'allow_marker_verified':True, 'case_ids':list(spec.cases)}
            gate.validate_report(spec, report)
            for changes in ({'input_hashes':None}, {'input_hashes':{}}, {'evidence_kind':'live-provider'},
                            {'limitations':[]}, {'packaging':{}} if not spec.requires_pg else {'isolation':{}}):
                with self.assertRaises(gate.GateError): gate.validate_report(spec, dict(report, **changes))
            stale = dict(report['input_hashes'])
            stale[next(iter(stale))] = '0' * 64
            with self.assertRaises(gate.GateError): gate.validate_report(spec, dict(report, input_hashes=stale))
            for cases in ([], report['cases'][:-1], report['cases'] + report['cases'][:1],
                          [dict(c,status='skipped') for c in report['cases']]):
                with self.assertRaises(gate.GateError): gate.validate_report(spec, dict(report,cases=cases))
            argv = [partition,'--suite','knowledge-ingest'] + (['--isolated'] if spec.requires_pg else [])
            with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)), [{'ok':True}])
            run.assert_called_once_with(ROOT,spec,isolated=spec.requires_pg)

    def test_knowledge_journey_registry(self):
        import copy
        spec=gate.SUITES[('knowledge-journey','e2e')]
        matrix=json.loads((ROOT/'extensions/knowledge/tests/Golden/cases.json').read_text())
        self.assertEqual((spec.owner,spec.mode,spec.tier,spec.requires_pg),('P8.4','local-host-e2e','local-host-e2e',True))
        self.assertEqual(tuple(matrix['postgres'].values()),gate.KNOWLEDGE_JOURNEY_CASES)
        self.assertEqual(tuple(matrix['unit'].values())+('knowledge-golden.static-analysis',),gate.KNOWLEDGE_JOURNEY_UNIT_CASES)
        report={'mode':spec.mode,'tier':spec.tier,'cases':[{'id':name,'status':'passed'} for name in spec.cases],
            'evidence_kind':'host_contract_harness','input_hashes':gate.hashes(ROOT,spec.dependencies),'limitations':matrix['limitations'],
            'transport':'scripted-knowledge/v1','extractor':'scripted-coordinate-extractor/v1','index':'isolated-postgresql-tsvector-gin',
            'hooks':{'mode':'contract-scripted','tier':'contract-scripted','cases':[{'id':name,'status':'passed'} for name in gate.KNOWLEDGE_JOURNEY_UNIT_CASES]},
            'isolation':{'engine':'postgresql','isolated':True,'database':'agentools_test_golden','owner':'test','allow_marker_verified':True,'case_ids':list(spec.cases)}}
        proof={'runtime':'AdvanceRun','provider_calls':1,'responses':1,'usage_versions':1,'index':'postgresql-tsvector-gin',
            'citations':[{'version':'v1'}],'source_hash':'a'*64,'release_hash':'b'*64,'snapshot_hash':'sha-256:'+'c'*64,'educational_qualification':'insufficient_evidence'}
        report['observations']={'support':dict(proof),'education':dict(proof),
            'correction':{'old_run':'old','new_run':'new','historical_unchanged':True,'old_snapshot_denied':True,'marker':'source_corrected','new_version':'v2'},
            'pre-send-correction':{'provider_calls':0,'usage_rows':0,'request_count':0,'outcome':'failed','possible_cost':False},
            'recovery':{'signal':9,'signaled':True,'worker_exit':-1,'provider_calls':1,'responses':1,'usage_versions':1,'delivery_messages':1,'lookup':'verified-same-request'}}
        report['observations']['education-correction']=dict(report['observations']['correction'])
        gate.validate_report(spec,report)
        for field in ('zero','missing','duplicate','skip','failed','unknown','tier','hashes','stale','kind','limits','isolation','hooks','observations','live','education','correction','recovery','refusal'):
            bad=copy.deepcopy(report)
            if field=='zero': bad['cases']=[]
            elif field=='missing': bad['cases'].pop()
            elif field=='duplicate': bad['cases']+=bad['cases'][:1]
            elif field in ('skip','failed','unknown'): bad['cases'][0]['status']={'skip':'skipped'}.get(field,field)
            elif field=='tier': bad['tier']='live-provider'
            elif field=='hashes': bad['input_hashes']={}
            elif field=='stale': bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
            elif field=='kind': bad['evidence_kind']='design-schema'
            elif field=='limits': bad['limitations']=[]
            elif field in ('isolation','hooks','observations'): bad[field]={}
            elif field=='live': bad['transport']='live-provider'
            elif field=='education': bad['observations']['education']['educational_qualification']='qualified'
            elif field=='correction': bad['observations']['correction']['historical_unchanged']=False
            elif field=='recovery': bad['observations']['recovery']['provider_calls']=2
            elif field=='refusal': bad['observations']['pre-send-correction']['possible_cost']=True
            with self.subTest(field=field),self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        for cases in ([],report['hooks']['cases'][:-1],[dict(c,status='skipped') for c in report['hooks']['cases']]):
            bad=copy.deepcopy(report);bad['hooks']['cases']=cases
            with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(gate.parser().parse_args(['e2e','--scenario','knowledge-journey'])),[{'ok':True}])
        run.assert_called_once_with(ROOT,spec,isolated=True)

    def test_knowledge_invalidation_registry(self):
        import copy
        matrix=json.loads((ROOT/'extensions/knowledge/tests/Invalidation/cases.json').read_text())
        for partition,group in (('test','unit'),('pg-test','postgres')):
            spec=gate.SUITES[('knowledge-invalidation',partition)]
            self.assertEqual(spec.owner,'P8.3-pg' if spec.requires_pg else 'P8.3')
            self.assertEqual(spec.requires_pg,partition=='pg-test')
            self.assertEqual(tuple(matrix[group].values()),gate.KNOWLEDGE_INVALIDATION_PG_CASES if spec.requires_pg else gate.KNOWLEDGE_INVALIDATION_UNIT_CASES)
            report={'mode':spec.mode,'tier':spec.tier,'cases':[{'id':name,'status':'passed'} for name in spec.cases],
                'input_hashes':gate.hashes(ROOT,spec.dependencies),'evidence_kind':'host_contract_harness','limitations':matrix['limitations'],
                'packaging':{'invalidation':True,'test_autoload':False,'sdk':False,'framework':False,'provider':False}}
            if spec.requires_pg:
                report['isolation']={'engine':'postgresql','isolated':True,'database':'agentools_test_invalidation','owner':'test',
                    'allow_marker_verified':True,'case_ids':list(spec.cases)}
                crash={'signal':9,'signaled':True,'worker_exit':-1,'retry_tombstones':1,'delete_intents':1,
                    'lost_response':'unknown','replayed_io':0,'new_attempt_io':1}
                report['observations']={'concurrency':{'competed':True,'backends':[11,12],'revocation':'committed','final_use':'denied'},
                    'crash-before':dict(crash),'crash-after':dict(crash),
                    'delete-crash':{'signal':9,'signaled':True,'worker_exit':-1,'lost_response':'unknown',
                        'replayed_io':0,'new_attempt_io':1,'provider_effects':1,'receipt':'succeeded'}}
            gate.validate_report(spec,report)
            for field in ('zero','missing','duplicate','skip','failed','unknown','tier','hashes','stale','kind','limits','proof'):
                bad=copy.deepcopy(report)
                if field=='zero': bad['cases']=[]
                elif field=='missing': bad['cases'].pop()
                elif field=='duplicate': bad['cases']+=bad['cases'][:1]
                elif field in ('skip','failed','unknown'): bad['cases'][0]['status']={'skip':'skipped'}.get(field,field)
                elif field=='tier': bad['tier']='live-provider'
                elif field=='hashes': bad['input_hashes']={}
                elif field=='stale': bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
                elif field=='kind': bad['evidence_kind']='design-schema'
                elif field=='limits': bad['limitations']=[]
                elif spec.requires_pg: bad['observations']={}
                else: bad['packaging']={}
                with self.subTest(partition=partition,field=field),self.assertRaises(gate.GateError): gate.validate_report(spec,bad)
            if spec.requires_pg:
                for change in ('competed','backends','signal','replayed_io','lost_response'):
                    bad=copy.deepcopy(report)
                    if change in ('competed','backends'): bad['observations']['concurrency'][change]=False if change=='competed' else [11,11]
                    else: bad['observations']['crash-before'][change]={'signal':0,'replayed_io':1,'lost_response':'succeeded'}[change]
                    with self.assertRaises(gate.GateError): gate.validate_report(spec,bad)
            argv=[partition,'--suite','knowledge-invalidation']+(['--isolated'] if spec.requires_pg else [])
            with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)),[{'ok':True}])
            run.assert_called_once_with(ROOT,spec,isolated=spec.requires_pg)

    def test_knowledge_retrieval_registry(self):
        import copy
        spec = gate.SUITES[('knowledge-retrieval', 'test')]
        matrix = json.loads((ROOT / 'extensions/knowledge/tests/Retrieval/cases.json').read_text())
        self.assertEqual((spec.owner, spec.mode, spec.tier, spec.requires_pg),
            ('P8.2', 'contract-scripted', 'contract-scripted', False))
        self.assertEqual(tuple(matrix['cases'].values()), gate.KNOWLEDGE_RETRIEVAL_UNIT_CASES)
        self.assertEqual(len(spec.cases), len(matrix['cases']) + 3)
        self.assertEqual(spec.command, (sys.executable, '-B', 'extensions/knowledge/tests/Retrieval/suite.py'))
        self.assertIn('fixtures/platform/v1/retrieval/evidence.json', spec.dependencies)
        report = {'mode':spec.mode, 'tier':spec.tier, 'cases':[{'id':name,'status':'passed'} for name in spec.cases],
            'input_hashes':gate.hashes(ROOT, spec.dependencies), 'evidence_kind':'host_contract_harness',
            'limitations':matrix['limitations'],
            'packaging':{'retrieval':True, 'test_autoload':False, 'sdk':False, 'framework':False, 'provider':False}}
        gate.validate_report(spec, report)
        for field in ('zero', 'missing', 'extra', 'skip', 'failed', 'unknown', 'tier', 'mode', 'hashes',
                      'stale', 'kind', 'limits', 'packaging', 'provider', 'test_autoload'):
            bad = copy.deepcopy(report)
            if field == 'zero': bad['cases'] = []
            elif field == 'missing': bad['cases'].pop()
            elif field == 'extra': bad['cases'] += bad['cases'][:1]
            elif field in ('skip', 'failed', 'unknown'): bad['cases'][0]['status'] = {'skip':'skipped'}.get(field,field)
            elif field == 'tier': bad['tier'] = 'live-provider'
            elif field == 'mode': bad['mode'] = 'unit'
            elif field == 'hashes': bad['input_hashes'] = {}
            elif field == 'stale': bad['input_hashes'][next(iter(bad['input_hashes']))] = '0' * 64
            elif field == 'kind': bad['evidence_kind'] = 'schema-only'
            elif field == 'limits': bad['limitations'] = []
            elif field == 'packaging': bad['packaging'] = {}
            else: bad['packaging'][field] = True
            with self.subTest(field=field), self.assertRaises(gate.GateError): gate.validate_report(spec, bad)
        with patch.object(gate, 'run_suite', return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(gate.parser().parse_args(['test','--suite','knowledge-retrieval'])), [{'ok':True}])
        run.assert_called_once_with(ROOT, spec, isolated=False)
        with tempfile.TemporaryDirectory(prefix='retrieval_manifest_negative_') as directory:
            root = Path(directory); fixture = root / 'fixture.json'; fixture.write_text('{}')
            tiny = replace(spec, cases=('one',), command=('runner',), dependencies=('fixture.json',))
            def runner(*args, **kwargs):
                value = dict(report, cases=[{'id':'one','status':'passed'}],
                    input_hashes=gate.hashes(root,tiny.dependencies))
                return subprocess.CompletedProcess([],0,json.dumps(value),'')
            with patch.object(gate,'ROOT',root), patch.object(gate.subprocess,'run',side_effect=runner):
                first = gate.run_suite(root,tiny)
                self.assertTrue(first['ok'])
                fixture.write_text('{"corrected":true}')
                changed = gate.run_suite(root,tiny)
                self.assertTrue(changed['ok'])
                self.assertNotEqual(first['suite_manifest_hash'],changed['suite_manifest_hash'])
                extra_case = gate.run_suite(root,replace(tiny,cases=('one','two')))
                self.assertFalse(extra_case['ok'])
                self.assertNotEqual(changed['suite_manifest_hash'],extra_case['suite_manifest_hash'])
                fixture.unlink()
                with self.assertRaises(gate.GateError): gate.run_suite(root,tiny)

    def test_sdk_adapter_registry(self):
        spec = gate.SUITES[('sdk-adapter', 'test')]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ('P4.2', 'contract-scripted', 'contract-scripted'))
        for alias in ['model-adapter', 'sdk-contract']:
            self.assertIs(spec, gate.SUITES[(alias, 'test')])
        self.assertEqual(len(spec.cases), 31)
        self.assertEqual(len(set(spec.cases)), 31)
        self.assertTrue({'SDK-001', 'V2ALL-SDK-POSITIVE', 'V2ALL-SDK-NEGATIVE', 'V2ALL-SDK-FAULT',
                         'adapter.curl-streaming', 'adapter.production-no-dev'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases]}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(spec, altered)
        gate.hashes(ROOT, spec.dependencies)
        args = gate.parser().parse_args(['test', '--suite', 'sdk-adapter'])
        result = {'ok': True}
        with patch.object(gate, 'run_suite', return_value=result) as run:
            self.assertEqual(gate.execute(args), [result])
        run.assert_called_once_with(ROOT, spec, isolated=False)

    def test_action_submit_registry(self):
        for partition in ('test', 'pg-test'):
            spec = gate.SUITES[('action-submit', partition)]
            self.assertEqual(spec.owner, 'P5.1')
            self.assertEqual(spec.requires_pg, partition == 'pg-test')
            self.assertEqual(len(spec.cases), 9 if spec.requires_pg else 15)
            self.assertIn('packages/agentools-postgres/migrations/0007_action_submissions.sql', spec.dependencies)
            # Migrator applies every SQL input, including later additive migrations.
            for migration in (ROOT / 'packages/agentools-postgres/migrations').glob('*.sql'):
                self.assertIn(str(migration.relative_to(ROOT)), spec.dependencies)
            gate.hashes(ROOT, spec.dependencies)
            report = {'mode': spec.mode, 'tier': spec.tier,
                      'cases': [{'id': name, 'status': 'passed'} for name in spec.cases]}
            if spec.requires_pg:
                report['isolation'] = {'engine': 'postgresql', 'isolated': True,
                    'database': 'agentools_test_registry', 'owner': 'test',
                    'allow_marker_verified': True, 'case_ids': list(spec.cases)}
            gate.validate_report(spec, report)
            for cases in ([], report['cases'][:-1], [dict(c, status='skipped') for c in report['cases']]):
                with self.assertRaises(gate.GateError):
                    gate.validate_report(spec, dict(report, cases=cases))
            argv = [partition, '--suite', 'action-submit'] + (['--isolated'] if spec.requires_pg else [])
            args = gate.parser().parse_args(argv)
            with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                self.assertEqual(gate.execute(args), [{'ok': True}])
            run.assert_called_once_with(ROOT, spec, isolated=spec.requires_pg)

    def test_confirmation_write_registry(self):
        for partition in ('test', 'pg-test'):
            spec = gate.SUITES[('confirmation-write', partition)]
            self.assertEqual(spec.owner, 'P5.2')
            self.assertEqual(spec.requires_pg, partition == 'pg-test')
            self.assertEqual(len(spec.cases), 12 if spec.requires_pg else 28)
            self.assertEqual(len(spec.cases), len(set(spec.cases)))
            self.assertIn('packages/agentools-postgres/migrations/0009_confirmation.sql', spec.dependencies)
            gate.hashes(ROOT, spec.dependencies)
            report = {'mode': spec.mode, 'tier': spec.tier,
                      'cases': [{'id': name, 'status': 'passed'} for name in spec.cases]}
            if spec.requires_pg:
                report['isolation'] = {'engine': 'postgresql', 'isolated': True,
                    'database': 'agentools_test_confirmation', 'owner': 'test',
                    'allow_marker_verified': True, 'case_ids': list(spec.cases)}
                self.assertTrue({'CNF-002', 'CNF-004', 'V2-P5-2-CONFIRM-POS',
                    'V2ALL-CONFIRM-POSITIVE', 'V2ALL-CONFIRM-NEGATIVE', 'V2ALL-CONFIRM-FAULT',
                    'confirmation.owner-commit-journal-failure', 'confirmation.owner-receipt-outage',
                    'confirmation.owner-reconciliation-guards'} <= set(spec.cases))
                for required in ('confirmation.owner-commit-journal-failure', 'confirmation.owner-receipt-outage',
                                 'confirmation.owner-reconciliation-guards'):
                    with self.assertRaises(gate.GateError):
                        gate.validate_report(spec, dict(report, cases=[c for c in report['cases'] if c['id'] != required]))
            else:
                self.assertTrue({'CNF-001', 'CNF-003'} <= set(spec.cases))
            gate.validate_report(spec, report)
            for cases in ([], report['cases'][:-1], [dict(c, status='skipped') for c in report['cases']]):
                with self.assertRaises(gate.GateError):
                    gate.validate_report(spec, dict(report, cases=cases))
            argv = [partition, '--suite', 'confirmation-write'] + (['--isolated'] if spec.requires_pg else [])
            with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)), [{'ok': True}])
            run.assert_called_once_with(ROOT, spec, isolated=spec.requires_pg)

    def test_runtime_registry(self):
        core = gate.SUITES[('runtime', 'test')]
        durable = gate.SUITES[('pg-runtime', 'pg-test')]
        self.assertEqual(core.owner, 'P4.3')
        self.assertEqual(durable.owner, 'P4.3')
        self.assertTrue(durable.requires_pg)
        self.assertIs(durable, gate.SUITES[('pg-models', 'pg-test')])
        self.assertIs(core, gate.SUITES[('durable-invocation', 'test')])
        self.assertIs(durable, gate.SUITES[('durable-invocation', 'pg-test')])
        for partition, spec, isolated in [('test', core, False), ('pg-test', durable, True)]:
            argv = [partition, '--suite', 'durable-invocation']
            if isolated:
                argv.append('--isolated')
            args = gate.parser().parse_args(argv)
            result = {'ok': True}
            with patch.object(gate, 'run_suite', return_value=result) as run:
                self.assertEqual(gate.execute(args), [result])
            run.assert_called_once_with(ROOT, spec, isolated=isolated)
        required = {'RUN-005', 'RUN-006', 'V2-P4-3-PROPOSAL-POS', 'V2-P4-3-PROPOSAL-NEG',
                    'V2-P4-3-PROVIDER-POS', 'V2-P4-3-PROVIDER-NEG', 'V2ALL-RUN-POSITIVE',
                    'V2ALL-RUN-NEGATIVE', 'V2ALL-RUN-FAULT'}
        self.assertTrue(required <= set(durable.cases))
        self.assertEqual(len(durable.cases), len(set(durable.cases)))
        for suite in [core, durable]:
            gate.hashes(ROOT, suite.dependencies)
            report = {'mode': suite.mode, 'tier': suite.tier,
                      'cases': [{'id': c, 'status': 'passed'} for c in suite.cases]}
            if suite.requires_pg:
                report['isolation'] = {'engine': 'postgresql', 'isolated': True,
                    'database': 'agentools_test_registry', 'owner': 'test',
                    'allow_marker_verified': True, 'case_ids': list(suite.cases)}
            self.assertEqual(gate.validate_report(suite, report)['count'], len(suite.cases))
            report['cases'].pop()
            with self.assertRaises(gate.GateError):
                gate.validate_report(suite, report)
        forbidden = '<?php namespace AgenToolsPlatform\\Core\\Budget; use AgenToolsPlatform\\Core\\Runtime\\InvocationPlan;'
        self.assertTrue(gate.source_errors(ROOT, forbidden, gate.CORE + '/src/Budget/Bad.php'))

    def test_read_journey_registry(self):
        spec = gate.SUITES[('read-journey', 'e2e')]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ('P4.4', 'local-host-e2e', 'local-host-e2e'))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 14)
        self.assertEqual(len(set(spec.cases)), 14)
        gate.hashes(ROOT, spec.dependencies)
        args = gate.parser().parse_args(['e2e', '--scenario', 'read-journey'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, spec, isolated=True)
        report = {'mode': spec.mode, 'tier': spec.tier,
                  'cases': [{'id': name, 'status': 'passed'} for name in spec.cases],
                  'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_read_registry',
                                'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        for changed in [dict(report, cases=report['cases'][:-1]), dict(report, isolation=None),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']])]:
            with self.assertRaises(gate.GateError):
                gate.validate_report(spec, changed)
        ignore = ROOT / 'packages/agentools-model-adapter/.gitignore'
        self.assertEqual(ignore.read_text(), '/vendor/\n')
        ignored = subprocess.run(['git', 'check-ignore', '-v', '--no-index',
                                  'packages/agentools-model-adapter/vendor/autoload.php'],
                                 cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(ignored.returncode, 0)
        self.assertTrue(ignored.stdout.startswith('packages/agentools-model-adapter/.gitignore:1:/vendor/'))
        core = gate.SUITES[('context-read', 'test')]
        self.assertEqual(len(core.cases), 7)
        self.assertEqual(core.owner, 'P4.4')

    def test_effect_reconcile_registry(self):
        portable = gate.SUITES[('effect-reconcile', 'test')]
        durable = gate.SUITES[('effect-reconcile', 'pg-test')]
        self.assertEqual((portable.owner, portable.tier, len(portable.cases)), ('P5.3', 'contract-scripted', 13))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P5.3', 'isolated-postgres', 15))
        self.assertTrue(durable.requires_pg)
        self.assertIn('ACT-002', durable.cases)
        self.assertIn('effect.sigkill-after-owner-commit', durable.cases)
        self.assertIn('effect.competing-recovery', durable.cases)
        gate.hashes(ROOT, durable.dependencies)
        report = {'mode': durable.mode, 'tier': durable.tier,
                  'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                  'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_effects',
                                'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}}
        gate.validate_report(durable, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, isolation=None),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(durable, altered)
        with self.assertRaisesRegex(gate.GateError, '--isolated'): gate.run_suite(ROOT, durable)
        args = gate.parser().parse_args(['pg-test', '--suite', 'effect-reconcile', '--isolated'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, durable, isolated=True)

    def test_context_guards_registry(self):
        import copy
        spec=gate.SUITES[('context-security','test')]
        self.assertIs(spec,gate.SUITES[('context-guards','test')])
        self.assertEqual((spec.owner,len(spec.cases)),('P7.6',21))
        report={'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases],
            'portable':[{'mode':'contract-scripted','tier':'contract-scripted','cases':[{'id':c,'status':'passed'} for c in group]}
                for group in (gate.SECURITY_GUARD_CASES,gate.SECURITY_DISCLOSURE_CASES,gate.SECURITY_WORKER_CASES,gate.SECURITY_EXAMPLE_CASES)],
            'packaging':{'standalone_core':True,'context_guards':True,'testing_autoload':False,'sdk_autoload':False,'postgres_autoload':False,'framework_autoload':False},
            'observations':{'CM-043':{'early_content_bytes':0,'checks':1,'full_result_check':True,'generated_bytes':25,'delivered_before_receipt':0,'delivered_after_receipt':25,'heard_bytes':None},
                'worker_isolation':{'jobs':2,'resource_kinds':7,'alpha_refs_in_beta':0,'finally_reset':True},
                'injection_surfaces':{'surfaces':['history','retrieval','tool','summary','memory','skill','input'],'unexpected_tools':0,'policy_disabled':False},
                'entry_parity':{'entries':['text','raw','build','stream','voice','background','scheduled'],'cached_grants':0},
                'unknown_delivery':{'owner_calls':1,'delivered_bytes':None,'resend':False},
                'redaction':{'nested_values_redacted':True,'exception_chain_redacted':True,'diagnostic_calls':1,'encrypted':True,'ttl':True,'audit':True}},
            'input_hashes':gate.hashes(ROOT,spec.dependencies)}
        self.assertEqual(gate.validate_report(spec,report)['count'],21)
        for fault in ('case','skip','portable','hash','fake','early','hearing','worker','surface','entry','unknown','logs'):
            bad=copy.deepcopy(report)
            if fault=='case':bad['cases'].pop()
            elif fault=='skip':bad['cases'][0]['status']='skipped'
            elif fault=='portable':bad['portable'][0]['cases']=[]
            elif fault=='hash':bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
            elif fault=='fake':bad['packaging']['testing_autoload']=True
            elif fault=='early':bad['observations']['CM-043']['early_content_bytes']=1
            elif fault=='hearing':bad['observations']['CM-043']['heard_bytes']=25
            elif fault=='worker':bad['observations']['worker_isolation']['alpha_refs_in_beta']=1
            elif fault=='surface':bad['observations']['injection_surfaces']['surfaces'].pop()
            elif fault=='entry':bad['observations']['entry_parity']['cached_grants']=1
            elif fault=='unknown':bad['observations']['unknown_delivery']['delivered_bytes']=0
            else:bad['observations']['redaction']['exception_chain_redacted']=False
            with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        for name in ('context-security','context-guards'):
            args=gate.parser().parse_args(['test','--suite',name])
            with patch.object(gate,'run_suite',return_value={'ok':True}) as run:self.assertEqual(gate.execute(args),[{'ok':True}])
            run.assert_called_once_with(ROOT,spec,isolated=False)

    def test_history_memory_registry(self):
        import copy
        core=gate.SUITES[('history-memory','test')];pg=gate.SUITES[('history-memory','pg-test')]
        self.assertEqual((core.owner,len(core.cases),pg.owner,len(pg.cases)),('P7.5',18,'P7.5-pg',6))
        def report(spec):return {'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases]}
        pure=report(core);pure['portable']=[report(gate.Suite('portable','contract-scripted','contract-scripted',group,(),()))
            for group in (gate.HISTORY_CORE_CASES,gate.MEMORY_CORE_CASES,gate.HISTORY_EXAMPLE_CASES)]
        pure['packaging']={'standalone_core':True,'history_memory':True,'testing_autoload':False,'sdk_autoload':False,'postgres_autoload':False,'framework_autoload':False}
        pure['input_hashes']=gate.hashes(ROOT,core.dependencies)
        durable=report(pg)
        def isolation(group):return {'engine':'postgresql','isolated':True,'database':'agentools_test_summary_registry','owner':'test','allow_marker_verified':True,'case_ids':list(group)}
        durable['isolation']=isolation(pg.cases)
        durable['portable']=[]
        for group in (gate.SUMMARY_DURABLE_CASES,gate.SUMMARY_CONCURRENT_CASES,gate.SUMMARY_RECOVERY_CASES):
            unit=report(gate.Suite('portable','isolated-postgres','isolated-postgres',group,(),()));unit['isolation']=isolation(group);durable['portable'].append(unit)
        durable['observations']={'competing_connections':{'backends':[101,102],'exits':[0,0],'outcomes':['appended','integrity_conflict'],'versions':1},
            'crash_restart':{'before_commit':{'signaled':True,'signal':9,'exit':-1},'after_commit':{'signaled':True,'signal':9,'exit':-1},'rolled_back':True,'replay_versions':1,'same_hash':True},
            'source_cascade':{'invalidated':4,'historical_markers':1,'future_use_denied':True}}
        durable['input_hashes']=gate.hashes(ROOT,pg.dependencies)
        self.assertEqual(gate.validate_report(core,pure)['count'],18);self.assertEqual(gate.validate_report(pg,durable)['count'],6)
        for spec,original in ((core,pure),(pg,durable)):
            for fault in ('case','skip','portable','hash','boundary'):
                bad=copy.deepcopy(original)
                if fault=='case':bad['cases'].pop()
                elif fault=='skip':bad['cases'][0]['status']='skipped'
                elif fault=='portable':bad['portable'][0]['cases']=[]
                elif fault=='hash':bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
                elif spec==core:bad['packaging']['testing_autoload']=True
                else:bad['observations']['crash_restart']['after_commit']['signal']=0
                with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        for fault in ('isolation','connection','cascade','rollback'):
            bad=copy.deepcopy(durable)
            if fault=='isolation':bad['isolation']['allow_marker_verified']=False
            elif fault=='connection':bad['observations']['competing_connections']['backends']=[1,1]
            elif fault=='cascade':bad['observations']['source_cascade']['invalidated']=1
            else:bad['observations']['crash_restart']['rolled_back']=False
            with self.assertRaises(gate.GateError):gate.validate_report(pg,bad)
        args=gate.parser().parse_args(['test','--suite','history-memory'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,core,isolated=False)
        with self.assertRaisesRegex(gate.GateError,'--isolated'):gate.run_suite(ROOT,pg)

    def test_context_compiler_registry(self):
        import copy
        spec=gate.SUITES[('context-compiler','test')]
        self.assertEqual((spec.owner,spec.mode,spec.tier,len(spec.cases)),('P7.4','contract-scripted','contract-scripted',28))
        portable=[]
        for group in (gate.CONTEXT_CORE_CASES,gate.CONTEXT_LIFECYCLE_CASES,gate.CONTEXT_EXAMPLE_CASES,gate.CONTEXT_READ_CASES):
            tier='unit' if group==gate.CONTEXT_READ_CASES else 'contract-scripted'
            portable.append({'mode':tier,'tier':tier,'cases':[{'id':c,'status':'passed'} for c in group]})
        postgres={'mode':'isolated-postgres','tier':'isolated-postgres','cases':[{'id':c,'status':'passed'} for c in gate.CONTEXT_PG_CASES],
            'isolation':{'engine':'postgresql','isolated':True,'database':'agentools_test_context_registry','owner':'test',
                'allow_marker_verified':True,'case_ids':list(gate.CONTEXT_PG_CASES)}}
        report={'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases],
            'portable':portable,'postgres':postgres,
            'packaging':{'standalone_core':True,'context':True,'testing_autoload':False,'sdk_autoload':False,'postgres_autoload':False,'framework_autoload':False},
            'input_hashes':gate.hashes(ROOT,spec.dependencies)}
        self.assertEqual(gate.validate_report(spec,report)['count'],28)
        for fault in ('case','skip','portable','postgres','isolation','fake','hash'):
            bad=copy.deepcopy(report)
            if fault=='case':bad['cases'].pop()
            elif fault=='skip':bad['cases'][0]['status']='skipped'
            elif fault=='portable':bad['portable'][0]['cases']=[]
            elif fault=='postgres':bad['postgres']['cases'].pop()
            elif fault=='isolation':bad['postgres']['isolation']['allow_marker_verified']=False
            elif fault=='fake':bad['packaging']['testing_autoload']=True
            else:bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
            with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        args=gate.parser().parse_args(['test','--suite','context-compiler'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,spec,isolated=False)
        with self.assertRaisesRegex(gate.GateError,'--isolated'):
            gate.run_suite(ROOT,gate.SUITES[('context-compiler','pg-test')])

    def test_skill_selection_registry(self):
        import copy
        spec=gate.SUITES[('skill-selection','test')]
        self.assertEqual((spec.owner,spec.mode,spec.tier,len(spec.cases)),('P7.3','contract-scripted','contract-scripted',21))
        report={'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases],
            'portable':[{'mode':'contract-scripted','tier':'contract-scripted','cases':[{'id':c,'status':'passed'} for c in group]}
                for group in (gate.SKILL_CORE_CASES,gate.SKILL_LIFECYCLE_CASES,gate.SKILL_EXAMPLE_CASES)],
            'packaging':{'standalone_core':True,'skills':True,'testing_autoload':False,'sdk_autoload':False,'postgres_autoload':False,'framework_autoload':False},
            'observations':{'CM-007':{'registered':100,'shortlist':1,'selected':1,'fragment_loads':1,'transport_calls':1,'tools':['search'],'qualification_calls_during_route':0},
                'CM-008':{'code':'HASH_MISMATCH','loads':1,'loaded_versions':['1'],'latest_substituted':False},
                'V2ALL-SKILL-FAULT':{'revoked_loads':0,'audit_marker':'revoked','snapshot_unchanged':True},
                'V2ALL-SKILL-NEGATIVE':{'rejected_manifest_fields':['secret','history','corpus','curriculum','tool_catalogue'],'forbidden_fragment_kinds':5,'qualified_publications':0}},
            'input_hashes':gate.hashes(ROOT,spec.dependencies)}
        self.assertEqual(gate.validate_report(spec,report)['count'],21)
        for fault in ('case','skip','portable','all-fragments','judge','latest','revoke','payload','fake','hash'):
            bad=copy.deepcopy(report)
            if fault=='case':bad['cases'].pop()
            elif fault=='skip':bad['cases'][0]['status']='skipped'
            elif fault=='portable':bad['portable'][0]['cases']=[]
            elif fault=='all-fragments':bad['observations']['CM-007']['fragment_loads']=100
            elif fault=='judge':bad['observations']['CM-007']['qualification_calls_during_route']=1
            elif fault=='latest':bad['observations']['CM-008']['latest_substituted']=True
            elif fault=='revoke':bad['observations']['V2ALL-SKILL-FAULT']['audit_marker']='published'
            elif fault=='payload':bad['observations']['V2ALL-SKILL-NEGATIVE']['forbidden_fragment_kinds']=4
            elif fault=='fake':bad['packaging']['testing_autoload']=True
            else:bad['input_hashes'][next(iter(bad['input_hashes']))]='0'*64
            with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        args=gate.parser().parse_args(['test','--suite','skill-selection'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,spec,isolated=False)

    def test_profile_merge_registry(self):
        import copy
        spec = gate.SUITES[('profile-merge','test')]
        self.assertEqual((spec.owner,spec.mode,spec.tier,len(spec.cases)), ('P7.2','contract-scripted','contract-scripted',24))
        report = {'mode':spec.mode,'tier':spec.tier,'cases':[{'id':c,'status':'passed'} for c in spec.cases],
            'portable':[{'mode':'contract-scripted','tier':'contract-scripted','cases':[{'id':c,'status':'passed'} for c in group]}
                for group in (gate.PROFILE_MERGE_CORE_CASES,gate.PROFILE_MERGE_LIVE_CASES)],
            'packaging':{'composition':True,'testing_autoload':False},
            'postgres':{'mode':'isolated-postgres','tier':'isolated-postgres','cases':[{'id':c,'status':'passed'} for c in gate.PROFILE_MERGE_PG_CASES],
                'isolation':{'engine':'postgresql','isolated':True,'database':'agentools_test_merge_registry','owner':'test',
                    'allow_marker_verified':True,'case_ids':list(gate.PROFILE_MERGE_PG_CASES)},
                'input_hashes':gate.hashes(ROOT,spec.dependencies)}}
        self.assertEqual(gate.validate_report(spec,report)['count'],24)
        for fault in ('case','skip','pg-case','pg-proof','pg-tier','portable','hash','fake'):
            bad=copy.deepcopy(report)
            if fault=='case':bad['cases'].pop()
            elif fault=='skip':bad['cases'][0]['status']='skipped'
            elif fault=='pg-case':bad['postgres']['cases'].pop()
            elif fault=='pg-proof':bad['postgres']['isolation']['allow_marker_verified']=False
            elif fault=='pg-tier':bad['postgres']['tier']='contract-scripted'
            elif fault=='portable':bad['portable'][1]['cases']=[]
            elif fault=='hash':bad['postgres']['input_hashes'][next(iter(bad['postgres']['input_hashes']))]='0'*64
            else:bad['packaging']['testing_autoload']=True
            with self.assertRaises(gate.GateError):gate.validate_report(spec,bad)
        args=gate.parser().parse_args(['test','--suite','profile-merge'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,spec,isolated=False)

    def test_profile_publish_registry(self):
        import copy
        contract = gate.SUITES[('profile-publish', 'test')]
        durable = gate.SUITES[('profile-publish', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P7.1', 'contract-scripted', 15))
        self.assertEqual((durable.owner, durable.requires_pg, len(durable.cases)), ('P7.1', True, 18))
        def base(spec):
            return {'mode': spec.mode, 'tier': spec.tier, 'cases': [{'id': c, 'status': 'passed'} for c in spec.cases]}
        report = base(durable)
        report['isolation'] = {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_profiles_registry',
            'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}
        observations = report['observations'] = {}
        for name in ('profiles.concurrent-publish', 'profiles.concurrent-pin'):
            observations[name] = {'workers':2, 'exits':[0,0], 'competed':True, 'rows':1, 'backends':[101,102], 'same_pin':True}
        for name, rows in (('profiles.publish-sigkill-recovery',1), ('profiles.pin-sigkill-recovery',1), ('profiles.migration-sigkill-recovery',2)):
            observations[name] = {'signal':9,'signaled':True,'worker_exit':-1,'committed':'committed','same_hash':True,'rows':rows}
        observations['profiles.migration-commit-rollback'] = {'history_rolled_back':True,'head_rolled_back':True,'receipt_rolled_back':True,'retry_revision':2}
        report['input_hashes'] = gate.hashes(ROOT, durable.dependencies)
        self.assertEqual(gate.validate_report(durable, report)['count'], 18)
        for fault in ('case', 'skip', 'isolation', 'contention', 'backend', 'crash', 'rollback', 'hash', 'malformed'):
            bad = copy.deepcopy(report)
            if fault == 'case': bad['cases'].pop()
            elif fault == 'skip': bad['cases'][0]['status'] = 'skipped'
            elif fault == 'isolation': bad['isolation'] = None
            elif fault == 'contention': bad['observations']['profiles.concurrent-pin']['competed'] = False
            elif fault == 'backend': bad['observations']['profiles.concurrent-pin']['backends'] = [101,101]
            elif fault == 'crash': bad['observations']['profiles.publish-sigkill-recovery']['signal'] = 0
            elif fault == 'rollback': bad['observations']['profiles.migration-commit-rollback']['receipt_rolled_back'] = False
            elif fault == 'hash': bad['input_hashes'][next(iter(bad['input_hashes']))] = '0'*64
            else: bad['observations'] = None
            with self.assertRaises(gate.GateError): gate.validate_report(durable, bad)
        report = base(contract)
        report.update(packaging={'profile_publisher':True,'profile_pins':True,'testing_autoload':False}, portable=[
            {'mode':'unit','tier':'unit','cases':[{'id':c,'status':'passed'} for c in cases]}
            for cases in (gate.PROFILE_UNIT_CASES, gate.PROFILE_EXAMPLE_CASES)])
        self.assertEqual(gate.validate_report(contract, report)['count'], 15)
        bad = copy.deepcopy(report); bad['packaging']['testing_autoload'] = True
        with self.assertRaises(gate.GateError): gate.validate_report(contract, bad)
        bad = copy.deepcopy(report); bad['portable'][0]['cases'].pop()
        with self.assertRaises(gate.GateError): gate.validate_report(contract, bad)
        bad = copy.deepcopy(report); bad['packaging'] = None
        with self.assertRaises(gate.GateError): gate.validate_report(contract, bad)
        with self.assertRaises(gate.GateError): gate.run_suite(ROOT, durable)
        for partition, spec in [('test', contract), ('pg-test', durable)]:
            args = gate.parser().parse_args([partition,'--suite','profile-publish'] + (['--isolated'] if spec.requires_pg else []))
            with patch.object(gate, 'run_suite', return_value={'ok':True}) as run:
                self.assertEqual(gate.execute(args), [{'ok': True}])
            run.assert_called_once_with(ROOT, spec, isolated=spec.requires_pg)

    def test_scheduler_registry(self):
        import copy
        port_path = gate.CORE + '/src/Scheduling/SchedulePort.php'
        port_source = (ROOT / port_path).read_text()
        self.assertEqual(gate.source_errors(ROOT, port_source, port_path), [])
        self.assertEqual(gate.MODULES['Scheduling'], {'Common', 'Security', 'Context'})
        for target in ('Runtime', 'Actions', 'Budget', 'Models', 'Profiles'):
            source = '<?php namespace AgenToolsPlatform\\Core\\Scheduling; use AgenToolsPlatform\\Core\\' + target + '\\Forbidden;'
            with self.subTest(dependency=target):
                self.assertTrue(any('Scheduling cannot depend on ' + target in error
                    for error in gate.source_errors(ROOT, source, port_path)))
        for dependency in ('PDO', 'Illuminate\\Container\\Container', 'AgenToolsPlatform\\Scheduler\\Store'):
            source = '<?php namespace AgenToolsPlatform\\Core\\Scheduling; use ' + dependency + ';'
            with self.subTest(dependency=dependency):
                self.assertTrue(gate.source_errors(ROOT, source, port_path))
        source = '<?php namespace AgenToolsPlatform\\Core\\Scheduling; file_get_contents("/secret");'
        self.assertTrue(any('unapproved function' in error for error in gate.source_errors(ROOT, source, port_path)))
        source = '<?php namespace AgenToolsPlatform\\Core\\SchedulingUnknown; interface Port {}'
        self.assertTrue(any('unknown core module' in error for error in gate.source_errors(ROOT, source, port_path)))
        self.assertTrue(any('namespace/path module mismatch' in error for error in
            gate.source_errors(ROOT, port_source, gate.CORE + '/src/Runtime/SchedulePort.php')))
        source = '<?php namespace AgenToolsPlatform\\Core\\Runtime; use AgenToolsPlatform\\Core\\Scheduling\\SchedulePort;'
        self.assertTrue(any('Runtime cannot depend on Scheduling' in error
            for error in gate.source_errors(ROOT, source, gate.CORE + '/src/Runtime/Example.php')))
        contract = gate.SUITES[('scheduler', 'test')]
        durable = gate.SUITES[('scheduler', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P6.5', 'contract-scripted', 21))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P6.5', 'isolated-postgres', 13))
        self.assertTrue(durable.requires_pg)
        matrix = json.loads((ROOT / 'fixtures/platform/v1/scheduler/cases.json').read_text())
        self.assertEqual(tuple(matrix['postgres'].values()), gate.SCHEDULER_PG_CASES)
        self.assertEqual(tuple(matrix['calendar'].values()), gate.SCHEDULER_CALENDAR_CASES)
        gate.hashes(ROOT, contract.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier,
            'cases': [{'id': name, 'status': 'passed'} for name in contract.cases],
            'packaging': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                'testing_autoload': False, 'core_port': True, 'scheduler_absent_headless': True,
                'adapter': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                    'testing_autoload': False, 'scheduler': True, 'filament': False, 'core_port': True}},
            'calendar': {'mode': 'unit', 'tier': 'unit',
                'cases': [{'id': name, 'status': 'passed'} for name in gate.SCHEDULER_CALENDAR_CASES]},
            'postgres': {'mode': durable.mode, 'tier': durable.tier,
                'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_scheduler_registry',
                    'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)},
                'observations': {
                    'CM-045': {'workers': 2, 'exits': [0, 0], 'same_receipts': True, 'counts': 1},
                    'scheduler.crash-after-commit': {'signal': 9, 'signaled': True, 'worker_exit': -1, 'same_refs': True, 'counts': 1},
                    'V2ALL-SCHED-FAULT': {'same_run': True, 'owner_outcome': 'unknown', 'status': 'reconciliation_required',
                        'provider_calls': 1, 'owner_writes': 0}}}}
        gate.validate_report(contract, report)
        for field in ('proof', 'missing', 'skip', 'zero', 'calendar', 'calendar-tier', 'headless', 'adapter', 'routes',
                'core-port', 'pg-tier', 'live-tier', 'concurrent', 'kill', 'unknown'):
            bad = copy.deepcopy(report)
            if field == 'proof': bad['postgres']['isolation'] = None
            elif field == 'missing': bad['postgres']['cases'].pop()
            elif field == 'skip': bad['postgres']['cases'][0]['status'] = 'skipped'
            elif field == 'zero': bad['cases'] = []
            elif field == 'calendar': bad['calendar']['cases'] = []
            elif field == 'calendar-tier': bad['calendar']['tier'] = 'isolated-postgres'
            elif field == 'headless': bad['packaging']['scheduler_absent_headless'] = False
            elif field == 'adapter': bad['packaging']['adapter']['testing_autoload'] = True
            elif field == 'routes': bad['packaging']['adapter']['default_routes'] = 1
            elif field == 'core-port': bad['packaging']['core_port'] = False
            elif field == 'pg-tier': bad['postgres']['tier'] = 'contract-scripted'
            elif field == 'live-tier': bad['tier'] = 'live-provider'
            elif field == 'concurrent': bad['postgres']['observations']['CM-045']['exits'] = [0, 1]
            elif field == 'kill': bad['postgres']['observations']['scheduler.crash-after-commit']['signal'] = 15
            else: bad['postgres']['observations']['V2ALL-SCHED-FAULT']['owner_outcome'] = 'succeeded'
            with self.subTest(field=field), self.assertRaises(gate.GateError):
                gate.validate_report(contract, bad)
        for partition in ('test', 'pg-test'):
            argv = [partition, '--suite', 'scheduler'] + (['--isolated'] if partition == 'pg-test' else [])
            with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)), [{'ok': True}])
            run.assert_called_once_with(ROOT, gate.SUITES[('scheduler', partition)], isolated=partition == 'pg-test')
        # Own source/fixture and required-case/tier additions must invalidate candidate-bound manifests.
        with tempfile.TemporaryDirectory(prefix='scheduler_manifest_negative_') as directory:
            root = Path(directory); fixture = root / 'fixture.json'; fixture.write_text('{}')
            tiny = gate.Suite('P6.5', 'unit', 'unit', ('one',), ('runner',), ('fixture.json',))
            output = json.dumps({'mode': 'unit', 'tier': 'unit', 'cases': [{'id': 'one', 'status': 'passed'}]})
            with patch.object(gate.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, output, '')):
                first = gate.run_suite(root, tiny)['suite_manifest_hash']
                fixture.write_text('{"changed":true}')
                self.assertNotEqual(first, gate.run_suite(root, tiny)['suite_manifest_hash'])
                fixture.write_text('{}'); (root / 'new.php').write_text('<?php')
                for changed in (replace(tiny, cases=('one', 'two')), replace(tiny, tier='contract-scripted'),
                        replace(tiny, dependencies=('fixture.json', 'new.php'))):
                    self.assertNotEqual(first, gate.run_suite(root, changed)['suite_manifest_hash'])
                fixture.unlink()
                with self.assertRaises(gate.GateError): gate.run_suite(root, tiny)

    def test_profile_admin_registry(self):
        import copy
        contract = gate.SUITES[('profile-publish-admin', 'test')]
        durable = gate.SUITES[('profile-publish-admin', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P7.7', 'contract-scripted', 18))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P7.7-pg', 'isolated-postgres', 13))
        self.assertTrue(durable.requires_pg)
        matrix = json.loads((ROOT / 'extensions/filament/tests/profile-cases.json').read_text())
        self.assertEqual(tuple(matrix['postgres'].values()), gate.PROFILE_ADMIN_PG_CASES)
        self.assertEqual(tuple(matrix['shell'].values()), gate.PROFILE_ADMIN_SHELL_CASES)
        report = {'mode': contract.mode, 'tier': contract.tier,
            'cases': [{'id': name, 'status': 'passed'} for name in contract.cases],
            'input_hashes': gate.hashes(ROOT, contract.dependencies),
            'packaging': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                'testing_autoload': False, 'filament_absent_headless': True,
                'adapter': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                    'testing_autoload': False, 'filament': True, 'profile_admin': True}},
            'shell': {'mode': 'contract-scripted', 'tier': 'contract-scripted',
                'cases': [{'id': name, 'status': 'passed'} for name in gate.PROFILE_ADMIN_SHELL_CASES]},
            'postgres': {'mode': durable.mode, 'tier': durable.tier,
                'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_profile_admin_registry',
                    'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}}}
        gate.validate_report(contract, report)
        for field in ('proof', 'missing', 'skip', 'zero', 'shell', 'headless', 'adapter', 'routes', 'tier', 'hashes'):
            bad = copy.deepcopy(report)
            if field == 'proof': bad['postgres']['isolation'] = None
            elif field == 'missing': bad['postgres']['cases'].pop()
            elif field == 'skip': bad['postgres']['cases'][0]['status'] = 'skipped'
            elif field == 'zero': bad['cases'] = []
            elif field == 'shell': bad['shell']['cases'] = []
            elif field == 'headless': bad['packaging']['filament_absent_headless'] = False
            elif field == 'adapter': bad['packaging']['adapter']['profile_admin'] = False
            elif field == 'routes': bad['packaging']['adapter']['default_routes'] = 1
            elif field == 'tier': bad['tier'] = 'live-provider'
            else: bad['input_hashes'] = {}
            with self.subTest(field=field), self.assertRaises(gate.GateError): gate.validate_report(contract, bad)
        for partition in ('test', 'pg-test'):
            argv = [partition, '--suite', 'profile-publish-admin'] + (['--isolated'] if partition == 'pg-test' else [])
            with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)), [{'ok': True}])
            run.assert_called_once_with(ROOT, gate.SUITES[('profile-publish-admin', partition)], isolated=partition == 'pg-test')
        with tempfile.TemporaryDirectory(prefix='profile_admin_manifest_negative_') as directory:
            root = Path(directory)
            fixture = root / 'fixture.json'; fixture.write_text('{}')
            tiny = gate.Suite('P7.7', 'unit', 'unit', ('one',), ('runner',), ('fixture.json',))
            output = json.dumps({'mode': 'unit', 'tier': 'unit', 'cases': [{'id': 'one', 'status': 'passed'}]})
            with patch.object(gate.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, output, '')):
                first = gate.run_suite(root, tiny)['suite_manifest_hash']
                fixture.write_text('{"source":2}')
                self.assertNotEqual(first, gate.run_suite(root, tiny)['suite_manifest_hash'])
                fixture.write_text('{}')
                (root / 'new.php').write_text('<?php')
                for changed in (replace(tiny, cases=('one', 'two')), replace(tiny, tier='contract-scripted'),
                        replace(tiny, dependencies=('fixture.json', 'new.php'))):
                    self.assertNotEqual(first, gate.run_suite(root, changed)['suite_manifest_hash'])
                fixture.unlink()
                with self.assertRaises(gate.GateError): gate.run_suite(root, tiny)

    def test_filament_registry(self):
        import copy
        contract = gate.SUITES[('filament', 'test')]
        durable = gate.SUITES[('filament', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P6.4', 'contract-scripted', 15))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P6.4', 'isolated-postgres', 10))
        self.assertTrue(durable.requires_pg)
        matrix = json.loads((ROOT / 'extensions/filament/tests/cases.json').read_text())
        self.assertEqual(tuple(matrix['postgres'].values()), gate.FILAMENT_PG_CASES)
        self.assertEqual(tuple(matrix['shell'].values()), gate.FILAMENT_SHELL_CASES)
        gate.hashes(ROOT, contract.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier,
            'cases': [{'id': name, 'status': 'passed'} for name in contract.cases],
            'packaging': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                'testing_autoload': False, 'filament_absent_headless': True,
                'adapter': {'provider': True, 'default_routes': 0, 'default_host_bindings': False,
                    'testing_autoload': False, 'filament': True}},
            'shell': {'mode': 'contract-scripted', 'tier': 'contract-scripted',
                'cases': [{'id': name, 'status': 'passed'} for name in gate.FILAMENT_SHELL_CASES]},
            'postgres': {'mode': durable.mode, 'tier': durable.tier,
                'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_filament_registry',
                    'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}}}
        gate.validate_report(contract, report)
        for field in ('proof', 'missing', 'skip', 'zero', 'shell', 'shell-tier', 'headless', 'adapter', 'routes', 'pg-tier', 'live-tier'):
            bad = copy.deepcopy(report)
            if field == 'proof': bad['postgres']['isolation'] = None
            elif field == 'missing': bad['postgres']['cases'].pop()
            elif field == 'skip': bad['postgres']['cases'][0]['status'] = 'skipped'
            elif field == 'zero': bad['cases'] = []
            elif field == 'shell': bad['shell']['cases'] = []
            elif field == 'shell-tier': bad['shell']['tier'] = 'live-provider'
            elif field == 'headless': bad['packaging']['filament_absent_headless'] = False
            elif field == 'adapter': bad['packaging']['adapter']['testing_autoload'] = True
            elif field == 'routes': bad['packaging']['adapter']['default_routes'] = 1
            elif field == 'pg-tier': bad['postgres']['tier'] = 'contract-scripted'
            else: bad['tier'] = 'live-provider'
            with self.subTest(field=field), self.assertRaises(gate.GateError):
                gate.validate_report(contract, bad)
        for partition in ('test', 'pg-test'):
            argv = [partition, '--suite', 'filament'] + (['--isolated'] if partition == 'pg-test' else [])
            with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                self.assertEqual(gate.execute(gate.parser().parse_args(argv)), [{'ok': True}])
            run.assert_called_once_with(ROOT, gate.SUITES[('filament', partition)], isolated=partition == 'pg-test')
        # Required view/fixture deletion is red, and source/case/tier additions alter the manifest hash.
        with tempfile.TemporaryDirectory(prefix='filament_manifest_negative_') as directory:
            root = Path(directory)
            fixture = root / 'fixture.json'; fixture.write_text('{}')
            tiny = gate.Suite('P6.4', 'unit', 'unit', ('one',), ('runner',), ('fixture.json',))
            output = json.dumps({'mode': 'unit', 'tier': 'unit', 'cases': [{'id': 'one', 'status': 'passed'}]})
            with patch.object(gate.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, output, '')):
                first = gate.run_suite(root, tiny)['suite_manifest_hash']
                fixture.write_text('{"source":2}')
                self.assertNotEqual(first, gate.run_suite(root, tiny)['suite_manifest_hash'])
                fixture.write_text('{}')
                extra = root / 'new.php'; extra.write_text('<?php')
                for changed in (replace(tiny, cases=('one', 'two')), replace(tiny, tier='contract-scripted'),
                        replace(tiny, dependencies=('fixture.json', 'new.php'))):
                    self.assertNotEqual(first, gate.run_suite(root, changed)['suite_manifest_hash'])
                fixture.unlink()
                with self.assertRaises(gate.GateError): gate.run_suite(root, tiny)

    def test_composition_testing_registry(self):
        import copy
        contract = gate.SUITES[('composition-testing', 'test')]
        durable = gate.SUITES[('composition-testing', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P6.3', 'contract-scripted', 19))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P6.3', 'isolated-postgres', 8))
        self.assertTrue(durable.requires_pg)
        for case in ('V2ALL-FAKE-POSITIVE', 'V2ALL-FAKE-NEGATIVE', 'V2ALL-FAKE-FAULT'):
            self.assertIn(case, durable.cases)
        gate.hashes(ROOT, contract.dependencies)
        report = {'mode': contract.mode, 'tier': contract.tier,
            'cases': [{'id': name, 'status': 'passed'} for name in contract.cases],
            'packaging': {'provider': True, 'default_routes': 0, 'default_host_bindings': False, 'testing_autoload': False},
            'portable': {'mode': 'contract-scripted', 'tier': 'contract-scripted',
                'cases': [{'id': name, 'status': 'passed'} for name in gate.COMPOSITION_PORT_CASES]},
            'postgres': {'mode': durable.mode, 'tier': durable.tier,
                'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_profile_registry',
                    'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}}}
        gate.validate_report(contract, report)
        for field in ('proof', 'missing', 'skip', 'zero', 'packaging', 'portable', 'portable-tier', 'pg-tier', 'live-tier'):
            bad = copy.deepcopy(report)
            if field == 'proof': bad['postgres']['isolation'] = None
            elif field == 'missing': bad['postgres']['cases'].pop()
            elif field == 'skip': bad['postgres']['cases'][0]['status'] = 'skipped'
            elif field == 'zero': bad['cases'] = []
            elif field == 'packaging': bad['packaging']['testing_autoload'] = True
            elif field == 'portable': bad['portable']['cases'] = []
            elif field == 'portable-tier': bad['portable']['tier'] = 'isolated-postgres'
            elif field == 'pg-tier': bad['postgres']['tier'] = 'contract-scripted'
            else: bad['tier'] = 'live-provider'
            with self.subTest(field=field), self.assertRaises(gate.GateError):
                gate.validate_report(contract, bad)
        args = gate.parser().parse_args(['test', '--suite', 'composition-testing'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, contract, isolated=False)
        args = gate.parser().parse_args(['pg-test', '--suite', 'composition-testing', '--isolated'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, durable, isolated=True)

    def test_platform_definitions_registry(self):
        import copy
        contract=gate.SUITES[('platform-definitions','test')]
        durable=gate.SUITES[('platform-definitions','pg-test')]
        self.assertEqual((contract.owner,contract.tier,len(contract.cases)),('P6.2','contract-scripted',21))
        self.assertEqual((durable.owner,durable.tier,len(durable.cases)),('P6.2','isolated-postgres',17))
        self.assertTrue(durable.requires_pg)
        gate.hashes(ROOT,contract.dependencies)
        report={'mode':contract.mode,'tier':contract.tier,
            'cases':[{'id':name,'status':'passed'} for name in contract.cases],
            'packaging':{'provider':True,'default_routes':0,'default_host_bindings':False,'testing_autoload':False},
            'postgres':{'mode':durable.mode,'tier':durable.tier,
                'cases':[{'id':name,'status':'passed'} for name in durable.cases],
                'isolation':{'engine':'postgresql','isolated':True,'database':'agentools_test_definitions_registry',
                    'owner':'test','allow_marker_verified':True,'case_ids':list(durable.cases)}}}
        gate.validate_report(contract,report)
        for field in ('missing','skip','proof','packaging','zero'):
            bad=copy.deepcopy(report)
            if field=='missing':bad['postgres']['cases'].pop()
            elif field=='skip':bad['postgres']['cases'][0]['status']='skipped'
            elif field=='proof':bad['postgres']['isolation']=None
            elif field=='packaging':bad['packaging']['testing_autoload']=True
            else:bad['cases']=[]
            with self.assertRaises(gate.GateError):gate.validate_report(contract,bad)
        args=gate.parser().parse_args(['test','--suite','platform-definitions'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,contract,isolated=False)
        from importlib.util import spec_from_file_location,module_from_spec
        spec=spec_from_file_location('definitions_attribution',ROOT/'packages/agentools-laravel/tests/Definitions/attribution.py')
        attribution=module_from_spec(spec);spec.loader.exec_module(attribution);attribution.check()
        with tempfile.TemporaryDirectory(prefix='definitions_attribution_negative_') as directory:
            target=Path(directory)
            doc=attribution.expected();doc['rows'][0]['sha256']='0'*64
            (target/'attribution.json').write_text(json.dumps(doc))
            with patch.object(attribution,'HERE',target):
                with self.assertRaisesRegex(RuntimeError,'stale'):attribution.check()

    def test_laravel_composition_registry(self):
        import copy
        contract = gate.SUITES[('laravel-composition', 'test')]
        durable = gate.SUITES[('laravel-composition', 'pg-test')]
        self.assertEqual((contract.owner, contract.tier, len(contract.cases)), ('P6.1', 'contract-scripted', 22))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P6.1', 'isolated-postgres', 18))
        self.assertTrue(durable.requires_pg)
        for case in ('CM-041', 'CM-042', 'V2ALL-MANAGED-NEGATIVE', 'V2ALL-MANAGED-FAULT'):
            self.assertIn(case, durable.cases)
        gate.hashes(ROOT, contract.dependencies)
        report = {'mode':contract.mode, 'tier':contract.tier,
            'cases':[{'id':c,'status':'passed'} for c in contract.cases],
            'packaging':{'provider':True,'default_routes':0,'default_host_bindings':False,'testing_autoload':False},
            'postgres':{'mode':durable.mode,'tier':durable.tier,
                'cases':[{'id':c,'status':'passed'} for c in durable.cases],
                'isolation':{'engine':'postgresql','isolated':True,'database':'agentools_test_laravel_registry',
                    'owner':'test','allow_marker_verified':True,'case_ids':list(durable.cases)}}}
        gate.validate_report(contract, report)
        for change in ('proof','missing','skip','zero','packaging','packaging-type'):
            bad=copy.deepcopy(report)
            if change == 'proof': bad['postgres']['isolation']=None
            elif change == 'missing': bad['postgres']['cases'].pop()
            elif change == 'skip': bad['postgres']['cases'][0]['status']='skipped'
            elif change == 'zero': bad['cases']=[]
            elif change == 'packaging-type': bad['packaging']['default_routes']=False
            else: bad['packaging']['testing_autoload']=True
            with self.assertRaises(gate.GateError): gate.validate_report(contract,bad)
        args=gate.parser().parse_args(['test','--suite','laravel-composition'])
        with patch.object(gate,'run_suite',return_value={'ok':True}) as run:
            self.assertEqual(gate.execute(args),[{'ok':True}])
        run.assert_called_once_with(ROOT,contract,isolated=False)
        from importlib.util import spec_from_file_location, module_from_spec
        spec = spec_from_file_location('laravel_reuse_check', ROOT / 'packages/agentools-laravel/tests/Composition/reuse.py')
        reuse = module_from_spec(spec); spec.loader.exec_module(reuse)
        reuse.check()
        with tempfile.TemporaryDirectory(prefix='laravel_reuse_negative_') as directory:
            target = Path(directory)
            for name in ('reuse-map.json','ManagedTest.php','LifecycleTest.php'):
                shutil.copyfile(reuse.HERE / name,target / name)
            with patch.object(reuse,'HERE',target):
                data=json.loads((target / 'reuse-map.json').read_text())
                bad=copy.deepcopy(data); bad['rows'][0]['sha256']='0'*64
                (target / 'reuse-map.json').write_text(json.dumps(bad))
                with self.assertRaisesRegex(RuntimeError,'stale'): reuse.check()
                (target / 'reuse-map.json').write_text(json.dumps(data))
                (target / 'LifecycleTest.php').write_text('<?php // consumer removed')
                with self.assertRaisesRegex(RuntimeError,'Missing reuse consumer'): reuse.check()

    def test_write_recovery_registry(self):
        import copy
        portable = gate.SUITES[('write-recovery', 'test')]
        durable = gate.SUITES[('write-recovery', 'e2e')]
        self.assertEqual((portable.owner, portable.tier, len(portable.cases)), ('P5.5', 'contract-scripted', 117))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P5.5', 'local-host-e2e', 52))
        self.assertTrue(durable.requires_pg)
        gate.hashes(ROOT, durable.dependencies)
        from importlib.util import spec_from_file_location, module_from_spec
        spec = spec_from_file_location('portable_validator', ROOT / 'conformance/platform/write-journey/parity.py')
        parity = module_from_spec(spec); spec.loader.exec_module(parity)
        fixtures = json.loads((ROOT / 'fixtures/platform/v1/recovery/portable.json').read_text())['cases']
        reports = [{'consumer': c, 'tier': 'contract-scripted', 'cases': [{'id': f['id'], 'observed': f['expected']} for f in fixtures]} for c in parity.CONSUMERS]
        pr = parity.validate(fixtures, reports)
        gate.validate_report(portable, pr)
        for consumer_change in ('missing', 'duplicate', 'extra', 'disagree', 'type'):
            changed = copy.deepcopy(reports)
            if consumer_change == 'missing': changed[0]['cases'].pop()
            elif consumer_change == 'duplicate': changed[0]['cases'].append(changed[0]['cases'][0])
            elif consumer_change == 'extra': changed[0]['cases'][0]['id'] = 'unrequired'
            elif consumer_change == 'type': changed[0]['cases'][0]['observed']['accepted'] = 1
            else: changed[0]['cases'][0]['observed']['accepted'] = 'fabricated'
            with self.assertRaises(ValueError): parity.validate(fixtures, changed)
        report = {'mode': durable.mode, 'tier': durable.tier, 'cases': [{'id': c, 'status': 'passed'} for c in durable.cases],
            'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_write_registry', 'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)},
            'portable': pr, 'observations': {
                'crash-matrix': {'boundaries': {n: {'worker_exit': 9, 'before': {'outcome': 'unknown'}, 'after': {'status': 'unresolved' if n == 'invocation-intent' else 'completed', 'owner_writes': 0 if n == 'invocation-intent' else 1}} for n in gate.WRITE_BOUNDARIES}},
                'atomic-matrix': {'boundaries': {n: {'rolled_back': True, 'owner_writes': 1, 'messages': 1} for n in gate.WRITE_ATOMIC_BOUNDARIES}},
                'zombie': {'old_worker_alive_after_db_lease_expiry': True, 'facts': {'stale_run': True, 'stale_effect': True, 'owner_writes': 1}, 'accepted_epoch': 2, 'original_epoch': 1},
                'rollback': {'reader_revision': '2026-09', 'owner_writes': 1, 'projection_digest': 'sha-256:' + 'a' * 64}}}
        gate.validate_report(durable, report)
        for invalid_digest in ('a' * 64, 'sha-256:' + 'a' * 63, 'sha-256:' + 'g' * 64,
                               'sha-512:' + 'a' * 64, 'sha-256:' + 'a' * 64 + '\n'):
            changed = copy.deepcopy(report)
            changed['observations']['rollback']['projection_digest'] = invalid_digest
            with self.assertRaisesRegex(gate.GateError, 'compatible reader rollback proof missing'):
                gate.validate_report(durable, changed)
        for mutation in ('case', 'isolation', 'crash', 'atomic', 'zombie', 'rollback', 'portable'):
            changed = copy.deepcopy(report)
            if mutation == 'case': changed['cases'].pop()
            elif mutation == 'isolation': changed['isolation'] = None
            elif mutation == 'portable': changed['portable']['cases'].pop()
            elif mutation == 'crash': changed['observations']['crash-matrix']['boundaries'].pop('owner-commit')
            elif mutation == 'atomic': changed['observations']['atomic-matrix']['boundaries'].pop('model-result')
            else: changed['observations'].pop(mutation)
            with self.assertRaises(gate.GateError): gate.validate_report(durable, changed)
        args = gate.parser().parse_args(['e2e', '--scenario', 'write-recovery'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, durable, isolated=True)

    def test_cancellation_resolution_registry(self):
        portable = gate.SUITES[('cancellation-resolution', 'test')]
        durable = gate.SUITES[('cancellation-resolution', 'pg-test')]
        self.assertEqual((portable.owner, portable.tier, len(portable.cases)), ('P5.4', 'contract-scripted', 11))
        self.assertEqual((durable.owner, durable.tier, len(durable.cases)), ('P5.4', 'isolated-postgres', 13))
        self.assertTrue(durable.requires_pg)
        self.assertTrue({'CANCEL-001', 'cancel.explicit-compensation', 'cancel.crash-after-commit',
                         'cancel.manual-cas-replay', 'cancel.competing-commands'}.issubset(durable.cases))
        gate.hashes(ROOT, durable.dependencies)
        report = {'mode': durable.mode, 'tier': durable.tier,
                  'cases': [{'id': name, 'status': 'passed'} for name in durable.cases],
                  'isolation': {'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_operations',
                                'owner': 'test', 'allow_marker_verified': True, 'case_ids': list(durable.cases)}}
        gate.validate_report(durable, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, isolation=None), dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(durable, altered)
        args = gate.parser().parse_args(['pg-test', '--suite', 'cancellation-resolution', '--isolated'])
        with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
            self.assertEqual(gate.execute(args), [{'ok': True}])
        run.assert_called_once_with(ROOT, durable, isolated=True)

    def test_usage_registry(self):
        import copy
        import jsonschema
        spec = gate.SUITES[('usage-normalization', 'test')]
        portable = gate.SUITES[('usage-parity', 'test')]
        self.assertIs(spec, gate.SUITES[('usage-budget', 'test')])
        self.assertIs(spec, gate.SUITES[('usage', 'test')])
        self.assertIs(spec, gate.SUITES[('usage-admission', 'test')])
        self.assertEqual((spec.owner, spec.tier, len(spec.cases)), ('P9.2', 'contract-scripted', 33))
        self.assertEqual(len(portable.cases), 19)
        self.assertTrue({'CM-035', 'CM-036', 'CM-037', 'CM-038', 'ECO-USE-009', 'ECO-USE-010',
                         'V2-P9-2-FALLBACK-POS', 'V2-P9-2-FALLBACK-NEG', 'V2ALL-USAGE-POSITIVE',
                         'V2ALL-USAGE-NEGATIVE', 'V2ALL-USAGE-FAULT'}.issubset(spec.cases))
        def report(registered):
            return {'mode':registered.mode, 'tier':registered.tier,
                    'cases':[{'id':name, 'status':'passed'} for name in registered.cases]}
        good = report(spec)
        gate.validate_report(spec, good)
        for cases in ([], good['cases'][:-1], [dict(c, status='skipped') for c in good['cases']]):
            with self.assertRaises(gate.GateError): gate.validate_report(spec, dict(good, cases=cases))
        language = dict(report(portable), observations={name:{'reasoning_tokens':None} for name in portable.cases})
        parity = dict(report(portable), languages={'php':copy.deepcopy(language), 'dart':copy.deepcopy(language)})
        gate.validate_report(portable, parity)
        corrupted = copy.deepcopy(parity)
        corrupted['languages']['dart']['observations']['CM-035']['reasoning_tokens'] = 0
        with self.assertRaises(gate.GateError): gate.validate_report(portable, corrupted)
        with self.assertRaises(gate.GateError): gate.validate_report(portable, report(portable))
        for registered in (spec, portable):
            self.assertTrue(all(not p.startswith('plans/') for p in registered.dependencies))
            gate.hashes(ROOT, registered.dependencies)
        for name in ('usage-normalization', 'usage-budget', 'usage', 'usage-admission', 'usage-parity'):
            with patch.object(gate, 'run_suite', return_value={'ok':True}) as run:
                gate.execute(gate.parser().parse_args(['test', '--suite', name]))
            run.assert_called_once_with(ROOT, gate.SUITES[(name, 'test')], isolated=False)
        base = ROOT / 'fixtures/platform/v1/usage'
        schemas = ROOT / 'spec/platform/v1/usage'
        for name in ('usage-event', 'admission-request', 'admission-result'):
            validator = jsonschema.Draft202012Validator(json.loads((schemas / (name + '.schema.json')).read_text()),
                                                       format_checker=jsonschema.FormatChecker())
            value = json.loads((base / (name + '.json')).read_text())
            validator.validate(value)
            bad = dict(value, extra=True)
            with self.assertRaises(jsonschema.ValidationError): validator.validate(bad)
        event = json.loads((base / 'usage-event.json').read_text())
        validator = jsonschema.Draft202012Validator(json.loads((schemas / 'usage-event.schema.json').read_text()))
        for units in ({'output_tokens':-1}, {'audio_input_units':1.25}, {'reasoning_tokens':'0'}):
            bad = copy.deepcopy(event); bad['units'].update(units)
            with self.assertRaises(jsonschema.ValidationError): validator.validate(bad)
        source = '<?php namespace AgenToolsPlatform\\Core\\Usage; final class Bad { public function leak() { return file_get_contents("secret"); } }'
        self.assertTrue(gate.source_errors(ROOT, source, 'src/Usage/Bad.php'))
        source = '<?php namespace AgenToolsPlatform\\Core\\Usage; use AgenToolsPlatform\\Core\\Models\\ModelTransport; final class Bad {}'
        self.assertTrue(gate.source_errors(ROOT, source, 'src/Usage/Bad.php'))

    def test_routing_registry(self):
        spec = gate.SUITES[('routing', 'test')]
        durable = gate.SUITES[('routing', 'pg-test')]
        self.assertIs(spec, gate.SUITES[('deployment-catalog', 'test')])
        self.assertIs(spec, gate.SUITES[('deployment-routing', 'test')])
        self.assertIs(durable, gate.SUITES[('deployment-routing', 'pg-test')])
        self.assertEqual((spec.owner, spec.mode, spec.tier), ('P9.1', 'contract-scripted', 'contract-scripted'))
        self.assertEqual(len(spec.cases), 25)
        self.assertEqual(len(durable.cases), 6)
        self.assertTrue(durable.requires_pg)
        self.assertTrue({'CM-023', 'CM-024', 'CM-025', 'CM-026', 'CM-027', 'V2-P9-1-INTERRUPT-POS',
                         'V2-P9-1-RECONCILE-POS', 'V2ALL-MODEL-POSITIVE', 'V2ALL-MODEL-NEGATIVE',
                         'V2ALL-MODEL-FAULT'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier,
                  'cases': [{'id': name, 'status': 'passed'} for name in spec.cases]}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']]),
                        dict(report, tier='live-provider')):
            with self.assertRaises(gate.GateError): gate.validate_report(spec, altered)
        with self.assertRaises(gate.GateError): gate.validate_report(durable, report)
        for registered in (spec, durable):
            self.assertTrue(all(not path.startswith('plans/') for path in registered.dependencies))
            gate.hashes(ROOT, registered.dependencies)
        for suite in ('routing', 'deployment-routing'):
            for mode in ('test', 'pg-test'):
                args = gate.parser().parse_args([mode, '--suite', suite] + (['--isolated'] if mode == 'pg-test' else []))
                with patch.object(gate, 'run_suite', return_value={'ok': True}) as run:
                    self.assertEqual(gate.execute(args), [{'ok': True}])
                run.assert_called_once_with(ROOT, gate.SUITES[('routing', mode)], isolated=mode == 'pg-test')
        # The schema is structural; temporal and cross-field semantics are enforced by CatalogEntry.
        import jsonschema
        fixture_root = ROOT / 'fixtures/platform/v1/routing'
        validator = jsonschema.Draft202012Validator(json.loads((fixture_root / 'catalog.schema.json').read_text()),
                                                   format_checker=jsonschema.FormatChecker())
        catalog = json.loads((fixture_root / 'catalog.json').read_text())
        validator.validate(catalog)
        for change in ({'extra': True}, {'cost_minor': 0}, {'region': ''},
                       {'endpoint': 'https://secret@provider.invalid'}, {'capabilities': {'invented': {}}}):
            with self.assertRaises(jsonschema.ValidationError): validator.validate(dict(catalog, **change))

    def test_model_transport_registry(self):
        spec = gate.SUITES[('model-contract', 'test')]
        self.assertIs(spec, gate.SUITES[('model-transport', 'test')])
        self.assertEqual((spec.owner, spec.mode, spec.tier), ('P4.1', 'contract-scripted', 'contract-scripted'))
        self.assertEqual(len(spec.cases), 20)
        self.assertEqual(len(set(spec.cases)), 20)
        self.assertTrue({'CM-028', 'CM-029', 'CM-030'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases]}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(c, status='skipped') for c in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(spec, altered)
        self.assertTrue(all(not p.startswith('plans/') for p in spec.dependencies))
        gate.hashes(ROOT, spec.dependencies)
        args = gate.parser().parse_args(['test', '--suite', 'model-contract'])
        result = {'ok': True}
        with patch.object(gate, 'run_suite', return_value=result) as run:
            self.assertEqual(gate.execute(args), [result])
        run.assert_called_once_with(ROOT, spec, isolated=False)

    def test_platform_registry(self):
        contracts = gate.SUITES[("contracts", "test")]
        schemas = gate.SUITES[("platform-spec", "check-spec")]
        self.assertEqual((contracts.owner, contracts.tier), ("P2.2", "contract-scripted"))
        self.assertEqual((schemas.owner, schemas.tier), ("P2.2", "design-schema"))
        self.assertTrue(contracts.cases and schemas.cases)
        self.assertEqual(len(contracts.cases), len(set(contracts.cases)))
        self.assertEqual(len(schemas.cases), 40)
        self.assertTrue(all(not p.startswith("plans/") for p in schemas.dependencies))

    def test_aggregate_preflight(self):
        args = gate.parser().parse_args(["acceptance", "--offline"])
        with patch.object(gate, "run_suite") as run:
            with self.assertRaisesRegex(gate.GateError, "not implemented/registered"):
                gate.execute(args)
        run.assert_not_called()

    def test_fsm_registry(self):
        spec = gate.SUITES[("fsm-registry", "test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P2.3", "unit", "unit"))
        self.assertFalse(spec.requires_pg)
        self.assertEqual(len(spec.cases), 16)
        self.assertEqual(len(spec.cases), len(set(spec.cases)))
        for case in ("RUN-007", "V2ALL-CATALOG-POSITIVE", "V2ALL-CATALOG-NEGATIVE",
                     "V2ALL-CATALOG-FAULT", "V2ALL-STATES-POSITIVE",
                     "V2ALL-STATES-NEGATIVE", "V2ALL-STATES-FAULT"):
            self.assertIn(case, spec.cases)
        report = {"mode": "unit", "tier": "unit", "cases": [
            {"id": name, "status": "passed"} for name in spec.cases]}
        self.assertEqual(gate.validate_report(spec, report)["count"], 16)
        report["cases"].pop()
        with self.assertRaises(gate.GateError):
            gate.validate_report(spec, report)

    def test_exact_cases(self):
        result = gate.validate_report(self.spec, self.report)
        self.assertEqual(result, {"count": 2, "failures": 0, "skips": 0, "case_ids": ["one", "two"]})

    def test_testkit_registry(self):
        spec = gate.SUITES[("testkit-parity", "test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P2.4", "contract-scripted", "contract-scripted"))
        self.assertFalse(spec.requires_pg)
        self.assertEqual(len(spec.cases), 31)
        self.assertEqual(len(spec.cases), len(set(spec.cases)))
        self.assertIn('portable.usage.missing-not-zero', spec.cases)
        self.assertIn('testkit.real-dedup-unknown-effect', spec.cases)
        self.assertIn('compatibility.current-semantic-parity', spec.cases)
        self.assertTrue(all(not p.startswith('plans/') for p in spec.dependencies))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases]}
        gate.validate_report(spec, report)
        report['cases'].pop()
        with self.assertRaises(gate.GateError):
            gate.validate_report(spec, report)

    def test_pg_schema_registry(self):
        spec = gate.SUITES[("pg-schema", "pg-test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P3.1", "isolated-postgres", "isolated-postgres"))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 13)
        self.assertEqual(len(set(spec.cases)), 13)
        self.assertIn('pg-schema.testDeliveryResolutionRequiresBoundLatestReceipt', spec.cases)
        self.assertIn('packages/agentools-postgres/migrations/0005_delivery_resolution.sql', spec.dependencies)
        self.assertIn('pg-schema.testUnsafeLifecycleRefusedBeforeMutation', spec.cases)
        self.assertIn('pg-schema.testFailedUpgradeRollsBackDdlAndHistory', spec.cases)
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases], 'isolation': {
                'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        report['cases'].pop()
        with self.assertRaises(gate.GateError):
            gate.validate_report(spec, report)
        with self.assertRaisesRegex(gate.GateError, '--isolated'):
            gate.run_suite(ROOT, spec)

    def test_pg_intake_registry(self):
        spec = gate.SUITES[("pg-intake", "pg-test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P3.2", "isolated-postgres", "isolated-postgres"))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 11)
        self.assertEqual(len(set(spec.cases)), 11)
        self.assertTrue({'RUN-001', 'RUN-002', 'V2-P3-2-INTAKE-FAULT',
                         'V2ALL-IDENTITY-POSITIVE', 'V2ALL-IDENTITY-NEGATIVE',
                         'V2ALL-IDENTITY-FAULT'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases], 'isolation': {
                'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        report['cases'].pop()
        with self.assertRaises(gate.GateError):
            gate.validate_report(spec, report)
        with self.assertRaisesRegex(gate.GateError, '--isolated'):
            gate.run_suite(ROOT, spec)
        gate.hashes(ROOT, spec.dependencies)

    def test_pg_claims_outbox_registry(self):
        spec = gate.SUITES[("pg-claims-outbox", "pg-test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P3.3", "isolated-postgres", "isolated-postgres"))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 11)
        self.assertEqual(len(set(spec.cases)), 11)
        self.assertTrue({'RUN-003', 'RUN-004', 'V2-P3-3-WAKE-POS', 'V2-P3-3-WAKE-NEG',
                         'V2-P3-3-CLAIM-POS', 'V2ALL-STORAGE-POSITIVE',
                         'V2ALL-STORAGE-NEGATIVE', 'V2ALL-STORAGE-FAULT'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases], 'isolation': {
                'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(case, status='skipped') for case in report['cases']])):
            with self.assertRaises(gate.GateError):
                gate.validate_report(spec, altered)
        with self.assertRaisesRegex(gate.GateError, '--isolated'):
            gate.run_suite(ROOT, spec)
        gate.hashes(ROOT, spec.dependencies)

    def test_pg_action_journal_registry(self):
        spec = gate.SUITES[("pg-action-journal", "pg-test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P3.4", "isolated-postgres", "isolated-postgres"))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 9)
        self.assertEqual(len(set(spec.cases)), 9)
        self.assertTrue({'ACT-001', 'ACT-005', 'V2-P3-4-INTENT-POS', 'V2ALL-JOURNAL-POSITIVE',
                         'V2ALL-JOURNAL-NEGATIVE', 'V2ALL-JOURNAL-FAULT'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases], 'isolation': {
                'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(case, status='skipped') for case in report['cases']])):
            with self.assertRaises(gate.GateError):
                gate.validate_report(spec, altered)
        with self.assertRaisesRegex(gate.GateError, '--isolated'):
            gate.run_suite(ROOT, spec)
        gate.hashes(ROOT, spec.dependencies)

    def test_pg_isolation_retention_registry(self):
        spec = gate.SUITES[("pg-isolation-retention", "pg-test")]
        self.assertEqual((spec.owner, spec.mode, spec.tier), ("P3.5", "isolated-postgres", "isolated-postgres"))
        self.assertTrue(spec.requires_pg)
        self.assertEqual(len(spec.cases), 65)
        self.assertEqual(len(set(spec.cases)), 65)
        self.assertIn('pg-isolation-retention.testLegacyPurgeReservationRequiresReceiptResolution', spec.cases)
        for family in (gate.PG_SCHEMA_CASES, gate.PG_INTAKE_CASES, gate.PG_CLAIMS_OUTBOX_CASES, gate.PG_ACTION_JOURNAL_CASES):
            self.assertTrue(set(family).issubset(spec.cases))
        self.assertTrue({'TEN-001', 'V2-P3-5-TENANT-POS', 'V2-P3-5-TENANT-FAULT',
                         'V2ALL-TENANT-POSITIVE', 'V2ALL-TENANT-NEGATIVE', 'V2ALL-TENANT-FAULT',
                         'ACT-005', 'V2-P3-4-INTENT-POS'}.issubset(spec.cases))
        report = {'mode': spec.mode, 'tier': spec.tier, 'cases': [
            {'id': name, 'status': 'passed'} for name in spec.cases], 'isolation': {
                'engine': 'postgresql', 'isolated': True, 'database': 'agentools_test_fixture',
                'owner': 'fixture', 'allow_marker_verified': True, 'case_ids': list(spec.cases)}}
        gate.validate_report(spec, report)
        for altered in (dict(report, cases=[]), dict(report, cases=report['cases'][:-1]),
                        dict(report, cases=[dict(case, status='skipped') for case in report['cases']])):
            with self.assertRaises(gate.GateError): gate.validate_report(spec, altered)
        with self.assertRaisesRegex(gate.GateError, '--isolated'): gate.run_suite(ROOT, spec)
        gate.hashes(ROOT, spec.dependencies)

    def test_zero_cases(self):
        self.report["cases"] = []
        self.rejects()
        self.rejects(spec=replace(self.spec, cases=()))

    def test_skips(self):
        self.report["cases"][0]["status"] = "skipped"
        self.rejects()

    def test_failure(self):
        self.report["cases"][0]["status"] = "failed"
        self.rejects()

    def test_duplicate_cases(self):
        self.report["cases"][1]["id"] = "one"
        self.rejects()
        self.rejects(spec=replace(self.spec, cases=("one", "one")))

    def test_extra_cases(self):
        self.report["cases"].append({"id": "extra", "status": "passed"})
        self.rejects()

    def test_missing_cases(self):
        self.report["cases"].pop()
        self.rejects()

    def test_mode_mismatch(self):
        self.report["mode"] = "live"
        self.rejects()

    def test_tier_mismatch(self):
        self.report["tier"] = "live-provider"
        self.rejects()

    def test_unknown_status(self):
        self.report["cases"][0]["status"] = "unknown"
        self.rejects()

    def test_malformed_report(self):
        for report in ([], {"mode": self.spec.mode, "tier": self.spec.tier, "cases": [None]},
                       {"mode": self.spec.mode, "tier": self.spec.tier}):
            with self.subTest(report=report):
                self.rejects(report=report)

    def pg(self):
        self.spec = replace(self.spec, requires_pg=True, mode="isolated-postgres", tier="isolated-postgres")
        self.report.update(mode=self.spec.mode, tier=self.spec.tier, isolation={
            "engine": "postgresql", "isolated": True, "database": "agentools_test_fixture",
            "owner": "fixture_owner", "allow_marker_verified": True, "case_ids": list(self.spec.cases)})

    def test_pg_proof_missing(self):
        self.pg()
        del self.report["isolation"]
        self.rejects()
        self.pg()
        self.report["isolation"]["database"] = "production"
        self.rejects()
        self.pg()
        self.report["isolation"]["allow_marker_verified"] = False
        self.rejects()
        with self.assertRaisesRegex(gate.GateError, "--isolated"):
            gate.run_suite(ROOT, self.spec)

    def test_pg_case_set(self):
        self.pg()
        self.assertEqual(gate.validate_report(self.spec, self.report)["count"], 2)
        self.report["isolation"]["case_ids"] = ["one"]
        self.rejects()

    def test_runner_nonzero(self):
        process = subprocess.CompletedProcess(self.spec.command, 9, json.dumps(self.report), "failure")
        with patch.object(gate.subprocess, "run", return_value=process):
            result = gate.run_suite(ROOT, self.spec)
        self.assertFalse(result["ok"])
        self.assertEqual(result["exit"], 9)
        self.assertEqual(result["stderr"], "failure")

    def test_missing_dependency(self):
        with self.assertRaisesRegex(gate.GateError, "missing dependency"):
            gate.run_suite(ROOT, replace(self.spec, dependencies=("missing-required.lock",)))

    def test_missing_runner(self):
        with self.assertRaises(gate.GateError):
            gate.run_suite(ROOT, replace(self.spec, command=("/missing-platform-runner",)))
        with patch.object(gate.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, "", "")):
            self.assertFalse(gate.run_suite(ROOT, self.spec)["ok"])

    def test_receipt_hashes(self):
        with tempfile.TemporaryDirectory(prefix="agentools_receipt_") as directory:
            receipt = Path(directory) / "result.json"
            result = {"ok": True, "stdout": "exact output", "stderr": "diagnostics", "mode": "tooling-self", "tier": "unit"}
            with patch.object(gate, "execute", return_value=[result]), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(gate.main(["test", "--suite", "gate-self", "--receipt", str(receipt)]), 0)
            data = json.loads(receipt.read_text())
            self.assertEqual(len(data["source"]["dirty_diff_digest"]), 64)
            self.assertTrue(data["input_hashes"])
            self.assertEqual(data["cost"]["provider_calls"], 0)
            for stream, expected in (("stdout", b"exact output"), ("stderr", b"diagnostics")):
                output = data["results"][0][stream]
                self.assertEqual(Path(output["path"]).read_bytes(), expected)
                self.assertEqual(output["sha256"], gate.digest(expected))

    def test_timeout(self):
        with patch.object(gate.subprocess, "run", side_effect=subprocess.TimeoutExpired(self.spec.command, 1)):
            with self.assertRaisesRegex(gate.GateError, "timeout"):
                gate.run_suite(ROOT, self.spec)

    def test_live_refused(self):
        result = self.cli(["qualify", "--live", "--provider", "synthetic", "--max-cost-minor", "1", "--currency", "USD"])
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(json.loads(result.stdout)["cost"]["provider_calls"], 0)
        self.assertNotEqual(self.cli(["qualify", "--offline", "--provider", "synthetic"]).returncode, 0)


class RecordingResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.cases = []

    def record(self, test, status):
        self.cases.append({"id": f"{test.__class__.__name__}.{test._testMethodName}", "status": status})

    def addSuccess(self, test):
        super().addSuccess(test)
        self.record(test, "passed")

    def addFailure(self, test, err):
        super().addFailure(test, err)
        self.record(test, "failed")

    def addError(self, test, err):
        super().addError(test, err)
        self.record(test, "failed")

    def addSkip(self, test, reason):
        super().addSkip(test, reason)
        self.record(test, "skipped")

    def addExpectedFailure(self, test, err):
        super().addExpectedFailure(test, err)
        self.record(test, "failed")

    def addUnexpectedSuccess(self, test):
        super().addUnexpectedSuccess(test)
        self.record(test, "failed")


if __name__ == "__main__":
    report_mode = "--report" in sys.argv
    if report_mode:
        sys.argv.remove("--report")
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(GateTests)
    result = unittest.TextTestRunner(stream=sys.stderr, verbosity=2, resultclass=RecordingResult).run(suite)
    report = {"mode": "tooling-self", "tier": "unit", "cases": result.cases}
    if report_mode:
        print(json.dumps(report))
    ok = result.wasSuccessful() and not result.skipped and result.testsRun > 0
    try:
        gate.validate_report(gate.SUITES[("gate-self", "test")], report)
    except gate.GateError as error:
        print(f"gate-self report validation failed: {error}", file=sys.stderr)
        ok = False
    sys.exit(0 if ok else 1)
