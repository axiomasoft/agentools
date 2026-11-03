#!/usr/bin/env python3
"""Materialize immutable read-only review inputs. Never dispatches review, mutates Planner or records PASS."""
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[3]
PLAN = Path('plans/2026.10.30-№1-AGENTOOLS-PLATFORM')
STATE = Path('/home/vostrikov/.local/share/swissknifeman/workspaces/axioma/projects/agentools/local/state/orch/conductor')
CHECKS = {'P9.1': ['V1', 'V2'], 'P9.2': ['V1', 'V2', 'V5'], 'P9.3': ['V1', 'V2', 'V3'], 'P9.4': ['V1', 'V2', 'V3']}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def json_bytes(value):
    return (json.dumps(value, indent=2, ensure_ascii=False) + '\n').encode()


class Bundle:
    def __init__(self, root):
        self.root = Path(root)
        self.entries = {}
        self.payload = {}
        self.cache = {}
        self.missing = []
        self.pin_drift = []
        self.source_drift = []

    def resolve(self, path):
        return self.root / str(path).replace('@plan/', str(PLAN) + '/', 1) if not Path(path).is_absolute() else Path(path)

    def read(self, path):
        name = str(path)
        if name not in self.cache:
            p = self.resolve(path)
            self.cache[name] = p.read_bytes() if p.is_file() else None
        return self.cache[name]

    def add(self, path, role, required=True, expected=None):
        name = str(path)
        data = self.read(path)
        if data is None:
            if required:
                self.missing.append({'path': name, 'role': role})
            return None
        sha = digest(data)
        if expected is not None and sha != expected:
            self.pin_drift.append({'path': name, 'role': role, 'expected': expected, 'actual': sha})
        basename = re.sub(r'[^A-Za-z0-9_.-]', '_', Path(name).name)
        member = 'inputs/' + sha + '/' + basename
        self.payload[member] = data
        if name not in self.entries:
            self.entries[name] = {'source': name, 'member': member, 'sha256': sha, 'bytes': len(data), 'roles': [], 'full_content_read_required': True}
        if role not in self.entries[name]['roles']:
            self.entries[name]['roles'].append(role)
        return data

    def generated(self, name, value, role):
        data = json_bytes(value)
        member = 'generated/' + name
        self.payload[member] = data
        self.entries[member] = {'source': 'generated from actual read-only inputs', 'member': member,
                                'sha256': digest(data), 'bytes': len(data), 'roles': [role], 'full_content_read_required': True}

    def manifest(self, path, role, expected=None):
        data = self.add(path, role, expected=expected)
        if data is None:
            return
        values = json.loads(data)
        if not isinstance(values, dict) or not values:
            self.missing.append({'path': str(path), 'role': 'nonempty full source manifest'})
            return
        # Original bytes remain intact. Compare only explicit path->SHA entries; never refresh a historical manifest.
        for source, wanted in values.items():
            if isinstance(wanted, str) and re.fullmatch('[0-9a-f]{64}', wanted):
                current = self.read(source)
                actual = None if current is None else digest(current)
                if actual != wanted:
                    self.source_drift.append({'manifest': str(path), 'source': source, 'historical_sha256': wanted, 'current_sha256': actual})

    def write(self, out, candidate, limitations, upstream):
        out = Path(out)
        if not out.resolve().is_relative_to((self.root / PLAN / 'artifacts/implementation/P9.5').resolve()):
            raise ValueError('Review output must stay inside the current P9.5 artifact directory')
        out.mkdir(parents=True, exist_ok=True)
        entries = sorted(self.entries.values(), key=lambda e: e['member'])
        for i, entry in enumerate(entries, 1):
            entry['read_id'] = f'R{i:04d}'
        manifest = {'format': 'provider-review-inputs/v1', 'inputs': entries, 'missing': self.missing,
                    'pinned_artifact_drift': self.pin_drift, 'historical_source_drift': self.source_drift}
        self.payload['input-index.json'] = json_bytes(manifest)
        archive = out / 'review-inputs.tar'
        with tarfile.open(archive, 'w') as tar:
            for member, data in sorted(self.payload.items()):
                info = tarfile.TarInfo(member)
                info.size = len(data)
                info.mode = 0o444
                tar.addfile(info, io.BytesIO(data))
        archive_bytes = archive.read_bytes()
        status = 'blocked_missing_exact_inputs' if self.missing or self.pin_drift else 'materialized_pending_coordinator_review'
        handoff = {'format': 'provider-review-handoff/v1', 'status': status, 'current_P9_5_candidate': candidate,
                   'historical_native_candidates': upstream, 'archive': {'path': str(archive.relative_to(self.root)), 'sha256': digest(archive_bytes), 'bytes': len(archive_bytes)},
                   'input_index': manifest, 'reviewer': 'grok/grok-4.7/high', 'reviewer_dispatched': False,
                   'review_rounds_max': 1, 'corrections_max': 1, 'rereview_allowed': False,
                   'content_consumption': {'reviewer_read_proof': 'not_yet_available',
                       'required': 'READ COMPLETE CONTENTS of every indexed receipt, original/correction manifest, native envelope/log and inherited limits through native stream tools; hashing/listing/references are not proof.',
                       'suggested_chunk_bytes': 4096, 'verdict_requires': 'List read_ids and actual native stream tool-result identifiers proving complete reads; identify any unread bytes and do not claim independent approval for deterministic fixes.'}}
        (out / 'review-handoff.json').write_bytes(json_bytes(handoff))
        (out / 'review-limitations.json').write_bytes(json_bytes(limitations))
        return handoff


def log_reports(data):
    """Extract actual structured case/isolation reports where present, retaining the complete raw log separately."""
    try:
        value = json.loads(data)
    except (ValueError, TypeError):
        return []
    found = []

    def walk(node):
        if isinstance(node, dict):
            if isinstance(node.get('cases'), list) and node['cases']:
                found.append({k: node[k] for k in ('mode', 'tier', 'cases', 'count', 'failures', 'skips', 'isolation', 'limitations') if k in node})
            for key, item in node.items():
                if key == 'stdout' and isinstance(item, str):
                    found.extend(log_reports(item))
                elif isinstance(item, (dict, list)):
                    walk(item)
        elif isinstance(node, list):
            for item in node:
                walk(item)
    walk(value)
    unique = {}
    for report in found:
        unique.setdefault(digest(json_bytes(report)), report)
    return list(unique.values())


def build(out):
    bundle = Bundle(ROOT)
    requirements_path = PLAN / 'artifacts/autopilot/BP9-2-reviewer-input-requirements.json'
    requirements_data = bundle.add(requirements_path, 'mandatory-review-requirements', expected='388a71b2396e3ac14e0b3dccd1878e637587210f82e6eb456c302c9cc6386545')
    requirements = json.loads(requirements_data) if requirements_data else {}
    upstream = {}
    cases = {}
    import sys
    sys.path.insert(0, str(ROOT / 'tools'))
    import platform_gate
    suites = {'P9.1': 'deployment-routing', 'P9.2': 'usage-admission', 'P9.3': 'usage-reservations', 'P9.4': 'cache-manager'}
    for item, required_checks in CHECKS.items():
        command = ['python3', '-I', '.swissknifeman/launcher.py', 'planner', 'get', 'PLAN2', item, '--fields', 'id,status,result']
        process = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=True)
        envelope = json.loads(process.stdout)
        if not envelope.get('ok'):
            raise RuntimeError('Fresh Planner read failed: ' + item)
        doc = envelope['data']['doc']
        bundle.generated(item + '-fresh-planner.json', envelope, 'fresh-native-registered-result')
        if doc['status'] not in ('done', 'done_with_deviations'):
            bundle.missing.append({'path': item, 'role': 'accepted native result'})
        receipt = PLAN / f'artifacts/implementation/{item}-receipt.json'
        bundle.add(receipt, 'complete-original-implementation-receipt')
        item_dir = ROOT / PLAN / 'artifacts/implementation' / item
        for path in sorted(item_dir.rglob('*')):
            if not path.is_file() or path.suffix not in ('.json', '.log', '.txt'):
                continue
            relative = path.relative_to(ROOT)
            if 'source' in path.name and ('hash' in path.name or 'manifest' in path.name):
                bundle.manifest(relative, 'complete-original-or-correction-source-manifest')
            else:
                bundle.add(relative, 'actual-focused-or-correction-evidence')
        # Preserve all exact pins from the owner readiness; an unavailable/mismatching pin blocks the sole review.
        for record in requirements.get('existing_verified_inputs', {}).get(item, {}).get('source_manifests', []):
            if 'source-hashes' in record['path']:
                bundle.manifest(record['path'], 'readiness-pinned-source-manifest', record['sha256'])
            else:
                bundle.add(record['path'], 'readiness-pinned-receipt', expected=record['sha256'])
        for record in requirements.get('existing_verified_inputs', {}).get(item, {}).get('checks', []):
            bundle.add(record['path'], 'readiness-pinned-native-engine-envelope', expected=record['sha256'])
        result_checks = {row['id']: row for row in doc['result'].get('checks', [])}
        upstream[item] = []
        cases[item] = {}
        for check in required_checks:
            registered = result_checks.get(check)
            if registered is None:
                bundle.missing.append({'path': item + ':' + check, 'role': 'native registered check'})
                continue
            raw = bundle.add(registered['evidence'], 'complete-native-engine-envelope')
            if raw is None:
                continue
            native = json.loads(raw)
            if native.get('subject') != 'PLAN2.' + item or native.get('check_id') != check or native.get('exit') != 0:
                bundle.missing.append({'path': registered['evidence'], 'role': 'correct native accepted check identity/outcome'})
            identity = native['evidence_id'].split(':')
            run = identity[0]
            round_number = identity[2].split('.')[-1]
            log_path = STATE / run / item / 'checks' / round_number / (check + '.log')
            log = bundle.add(log_path, 'complete-actual-native-check-log')
            cases[item][check] = {'command': native['procedure'], 'native_candidate': native['candidate'],
                                 'reports': log_reports(log) if log is not None else [],
                                 'raw_log_member': bundle.entries.get(str(log_path), {}).get('member'),
                                 'required_case_provenance': 'Owning native gate/command on its recorded candidate; raw output retained, no historical PASS transferred to current candidate.'}
            if check in ('V1', 'V3'):
                suite = platform_gate.SUITES[(suites[item], 'pg-test' if check == 'V3' else 'test')]
                wanted = list(suite.cases)
                actual = [[row['id'] for row in report['cases']] for report in cases[item][check]['reports']]
                cases[item][check]['current_required_case_set'] = wanted
                cases[item][check]['historical_actual_matches_current_required'] = any(sorted(ids) == sorted(wanted) for ids in actual)
                cases[item][check]['required_set_scope'] = 'Current preserved owning registry; native actual reports and historical source hash remain separate evidence.'
            elif check == 'V5':
                text = log.decode(errors='replace') if log else ''
                cases[item][check]['required_predicates'] = ['dart analyze --fatal-infos', 'dart test']
                cases[item][check]['toolchain_observations'] = {'no_issues_marker': 'No issues found' in text, 'all_tests_passed_marker': 'All tests passed!' in text,
                    'native_exit': native['exit'], 'case_format': 'Toolchain output, not a registered platform case suite; complete raw log is mandatory provenance.'}
            upstream[item].append({'check': check, 'exit': native['exit'], 'candidate': native['candidate'], 'evidence_id': native['evidence_id']})
            for path in sorted((STATE / run / item / 'checks').glob('*/V*.log')):
                bundle.add(path, 'native-all-check-rounds-including-red-check-fixes')
    for name in ['BP9-1-upstream-evidence-index.json', 'BP9-2-upstream-evidence-index.json']:
        data = bundle.add(PLAN / 'artifacts/autopilot' / name, 'inherited-evidence-index')
        if data is None:
            continue
        index = json.loads(data)
        if name.startswith('BP9-1'):
            for record in index.get('historical_review_sources', []):
                bundle.add(record['path'], 'historical-original-review-or-triage-source', expected=record.get('sha256'))
            for item in ['P7.6', 'P7.8', 'P8.1', 'P8.2', 'P8.3', 'P8.4']:
                info = index[item]
                if 'receipt' in info:
                    bundle.add(info['receipt']['path'], 'inherited-complete-implementation-receipt')
                if 'source_manifest' in info:
                    bundle.manifest(info['source_manifest']['path'], 'inherited-complete-historical-source-manifest')
    process = subprocess.run(['python3', '-I', '.swissknifeman/launcher.py', 'planner', 'get', 'PLAN2', 'knowledge/p9-bp91-readiness-20261102'], cwd=ROOT, text=True, capture_output=True, check=True)
    bundle.generated('inherited-P8-audit.json', json.loads(process.stdout), 'complete-inherited-audit-with-restored-red-and-coverage-gap')
    bundle.generated('native-actual-case-and-isolation-reports.json', cases, 'actual-historical-case-sets-and-isolation-provenance')
    own_manifest = Path(out) / 'source-hashes.json'
    for path in [PLAN / 'artifacts/implementation/P9.5-receipt.json', Path(out) / 'focused-checks.json']:
        bundle.add(path, 'current-P9.5-implementation-and-focused-evidence')
    own_data = bundle.add(own_manifest, 'complete-current-P9.5-source-manifest')
    if own_data is not None:
        own_hashes = json.loads(own_data)
        bundle.generated('P9.5-current-required-cases.json', {'cases': list(platform_gate.PROVIDER_FALLBACK_CASES), 'acceptance': 'not_run_by_executor'}, 'current-required-case-set-not-an-execution-claim')
        for path, wanted in own_hashes.items():
            data = bundle.add(path, 'current-candidate-source')
            if data is not None and digest(data) != wanted:
                bundle.pin_drift.append({'path': path, 'role': 'current-candidate-source', 'expected': wanted, 'actual': digest(data)})
    limitations = {
        'historical_PASS_is_not_current_PASS': True, 'reviewer_full_content_reads': 'required, not yet performed',
        'P8_original_review': 'Original P8 RED is retained. P8.4 F1 triage rejected the finding because both keys crash on reviewed bytes; V1 used real isolated PostgreSQL. Triager direct PHPUnit lacked credentials and was not runtime PASS.',
        'P8_read_gap': 'Historical P8.1/P8.2/P8.3 source manifests were not fully read by the P8 reviewer; include their full bytes here, do not hide or repeat the gap.',
        'P7': 'Original restored P7.6 RED and P7.8 reconciliation remain historical; F1/F2 deterministic fixes have no independent re-review.',
        'inherited': ['P2.4 ManualClock fractional time', 'P3.5 audit/reset', 'P6.5 host prepare/dual-dispatch', 'P4.4/P5.2 historical review/correction limits'],
        'evidence_tiers': 'Scripted/local/isolated-PG evidence is not live/provider/pricing/domain-expert/production/public qualification.',
        'D8': 'No classical mutation tools, mutants or score; fault/crash/recovery/security/e2e/parity remain.',
        'missing_exact_inputs': bundle.missing, 'pinned_artifact_drift': bundle.pin_drift,
        'historical_source_drift': bundle.source_drift,
    }
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    candidate = {'head': head, 'identity_kind': 'executor-dependency-snapshot', 'native_candidate': 'pending_conductor_registration',
                 'acceptance': 'not_run_by_executor', 'source_manifest': str(own_manifest.relative_to(ROOT)),
                 'dependency_manifest_digest': digest(json.dumps(json.loads(own_data), sort_keys=True, separators=(',', ':')).encode()) if own_data else None}
    return bundle.write(out, candidate, limitations, upstream)


if __name__ == '__main__':
    result = build(ROOT / PLAN / 'artifacts/implementation/P9.5')
    print(json.dumps({'status': result['status'], 'inputs': len(result['input_index']['inputs']),
                      'missing': result['input_index']['missing'], 'pinned_artifact_drift': result['input_index']['pinned_artifact_drift']}, ensure_ascii=False))
