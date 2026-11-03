"""Serial CLI adapter for the existing platform gate; no new runtime loop."""
import argparse
from copy import deepcopy
import importlib.util
import io
import json
from pathlib import Path
import sys
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import harness as h
sys.path.insert(0, str(h.ROOT / 'tools'))
import platform_gate as gate

LIMITATIONS = [
    'Synthetic technical evidence only; no provider, domain, educational or production qualification.',
    'Quality/grounding thresholds require owner baseline and expert scorer; absent scorer is insufficient_evidence.',
    'Historical upstream receipts retain their original candidates; current linked suites require fresh execution.',
]


def load_tests():
    source = importlib.util.spec_from_file_location('evaluation_focused_tests',
        h.ROOT / 'packages/agentools-testing/tests/Evaluation/test_harness.py')
    module = importlib.util.module_from_spec(source)
    source.loader.exec_module(module)
    return module


def focused_report():
    module = load_tests()
    cases = []

    class Recording(unittest.TextTestResult):
        def addSuccess(self, test):
            super().addSuccess(test)
            cases.append({'id': module.CASES[test._testMethodName], 'status': 'passed'})

        def addFailure(self, test, err):
            super().addFailure(test, err)
            cases.append({'id': module.CASES[test._testMethodName], 'status': 'failed'})

        def addError(self, test, err):
            super().addError(test, err)
            cases.append({'id': module.CASES[test._testMethodName], 'status': 'failed'})

        def addSkip(self, test, reason):
            super().addSkip(test, reason)
            cases.append({'id': module.CASES[test._testMethodName], 'status': 'skipped'})

    discovered = unittest.defaultTestLoader.getTestCaseNames(module.EvaluationTests)
    h.require(set(discovered) == set(module.CASES), 'test methods differ from exact manifest')
    started = time.monotonic_ns()
    output = io.StringIO()
    tests = unittest.TestSuite(module.EvaluationTests(name) for name in module.CASES)
    result = unittest.TextTestRunner(stream=output, verbosity=2, resultclass=Recording).run(tests)
    sys.stderr.write(output.getvalue())
    report = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases,
              'count': len(cases), 'failures': len(result.errors) + len(result.failures), 'skips': len(result.skipped),
              'evidence_kind': 'contract-scripted', 'readiness_claim': 'technical-contract-only',
              'input_hashes': gate.hashes(h.ROOT, gate.EVALUATION_INPUTS), 'limitations': LIMITATIONS,
              'baseline': {'measurement_kind': 'local-evaluator-self-check',
                           'elapsed_ms': (time.monotonic_ns() - started) / 1_000_000,
                           'executed_cases': len(cases), 'provider_calls': 0, 'spend_minor': 0,
                           'metrics': dict.fromkeys(h.METRICS, None),
                           'domain_scorer': 'absent', 'domain_decision': 'insufficient_evidence'}}
    return report


def integration_report(name):
    suite, _, suite_hash = h.load_suite(h.ROOT, h.FIXTURES + '/' + name + '-suite.json')
    keys = h.validate_integrations(suite, gate.SUITES)
    children = []
    cases = []
    isolation = None
    for key in keys:
        spec = gate.SUITES[key]
        child = gate.run_suite(h.ROOT, spec, isolated=True, timeout=900)
        children.append({'suite': key[0], 'partition': key[1], **child})
        # On error do not turn unexecuted child cases into a PASS or continue IO.
        if not child['ok']:
            sys.stderr.write(json.dumps(children, ensure_ascii=False) + '\n')
        h.require(child['ok'], f'integration failed: {key}: {child.get("error")}')
        cases.extend({'id': key[0] + ':' + c['id'], 'status': c['status']} for c in child['report']['cases'])
        if spec.requires_pg:
            isolation = deepcopy(child['report']['isolation'])
    h.require(isolation is not None, 'real isolated PostgreSQL evidence absent')
    isolation['case_ids'] = [c['id'] for c in cases]
    return {'mode': 'local-host-e2e', 'tier': 'local-host-e2e', 'evidence_kind': 'local-host-e2e',
            'readiness_claim': 'technical-contract-only', 'suite_hash': suite_hash,
            'cases': cases, 'count': len(cases), 'failures': 0, 'skips': 0,
            'isolation': isolation, 'integrations': children,
            'input_hashes': gate.hashes(h.ROOT, gate.SUITES[(name, 'e2e')].dependencies),
            'limitations': LIMITATIONS + ['Scripted external owners/media/models; real kernel and isolated PG only.']}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', choices=('evaluation-harness', 'technical-golden', 'evaluation-faults'), required=True)
    args = parser.parse_args(argv)
    try:
        report = focused_report() if args.suite == 'evaluation-harness' else integration_report(args.suite)
        print(json.dumps(report, ensure_ascii=False))
        return 0 if report['count'] and report['failures'] == 0 and report['skips'] == 0 else 1
    except (h.EvaluationError, gate.GateError, OSError, ValueError) as error:
        print(json.dumps({'error': str(error), 'cases': [], 'failures': 1, 'skips': 0}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
