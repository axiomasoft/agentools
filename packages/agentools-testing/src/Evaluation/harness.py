"""Internal, offline technical evaluation. Never executes tools or model output.

Owner supplied domain scorers are data-only hooks here. An absent scorer is
insufficient evidence, never a fabricated educational score.
"""
from __future__ import annotations

from copy import deepcopy
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[4]
SCHEMAS = ROOT / 'spec/platform/v1/evaluation'
FIXTURES = 'fixtures/platform/v1/evaluation'
TIERS = ('design-schema', 'unit', 'contract-scripted', 'isolated-postgres',
         'local-host-e2e', 'live-provider', 'product-pilot')
HARD = ('tenant_isolation', 'audience_isolation', 'permitted_tools_effects',
        'no_duplicates', 'unknown_not_success', 'provenance', 'child_safety_baseline')
METRICS = ('quality', 'grounding', 'cost', 'latency', 'privacy', 'duplicate', 'unknown')
TUPLE_FIELDS = ('deployment_ref', 'model_revision', 'profile_ref', 'profile_bundle_hash',
                'task_ref', 'suite_hash', 'prompt_hash', 'skills_hash', 'tool_schema_hash',
                'material_corpus_hash')


class EvaluationError(ValueError):
    pass


class ImmutableRegistry:
    """Process-local identity guard; durable ownership belongs to the host store."""

    def __init__(self):
        self._entries = {}

    def register(self, tenant_ref, kind, identity, value):
        require(bool(tenant_ref) and kind in ('suite', 'run') and bool(identity), 'invalid registry identity')
        snapshot = deepcopy(value)
        validate(kind, snapshot)
        if kind == 'run':
            verify_run(snapshot, snapshot['suite_hash'])
            require(snapshot['tenant_ref'] == tenant_ref and snapshot['run_id'] == identity,
                    'registry tenant/run identity mismatch')
        else:
            require(snapshot['suite_id'] == identity, 'registry suite identity mismatch')
        key = (tenant_ref, kind, identity, snapshot['version'] if kind == 'suite' else None)
        previous = self._entries.get(key)
        require(previous is None or previous[0] == content_hash(snapshot), 'immutable identity hash conflict')
        self._entries[key] = (content_hash(snapshot), snapshot)
        return deepcopy(snapshot)


def require(condition, message):
    if not condition:
        raise EvaluationError(message)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'),
                      ensure_ascii=False, allow_nan=False).encode('utf-8')


def content_hash(value):
    return 'sha-256:' + hashlib.sha256(canonical(value)).hexdigest()


def file_hash(path):
    return 'sha-256:' + hashlib.sha256(path.read_bytes()).hexdigest()


def validate(kind, value):
    # No remote schema/ref resolution and no optional always-green fallback.
    try:
        from jsonschema import Draft202012Validator, FormatChecker
    except ImportError as error:
        raise EvaluationError('jsonschema dependency missing') from error
    schema = json.loads((SCHEMAS / (kind + '.schema.json')).read_text())
    errors = sorted(Draft202012Validator(schema, format_checker=FormatChecker()).iter_errors(value),
                    key=lambda e: str(list(e.path)))
    require(not errors, f'{kind} schema: ' + '; '.join(e.message for e in errors[:3]))


def local_file(root, reference):
    require(isinstance(reference, str) and reference and not Path(reference).is_absolute(),
            'relative local reference required')
    path = (root / reference).resolve()
    require(path.is_relative_to(root.resolve()) and path.is_file(), 'missing/escaping reference: ' + reference)
    return path


def load_case(root, reference):
    path = local_file(root, reference)
    case = json.loads(path.read_text())
    validate('case', case)
    fixture = local_file(root, case['input_fixture']['path'])
    require(file_hash(fixture) == case['input_fixture']['sha256'], 'stimulus hash mismatch')
    require(set(case['expected_invariants']) == set(HARD), 'all hard invariants required')
    ids = [s['scorer_id'] for s in case['scorers']]
    require(len(set(ids)) == len(ids) and 'technical.hard' in ids, 'missing/duplicate hard scorer')
    require(next(s for s in case['scorers'] if s['scorer_id'] == 'technical.hard')['kind'] == 'hard',
            'hard scorer kind changed')
    require(all(s['kind'] == 'domain' or s['scorer_id'] == 'technical.hard' for s in case['scorers']),
            'unregistered technical scorer')
    scorer = next(s for s in case['scorers'] if s['scorer_id'] == 'technical.hard')
    require(scorer['config'].get('implementation_hash') == file_hash(Path(__file__)),
            'scorer implementation hash mismatch')
    return case


def load_suite(root, reference):
    suite = json.loads(local_file(root, reference).read_text())
    validate('suite', suite)
    cases = []
    for ref in suite['case_refs']:
        case = load_case(root, ref['path'])
        require(ref['case_id'] == case['case_id'] and ref['case_hash'] == content_hash(case),
                'case hash/identity mismatch')
        require(set(suite['required_scorers']) <= {s['scorer_id'] for s in case['scorers']},
                'required scorer absent from case')
        cases.append(case)
    require(len({c['case_id'] for c in cases}) == len(cases), 'duplicate case identity')
    require('technical.hard' in suite['required_scorers'], 'technical hard scorer must be required')
    for corpus in suite['corpus_release_refs']:
        require(file_hash(local_file(root, corpus['ref'])) == corpus['sha256'], 'corpus hash mismatch')
    return suite, cases, content_hash(suite)


def seal_run(run):
    run = deepcopy(run)
    run.pop('run_hash', None)
    run['run_hash'] = content_hash(run)
    validate('run', run)
    return run


def verify_run(run, suite_hash):
    validate('run', run)
    unsigned = {k: v for k, v in run.items() if k != 'run_hash'}
    require(run['run_hash'] == content_hash(unsigned), 'run hash mismatch')
    require(run['suite_hash'] == suite_hash, 'run suite hash mismatch')
    require(run['evidence_kind'] in TIERS, 'noncanonical evidence tier')
    return True


def combine(statuses):
    if 'fail' in statuses:
        return 'fail'
    return 'insufficient_evidence' if any(s != 'pass' for s in statuses) else 'pass'


def score(case, run, observation):
    validate('case', case)
    require(set(case['expected_invariants']) == set(HARD), 'all hard invariants required')
    hard_scorers = [s for s in case['scorers'] if s['scorer_id'] == 'technical.hard']
    require(len(hard_scorers) == 1 and hard_scorers[0]['kind'] == 'hard' and
            hard_scorers[0]['config'].get('implementation_hash') == file_hash(Path(__file__)),
            'hard scorer missing/stale')
    verify_run(run, run['suite_hash'])
    require(case['profile_ref'] == run['profile_ref'] and case['task_ref'] == run['task_ref'],
            'case/run task/profile mismatch')
    require(run['budget']['currency'] == case['budget']['currency'], 'case/run currency mismatch')
    policy = case['evidence_access_policy']
    require(run['tenant_ref'] == policy['tenant_ref'] and run['audience_ref'] == policy['audience_ref'],
            'case/run evidence scope mismatch')
    outputs = []
    refs = []
    reasons = []
    status = 'not_run' if observation is None else 'pass'
    if observation is not None:
        validate('observation', observation)
        # Refuse foreign evidence before retaining/logging payload or references.
        require(observation['tenant_ref'] == policy['tenant_ref'] and
                observation['audience_ref'] == policy['audience_ref'], 'evidence access denied')
        for ref in observation['evidence_refs']:
            require(ref['ref'] in policy['allowed_evidence_refs'] and
                    ref['tenant_ref'] == policy['tenant_ref'] and
                    ref['audience_ref'] == policy['audience_ref'] and ref['redacted'] is True,
                    'evidence access denied')
        refs = deepcopy(observation['evidence_refs'])
        required = set(case['expected_invariants'])
        values = observation['invariants']
        if any(values.get(k) is False for k in required):
            status = 'fail'
            reasons.append('hard_invariant_violated')
        elif not required or any(values.get(k) is not True for k in required) or not refs:
            status = 'insufficient_evidence'
            reasons.append('hard_invariant_evidence_absent')
        limits = (('cost_minor', 'max_cost_minor'), ('latency_ms', 'max_latency_ms'),
                  ('provider_calls', 'max_provider_calls'))
        for measured, bound in limits:
            value = observation['usage'][measured]
            if value is None:
                status = combine([status, 'insufficient_evidence'])
                reasons.append(measured + '_unknown')
            elif value > case['budget'][bound] or value > run['budget'][bound]:
                status = 'fail'
                reasons.append(measured + '_budget_exceeded')
        require(observation['usage']['currency'] == case['budget']['currency'], 'currency mismatch')
    for scorer in case['scorers']:
        domain = scorer['kind'] == 'domain'
        require(domain or scorer['scorer_id'] == 'technical.hard', 'unregistered scorer')
        scorer_status = 'insufficient_evidence' if domain else status
        outputs.append({'scorer_id': scorer['scorer_id'], 'status': scorer_status,
                        'value': None if domain or scorer_status != 'pass' else True,
                        'reason': 'owner_domain_scorer_absent' if domain else ','.join(sorted(reasons)) or status,
                        'config_hash': content_hash(scorer['config'])})
    result = {'schema_version': 'evaluation/v1', 'case_id': case['case_id'],
              'case_hash': content_hash(case), 'run_hash': run['run_hash'],
              'suite_hash': run['suite_hash'], 'status': 'not_run' if observation is None else
              combine([o['status'] for o in outputs]), 'scorer_outputs': outputs,
              'evidence_refs': refs, 'limits': sorted(reasons),
              'metrics': {key: observation['metrics'][key] if observation else None for key in METRICS}}
    result['result_hash'] = content_hash(result)
    validate('result', result)
    return result


def qualify(suite, cases, run, results, current_tuple, *, qualification_id, issued_at,
            rollout_policy_ref, domain=False):
    """Technical certificate only; current owner/emergency authorization stays external."""
    suite_hash = content_hash(suite)
    verify_run(run, suite_hash)
    validate('suite', suite)
    reasons = []
    require('technical.hard' in suite['required_scorers'], 'technical hard scorer must be required')
    for case in cases:
        validate('case', case)
        require(set(case['expected_invariants']) == set(HARD), 'all hard invariants required')
        require(any(s['scorer_id'] == 'technical.hard' and s['kind'] == 'hard' for s in case['scorers']),
                'technical hard scorer missing')
    expected_tuple = {k: run[k] for k in TUPLE_FIELDS}
    if run['evidence_kind'] in ('design-schema', 'unit'):
        reasons.append('runtime_contract_evidence_absent')
    if current_tuple != expected_tuple:
        reasons.append('required_tuple_mismatch')
    refs = suite['case_refs']
    if ([c['case_id'] for c in cases] != [r['case_id'] for r in refs] or
            [content_hash(c) for c in cases] != [r['case_hash'] for r in refs]):
        reasons.append('required_case_hash_mismatch')
    if any(c['profile_ref'] != run['profile_ref'] or c['task_ref'] != run['task_ref'] for c in cases):
        reasons.append('case_run_profile_task_mismatch')
    if run['material_corpus_hash'] != content_hash(suite['corpus_release_refs']):
        reasons.append('required_corpus_hash_mismatch')
    if any(not set(suite['required_scorers']) <= {s['scorer_id'] for s in c['scorers']} for c in cases):
        reasons.append('required_scorer_missing')
    ids = [r.get('case_id') for r in results]
    if len(ids) != len(refs) or set(ids) != {c['case_id'] for c in cases}:
        reasons.append('required_case_set_differs')
    for result in results:
        validate('result', result)
        require(result['result_hash'] == content_hash({k: v for k, v in result.items() if k != 'result_hash'}),
                'result hash mismatch')
        require(result['run_hash'] == run['run_hash'] and result['suite_hash'] == suite_hash,
                'result run/suite mismatch')
        case = next((c for c in cases if c['case_id'] == result['case_id']), None)
        require(case is not None and result['case_hash'] == content_hash(case), 'result case hash mismatch')
        require([o['scorer_id'] for o in result['scorer_outputs']] == [s['scorer_id'] for s in case['scorers']],
                'result scorer set mismatch')
        require(all(o['config_hash'] == content_hash(s['config'])
                    for o, s in zip(result['scorer_outputs'], case['scorers'])), 'scorer config hash mismatch')
    for ref in suite['integration_refs']:
        receipts = [r for r in run['integration_receipts']
                    if (r['suite'], r['partition']) == (ref['suite'], ref['partition'])]
        if not receipts:
            reasons.append('integration_receipt_absent')
        elif (len(receipts) != 1 or receipts[0]['required_case_ids'] != ref['required_case_ids'] or
              receipts[0]['executed_case_ids'] != ref['required_case_ids'] or
              receipts[0]['failures'] != 0 or receipts[0]['skips'] != 0 or
              (ref['partition'] == 'e2e' and receipts[0]['evidence_kind'] != 'local-host-e2e') or
              (ref['partition'] == 'pg-test' and receipts[0]['evidence_kind'] != 'isolated-postgres')):
            reasons.append('integration_receipt_invalid')
    selected = [o['status'] for r in results for o in r['scorer_outputs']
                if domain or o['scorer_id'] in suite['required_scorers']]
    insufficient_reasons = {'integration_receipt_absent', 'runtime_contract_evidence_absent'}
    decision = 'rejected' if any(r not in insufficient_reasons for r in reasons) or 'fail' in selected else (
        'insufficient_evidence' if reasons else
        'qualified' if selected and all(s == 'pass' for s in selected) else 'insufficient_evidence')
    if decision == 'insufficient_evidence':
        reasons.append('required_scorer_evidence_absent')
    certificate = {'schema_version': 'evaluation/v1', 'qualification_id': qualification_id,
                   'tuple': expected_tuple, 'required_suites': [{'suite_id': suite['suite_id'],
                   'version': suite['version'], 'suite_hash': suite_hash}],
                   'result_refs': [r['result_hash'] for r in results], 'decision': decision,
                   'reason': sorted(reasons), 'issued_at': issued_at, 'expires_at': None,
                   'rollout_policy_ref': rollout_policy_ref, 'evidence_kind': run['evidence_kind'],
                   'readiness_claim': 'technical-contract-only'}
    validate('qualification', certificate)
    return certificate


def applicable(certificate, current_tuple):
    validate('qualification', certificate)
    return (certificate['decision'] == 'qualified' and set(current_tuple) == set(TUPLE_FIELDS)
            and certificate['tuple'] == current_tuple)


def validate_claim(evidence_kind, readiness_claim):
    require(evidence_kind in TIERS, 'canonical evidence kind required')
    require(readiness_claim in ('technical-contract-only', 'production'), 'unknown readiness claim')
    require(readiness_claim != 'production' or evidence_kind == 'product-pilot',
            'impossible production claim from technical/fake-only evidence')
    # A pilot tier is necessary, never sufficient: rollout remains owner-controlled.


def validate_integrations(suite, registry):
    """Bind existing runners and exact case sets; never replace old gate validation."""
    require(suite['integration_refs'], 'missing integration manifest')
    keys = []
    for ref in suite['integration_refs']:
        key = (ref['suite'], ref['partition'])
        require(key not in keys and key in registry, 'missing/duplicate integration suite')
        keys.append(key)
        spec = registry[key]
        require(bool(spec.command) and bool(spec.dependencies) and bool(spec.cases) and
                len(set(spec.cases)) == len(spec.cases), 'empty integration runner/cases/dependencies')
        require(list(spec.cases) == ref['required_case_ids'], 'integration exact case set differs')
    return keys
