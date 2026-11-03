"""Rebuild synthetic evaluation examples and their content pins (no model IO)."""
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import harness as h

FOLDER = h.ROOT / h.FIXTURES


def write(name, value):
    path = FOLDER / name
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + '\n')
    return h.file_hash(path)


def run_for(suite_hash, profile='generic-teacher/v1', task='technical-lesson-read'):
    run = {'schema_version': 'evaluation/v1', 'run_id': 'synthetic-evaluation-1',
           'deployment_ref': 'scripted-offline', 'model_revision': 'deterministic-fake/v1',
           'profile_ref': profile, 'task_ref': task, 'suite_hash': suite_hash,
           'tenant_ref': 'tenant-synthetic', 'audience_ref': 'synthetic-reader',
           'seed': 17, 'started_at': '2026-11-03T12:00:00Z', 'budget': budget(),
           'evidence_kind': 'contract-scripted', 'integration_receipts': []}
    for key in h.TUPLE_FIELDS:
        if key.endswith('_hash') and key != 'suite_hash':
            run[key] = h.content_hash({'synthetic_component': key, 'version': '1'})
    return h.seal_run(run)


def budget():
    return {'max_cost_minor': 0, 'currency': 'USD', 'max_latency_ms': 1000, 'max_provider_calls': 0}


def rebuild():
    from importlib.util import spec_from_file_location, module_from_spec
    spec = spec_from_file_location('evaluation_gate_inputs', h.ROOT / 'tools/platform_gate.py')
    gate = module_from_spec(spec)
    sys.modules[spec.name] = gate
    spec.loader.exec_module(gate)
    refs = []
    for host, profile, task, stimulus in (
            ('teacher', 'generic-teacher/v1', 'technical-lesson-read',
             {'trusted_text': 'Read the permitted synthetic lesson context.',
              'lesson_ref': 'synthetic-lesson-1', 'material_ref': 'synthetic-material-1',
              'permitted_tool': 'material.read', 'model_transport': 'deterministic-fake/v1',
              'expected_response': 'Synthetic material reference retained; no pedagogical claim.'}),
            ('support', 'generic-support/v1', 'technical-support-read',
             {'trusted_text': 'Read the permitted synthetic support context.',
              'ticket_ref': 'synthetic-ticket-1', 'permitted_tool': 'ticket.read',
              'model_transport': 'deterministic-fake/v1', 'expected_response': 'Synthetic ticket is open.'})):
        input_hash = write(host + '-input.json', stimulus)
        case = {'schema_version': 'evaluation/v1', 'case_id': 'golden.' + host, 'version': '1',
                'task_ref': task, 'profile_ref': profile,
                'input_fixture': {'path': h.FIXTURES + '/' + host + '-input.json', 'sha256': input_hash},
                'expected_invariants': list(h.HARD),
                'scorers': [{'scorer_id': 'technical.hard', 'kind': 'hard', 'config': {
                    'version': '1', 'implementation_hash': h.file_hash(Path(h.__file__))}},
                            {'scorer_id': 'owner.domain', 'kind': 'domain',
                             'config': {'owner_hook_ref': 'host:domain-scorer', 'baseline_ref': None}}],
                'config': {'fixture_only': True, 'runtime_suite': 'knowledge-journey' if host == 'teacher' else 'read-journey'},
                'evidence_access_policy': {'tenant_ref': 'tenant-synthetic', 'audience_ref': 'synthetic-reader',
                    'classification': 'synthetic', 'allowed_evidence_refs': ['synthetic:' + host], 'personal_data_allowed': False},
                'budget': budget()}
        h.validate('case', case)
        write(host + '-case.json', case)
        observation = {'tenant_ref': 'tenant-synthetic', 'audience_ref': 'synthetic-reader',
                       'invariants': dict.fromkeys(h.HARD, True),
                       'metrics': dict.fromkeys(h.METRICS, None),
                       'usage': {'cost_minor': 0, 'currency': 'USD', 'latency_ms': 0, 'provider_calls': 0},
                       'evidence_refs': [{'ref': 'synthetic:' + host, 'sha256': input_hash,
                         'tenant_ref': 'tenant-synthetic', 'audience_ref': 'synthetic-reader', 'redacted': True}]}
        write(host + '-observation.json', observation)
        suite = {'schema_version': 'evaluation/v1', 'suite_id': 'technical-' + host, 'version': '1',
                 'case_refs': [{'case_id': case['case_id'], 'path': h.FIXTURES + '/' + host + '-case.json',
                                'case_hash': h.content_hash(case)}],
                 'required_scorers': ['technical.hard'],
                 'corpus_release_refs': [{'ref': case['input_fixture']['path'], 'sha256': input_hash}],
                 'integration_refs': []}
        write(host + '-suite.json', suite)
        run = run_for(h.content_hash(suite), profile, task)
        run['material_corpus_hash'] = h.content_hash(suite['corpus_release_refs'])
        run = h.seal_run(run)
        write(host + '-run.json', run)
        result = h.score(case, run, observation)
        write(host + '-result.json', result)
        for domain, name in ((False, 'qualification'), (True, 'insufficient-evidence')):
            q = h.qualify(suite, [case], run, [result], {k: run[k] for k in h.TUPLE_FIELDS},
                          qualification_id='synthetic-' + host + '-' + name, issued_at=run['started_at'],
                          rollout_policy_ref='synthetic-disabled-production', domain=domain)
            write(host + '-' + name + '.json', q)
        refs.extend(suite['case_refs'])
    # These are links to existing suites, not replacement implementations or imported PASS claims.
    upstream = {('voice-journey', 'e2e'): ('P11.3', '003b6be9c7d387883e9928a25ee75771c3659e88b38995ffe8062632ad3e1437'),
                ('provider-fallback', 'test'): ('P9.5', '76eb0dc5a3e484b9adafbbe15d1e862479c91e2fb1a9f116e401a9083ea2e00d')}
    for suite_id, integrations in (
            ('technical-golden', [('knowledge-journey', 'e2e'), ('read-journey', 'e2e'), ('second-host', 'test')]),
            ('evaluation-faults', [('provider-fallback', 'test'), ('voice-journey', 'e2e')])):
        linked = []
        for key in integrations:
            item, sha = upstream.get(key, (None, None))
            linked.append({'suite': key[0], 'partition': key[1],
                           'required_case_ids': list(gate.SUITES[key].cases),
                           'source_receipt_ref': '@plan/artifacts/implementation/' + item + '-receipt.json' if item else None,
                           'source_receipt_sha256': 'sha-256:' + sha if sha else None})
        suite = {'schema_version': 'evaluation/v1', 'suite_id': suite_id, 'version': '1',
                 'case_refs': refs, 'required_scorers': ['technical.hard'],
                 'corpus_release_refs': [{'ref': r['path'], 'sha256': h.file_hash(h.ROOT / r['path'])} for r in refs],
                 'integration_refs': linked}
        h.validate('suite', suite)
        write(suite_id + '-suite.json', suite)


if __name__ == '__main__':
    rebuild()
