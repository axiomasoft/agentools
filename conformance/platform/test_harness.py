"""Negative tests of the new exact-set/applicability checker, not provider qualification."""
import copy
import json
import sys
import unittest
from unittest.mock import patch

import harness


class CompatibilityTests(unittest.TestCase):
    def setUp(self):
        self.rows = harness.vectors()
        self.matrix = harness.document('fixtures/platform/v1/compatibility/applicability.json')
        cases = []
        for vector in self.rows:
            if vector['kind'] == 'schema':
                fixture = harness.document(vector['source'])
                observed = {'valid': fixture['expect']['valid'], 'data': fixture['data']}
                tier = 'design-schema'
            else:
                observed = {**vector['expected'], 'previous_hash': 'sha-256:' + 'a' * 64}
                tier = 'contract-scripted'
            cases.append({'id': vector['id'], 'status': 'passed', 'tier': tier, 'observed': observed})
        self.report = {'consumer': 'php', 'cases': cases}
        self.reports = {name: {**copy.deepcopy(self.report), 'consumer': name} for name in harness.CONSUMERS}

    def test_case_set(self):
        harness.validate_worker(self.report, 'php', self.rows)
        for rows in ([], self.report['cases'][:-1], self.report['cases'] + self.report['cases'][:1]):
            with self.assertRaises(harness.CompatibilityError):
                harness.validate_worker({**self.report, 'cases': rows}, 'php', self.rows)
        self.report['cases'][0]['status'] = 'skipped'
        with self.assertRaises(harness.CompatibilityError):
            harness.validate_worker(self.report, 'php', self.rows)

    def test_missing_consumer(self):
        harness.compare_consumers(self.reports, self.rows)
        del self.reports['dart']
        with self.assertRaises(harness.CompatibilityError):
            harness.compare_consumers(self.reports, self.rows)
        self.report['consumer'] = 'reference'
        with self.assertRaises(harness.CompatibilityError):
            harness.validate_worker(self.report, 'php', self.rows)

    def test_tiers(self):
        for tier in ('isolated-postgres', 'live-provider', 'unit'):
            self.report['cases'][0]['tier'] = tier
            with self.assertRaises(harness.CompatibilityError):
                harness.validate_worker(self.report, 'php', self.rows)

    def test_observed(self):
        for index in (0, 8):
            mutated = copy.deepcopy(self.report)
            mutated['cases'][index]['observed'] = {}
            with self.assertRaises(harness.CompatibilityError):
                harness.validate_worker(mutated, 'php', self.rows)
        mutated = copy.deepcopy(self.report)
        mutated['cases'][8]['observed']['data']['output_tokens'] = 0
        with self.assertRaises(harness.CompatibilityError):
            harness.validate_worker(mutated, 'php', self.rows)
        mutated = copy.deepcopy(self.report)
        mutated['cases'][9]['observed']['data']['output_tokens'] = False
        with self.assertRaises(harness.CompatibilityError):
            harness.validate_worker(mutated, 'php', self.rows)

    def test_drift(self):
        self.reports['dart']['cases'][0]['observed']['previous_hash'] = 'sha-256:' + 'b' * 64
        with self.assertRaises(harness.CompatibilityError):
            harness.compare_consumers(self.reports, self.rows)

    def test_applicability(self):
        harness.validate_applicability(self.matrix, self.rows)
        for mutate in (
            lambda m: m['surfaces'][0]['consumers'].pop('dart'),
            lambda m: m['surfaces'][1]['consumers']['dart'].update(applicable=False),
            lambda m: m['surfaces'][3]['consumers']['dart'].update(rationale=''),
            lambda m: m['surfaces'][2]['consumers']['reference'].update(applicable=True),
            lambda m: m['surfaces'][3].update(tier='local-host-e2e'),
        ):
            matrix = copy.deepcopy(self.matrix)
            mutate(matrix)
            with self.assertRaises(harness.CompatibilityError):
                harness.validate_applicability(matrix, self.rows)
        with patch.object(harness, 'document', return_value={'revision': 'platform-compatibility/v1', 'dated_revision': '2026-09', 'cases': []}):
            with self.assertRaises(harness.CompatibilityError):
                harness.vectors()


if __name__ == '__main__':
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(CompatibilityTests)
    class RecordingResult(unittest.TextTestResult):
        def __init__(self, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self.cases = []
        def addSuccess(self, test):
            super().addSuccess(test)
            self.cases.append({'id': test.id().split('.')[-2] + '.' + test.id().split('.')[-1], 'status': 'passed', 'tier': 'unit'})
    result = unittest.TextTestRunner(stream=sys.stderr, verbosity=2, resultclass=RecordingResult).run(suite)
    print(json.dumps({'mode': 'unit', 'tier': 'unit', 'cases': result.cases, 'count': result.testsRun, 'failures': len(result.failures) + len(result.errors), 'skips': len(result.skipped)}))
    raise SystemExit(0 if result.wasSuccessful() and not result.skipped and result.testsRun == len(harness.NEGATIVE_CASES) else 1)
