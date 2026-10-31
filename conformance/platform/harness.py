#!/usr/bin/env python3
"""Local compatibility/testkit composition. No provider calls, schema rebaseline or PG claims."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
PORTABLE_CASES = (
    'portable.action.complete', 'portable.action.wait-cancel', 'portable.action.unknown-kind',
    'portable.action.unknown-effect', 'portable.action.terminal-late', 'portable.action.stale-epoch',
    'portable.action.invalid-cancel', 'portable.action.version-cas', 'portable.usage.partial',
    'portable.usage.zero', 'portable.usage.missing-not-zero', 'portable.action.receipt',
    'portable.action.closed-wire', 'portable.error.unknown-not-known',
)
TESTKIT_CASES = (
    'testkit.clock-random', 'testkit.transport-boundaries', 'testkit.owner-lookup-unknown',
    'testkit.disclosure-revocation-isolation', 'testkit.real-facade-guards',
    'testkit.real-confirmation-guards', 'testkit.commit-crash-replay',
    'testkit.scheduler-repeatable-fail-closed', 'testkit.real-dedup-unknown-effect',
)
NEGATIVE_CASES = tuple('CompatibilityTests.test_' + name for name in (
    'case_set', 'missing_consumer', 'tiers', 'observed', 'drift', 'applicability',
))
CASES = TESTKIT_CASES + PORTABLE_CASES + NEGATIVE_CASES + (
    'compatibility.current-semantic-parity', 'compatibility.frozen-predecessor',
)
CONSUMERS = ('reference', 'php', 'dart')


class CompatibilityError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise CompatibilityError(message)


def document(path):
    return json.loads((ROOT / path).read_text())


def exact_json(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False)


def vectors():
    data = document('fixtures/platform/v1/compatibility/vectors.json')
    require(data.get('revision') == 'platform-compatibility/v1' and data.get('dated_revision') == '2026-09', 'Unknown compatibility revision')
    rows = data.get('cases')
    require(isinstance(rows, list) and [r.get('id') for r in rows] == list(PORTABLE_CASES), 'Required portable case set changed')
    require(all(r.get('kind') in {'action', 'schema'} for r in rows), 'Unknown portable case kind')
    return rows


def validate_applicability(matrix, rows):
    require(matrix.get('revision') == 'platform-applicability/v1', 'Unknown applicability revision')
    surfaces = matrix.get('surfaces')
    require(isinstance(surfaces, list) and [s.get('surface') for s in surfaces] == [
        'dated-action-behavior', 'portable-action-usage-error-schema',
        'php-core-context-action-facade', 'laravel-composition'], 'Required applicability surfaces missing')
    for index, kind, tier in ((0, 'action', 'contract-scripted'), (1, 'schema', 'design-schema')):
        surface = surfaces[index]
        require(surface.get('cases') == [r['id'] for r in rows if r['kind'] == kind] and surface.get('tier') == tier, 'Portable applicability case/tier mismatch')
        require(set(surface.get('consumers', {})) == set(CONSUMERS), 'Required language missing')
        for name in CONSUMERS:
            entry = surface['consumers'][name]
            require(entry.get('applicable') is True and bool(entry.get('rationale')), 'Portable contract cannot become N/A')
    for surface in surfaces[2:]:
        require(set(surface.get('consumers', {})) == set(CONSUMERS), 'Missing explicit framework applicability')
        for name in CONSUMERS:
            entry = surface['consumers'][name]
            require(isinstance(entry.get('applicable'), bool) and bool(entry.get('rationale')), 'N/A requires rationale')
        require(surface['consumers']['php']['applicable'] is True and surface['consumers']['dart']['applicable'] is False
                and surface['consumers']['reference']['applicable'] is False, 'PHP-only composition applicability changed')
    require(surfaces[3].get('status') == 'planned' and surfaces[3].get('cases') == [] and surfaces[3].get('tier') is None, 'Absent Laravel composition cannot be qualified')


def validate_worker(report, consumer, rows):
    require(isinstance(report, dict) and report.get('consumer') == consumer, 'Wrong/missing consumer')
    cases = report.get('cases')
    require(isinstance(cases, list) and len(cases) == len(PORTABLE_CASES), 'Missing/extra portable cases')
    require([c.get('id') for c in cases] == list(PORTABLE_CASES), 'Portable case set mismatch/duplicate')
    for result, vector in zip(cases, rows):
        require(result.get('status') == 'passed', 'Portable case failed/skipped/unknown')
        require(result.get('tier') == ('design-schema' if vector['kind'] == 'schema' else 'contract-scripted'), 'Evidence tier mismatch')
        observed = result.get('observed')
        require(isinstance(observed, dict), 'Missing observed outcome')
        if vector['kind'] == 'schema':
            fixture = document(vector['source'])
            require(set(observed) == {'valid', 'data'} and isinstance(observed['valid'], bool), 'Missing schema observation')
            require(observed['valid'] is fixture['expect']['valid'] and exact_json(observed['data']) == exact_json(fixture['data']), 'Schema validity/data mismatch; unknown/null/zero must remain exact')
        else:
            require(set(observed) == set(vector['expected']) | {'previous_hash'}, 'Missing Action observations')
            require(exact_json({k: observed[k] for k in vector['expected']}) == exact_json(vector['expected']), 'Action outcome differs from fixture')
            digest = observed['previous_hash']
            require(isinstance(digest, str) and digest.startswith('sha-256:') and len(digest) == 72, 'Missing action hash chain')
    return cases


def compare_consumers(reports, rows):
    require(set(reports) == set(CONSUMERS), 'A required consumer did not execute')
    validated = {name: validate_worker(reports[name], name, rows) for name in CONSUMERS}
    for index in range(len(rows)):
        baseline = validated['reference'][index]['observed']
        require(all(exact_json(validated[name][index]['observed']) == exact_json(baseline) for name in CONSUMERS), 'Consumer semantic/hash drift')
    return validated


def main():
    runs = []
    cases = []
    report = {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': cases, 'runs': runs,
              'limitations': ['Scripted local external ports; no production bridge/PG/provider qualification.',
                               'Usage parity is schema-only; reference Action path reuses the SDK journal/reducer.',
                               'Per-case tiers retain design-schema/unit where appropriate; Laravel adapter remains planned.']}

    def run(cmd, *, input=None, json_output=True):
        result = subprocess.run(cmd, cwd=ROOT, text=True, capture_output=True, input=input, timeout=120)
        runs.append({'cmd': cmd, 'exit': result.returncode,
                     'stdout_sha256': hashlib.sha256(result.stdout.encode()).hexdigest(),
                     'stderr': result.stderr})
        require(result.returncode == 0, 'Required command failed: ' + ' '.join(cmd))
        return json.loads(result.stdout) if json_output else result.stdout

    try:
        rows = vectors()
        validate_applicability(document('fixtures/platform/v1/compatibility/applicability.json'), rows)
        sys.path.insert(0, str(ROOT / 'tools'))
        import platform_gate as gate
        sub = run(['php', 'packages/agentools-testing/tests/run.php'])
        gate.validate_report(gate.Suite('P2.4', 'contract-scripted', 'contract-scripted', TESTKIT_CASES, (), ()), sub)
        cases.extend(sub['cases'])
        negative = run([sys.executable, '-B', 'conformance/platform/test_harness.py', '--report'])
        gate.validate_report(gate.Suite('P2.4', 'unit', 'unit', NEGATIVE_CASES, (), ()), negative)
        cases.extend(negative['cases'])
        reports = {name: run(['php', 'conformance/platform/worker.php', name]) for name in ('reference', 'php')}
        reports['dart'] = run(['dart', '--packages=dart/agentools/.dart_tool/package_config.json', 'run', 'conformance/platform/worker.dart'])
        validated = compare_consumers(reports, rows)
        report['consumer_reports'] = reports
        for index, vector in enumerate(rows):
            cases.append({'id': vector['id'], 'status': 'passed', 'tier': validated['php'][index]['tier']})
        profiles = [run(['php', 'conformance/platform/profile.php']), run(['dart', 'run', 'dart/agentools/conformance/semantic_profile.dart'])]
        manifest = document('spec/revisions/2026-09/semantic-manifest.json')
        normative = {'schema_version': 'semantic-profile/v1', 'spec_revision': '2026-09', **manifest}
        for profile in profiles:
            require(profile.get('schema_version') == 'semantic-profile/v1' and profile.get('spec_revision') == '2026-09', 'Wrong semantic revision')
            run(['php', 'conformance/platform/compare.php'], input=json.dumps({'expected': normative, 'actual': profile}))
        run(['php', 'conformance/platform/compare.php'], input=json.dumps({'expected': profiles[0], 'actual': profiles[1]}))
        cases.append({'id': 'compatibility.current-semantic-parity', 'status': 'passed', 'tier': 'contract-scripted'})
        frozen = ['spec/revisions/2026-08', 'fixtures/2026-08']
        run(['git', 'diff', '--exit-code', 'HEAD', '--', *frozen], json_output=False)
        require(not run(['git', 'ls-files', '--others', '--exclude-standard', '--', *frozen], json_output=False).strip(), 'Untracked frozen predecessor inputs')
        cases.append({'id': 'compatibility.frozen-predecessor', 'status': 'passed', 'tier': 'unit'})
        gate.validate_report(gate.Suite('P2.4', 'contract-scripted', 'contract-scripted', CASES, (), ()), report)
        report.update(count=len(cases), failures=0, skips=0)
    except Exception as error:
        report['error'] = str(error)
        print(json.dumps(report))
        return 1
    print(json.dumps(report))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
