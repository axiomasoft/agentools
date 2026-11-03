#!/usr/bin/env python3
"""Measure selected existing evaluator golden/fault tests; never accesses a provider."""
import hashlib
import importlib.util
import io
import json
import platform
from pathlib import Path
import sys
import time
import unittest
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[3]
TEST_FILE = ROOT / 'packages/agentools-testing/tests/Evaluation/test_harness.py'
sys.path.insert(0, str(ROOT / 'packages/agentools-testing/src/Evaluation'))
sys.path.insert(0, str(ROOT / 'tools'))


def benchmark():
    spec = importlib.util.spec_from_file_location('evaluation_baseline_cases', TEST_FILE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    groups = {'golden': ('test_teacher_golden', 'test_support_golden'),
              'fault': ('test_hard_faults', 'test_unknown_evidence', 'test_budget', 'test_integration_faults')}
    results = []
    for name, methods in groups.items():
        timer = time.monotonic_ns()
        result = unittest.TextTestRunner(stream=io.StringIO(), verbosity=0).run(
            unittest.TestSuite(module.EvaluationTests(m) for m in methods))
        elapsed = (time.monotonic_ns() - timer) / 1_000_000
        if result.testsRun != len(methods) or result.errors or result.failures or result.skipped:
            raise RuntimeError(f'{name} benchmark failed, skipped, or executed wrong count')
        results.append({'group': name, 'methods': list(methods), 'test_count': result.testsRun,
                        'elapsed_ms': elapsed, 'failures': len(result.failures) + len(result.errors),
                        'skips': len(result.skipped), 'provider_calls': 0, 'measured_spend_minor': 0,
                        'cost_currency': 'USD', 'cost_basis': 'no provider calls; no price inference'})
    inputs = [TEST_FILE, ROOT / 'packages/agentools-testing/src/Evaluation/harness.py',
              *sorted((ROOT / 'fixtures/platform/v1/evaluation').glob('*.json'))]
    return {'format': 'agentools-local-baseline/v1', 'measured_at': datetime.now(timezone.utc).isoformat(),
            'tier': 'contract-scripted', 'measurement_kind': 'local-evaluator-unittest',
            'environment': {'python': platform.python_version(), 'platform': platform.platform()},
            'fixture_policy': {'name': 'synthetic-example-not-slo', 'max_provider_calls': 0,
                               'max_cost_minor': 0, 'currency': 'USD'},
            'measurements': results,
            'input_sha256': {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in inputs},
            'production_slo': None, 'provider_pricing': None, 'live_provider': 'not_run',
            'readiness': 'technical-contract-only',
            'limitations': ['Elapsed wall time includes local unittest overhead, one sample, not percentile or SLO.',
                            'Scripted tests and zero provider calls measure no live latency, pricing, cost or quality.',
                            'No real host, product pilot, provider or domain qualification is claimed.']}


if __name__ == '__main__':
    print(json.dumps(benchmark(), indent=2, ensure_ascii=False))
