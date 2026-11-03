#!/usr/bin/env python3
"""PHP reporting contract regression; does not run the accounting acceptance partitions."""
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent


def php_report(named=False):
    source = ('<?php require ' + json.dumps(str(HERE / 'Proof.php')) + '; '
              'use AgenToolsPlatform\\Postgres\\Tests\\Usage\\Proof; ')
    if named:
        source += "Proof::$observations = ['named'=>['preserved'=>true]]; "
    source += "Proof::run(['reporting.probe'=>static function (): void {}]);"
    process = subprocess.run(['php'], input=source, text=True, capture_output=True, check=True)
    return json.loads(process.stdout)


class ReportingTests(unittest.TestCase):
    def test_empty_observations_are_an_object(self):
        report = php_report()
        self.assertEqual(report['cases'], [{'id': 'reporting.probe', 'status': 'passed'}])
        self.assertEqual(report['failures'], 0)
        self.assertIsInstance(report['observations'], dict)
        self.assertEqual(report['observations'], {})

    def test_named_observations_are_preserved(self):
        self.assertEqual(php_report(named=True)['observations'], {'named': {'preserved': True}})

    def test_runner_merges_empty_and_named_observations(self):
        spec = importlib.util.spec_from_file_location('usage_reporting_runner', HERE / 'run.py')
        runner = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runner)
        reports = [php_report(), php_report(named=True)]
        processes = [subprocess.CompletedProcess(['php'], 0, json.dumps(report), '') for report in reports]
        with patch.object(runner.subprocess, 'run', side_effect=processes) as run:
            result = runner.run()
        self.assertEqual(run.call_count, 2)
        self.assertEqual(result['observations'], {'named': {'preserved': True}})
        self.assertEqual((result['count'], result['failures'], result['skips']), (2, 0, 0))


if __name__ == '__main__':
    unittest.main()
