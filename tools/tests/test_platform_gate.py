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
        self.assertEqual(len(spec.cases), 54)
        self.assertEqual(len(set(spec.cases)), 54)
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
    except gate.GateError:
        ok = False
    sys.exit(0 if ok else 1)
