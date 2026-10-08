#!/usr/bin/env python3
"""Cross-check final audit evidence; --baseline is the pre-audit local snapshot."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
AUDIT = Path(__file__).resolve().parent
EVIDENCE = AUDIT / 'evidence'
ARTIFACTS = ROOT / 'verification/artifacts'
CHECKS = '''fix-php-final fix-php83-live-final fix-php83-install fix-phpstan-final
fix-composer-validate fix-dart-analyze-final fix-dart-format fix-mcp-tests-final
fix-mcp-analyze-final fix-python-coherent fix-agent-docs fix-clean-bootstrap
fix-compat-php-lowest-receipt fix-compat-php-latest-final
fix-compat-core-lowest-receipt fix-compat-core-latest-receipt
fix-compat-dart-mcp-lowest-final fix-compat-mcp-latest-receipt
fix-conformance-final fix-adversarial-final fix-mutation-final fix-storage-final
fix-resource-final fix-packages-final fix-osv-final fix-gitleaks-complete
fix-policy-workflows fix-policy-lock fix-ci-matrix fix-release-candidate-final
fix-sbom-final fix-private-rc-complete fix-docs-coherent fix-consumers-coherent
fix-private-release-coherent fix-consumers-complete fix-dossier-complete
fix-acceptance-complete'''.split()


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read(path):
    return json.loads(path.read_text())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', type=Path)
    args = parser.parse_args()
    errors, commands = [], []
    for name in CHECKS:
        record, log = EVIDENCE / (name + '.json'), EVIDENCE / (name + '.log')
        doc = read(record)
        if doc['exit_code'] != 0:
            errors.append('nonzero command: ' + name)
        commands.append(dict(doc, record_sha256=sha(record), log_sha256=sha(log)))

    artifacts = {p.name: read(p) for p in ARTIFACTS.glob('*.json')}
    docs = artifacts['P10-docs.json']
    consumer = artifacts['P10-consumer-acceptance.json']
    dossier = artifacts['P10-release-dossier.json']
    acceptance = artifacts['P10-private-rc-acceptance.json']
    packet = artifacts['P10-g-release-request.json']
    coherence = {
        'docs_to_matrix': docs['matrix_digest'] == sha(ARTIFACTS / 'P10-acceptance-matrix.json'),
        'consumer_to_docs': consumer['p10_docs_digest'] == sha(ARTIFACTS / 'P10-docs.json'),
        'consumer_to_rc': consumer['p9_rc_digest'] == sha(ARTIFACTS / 'P9-private-rc.json'),
        'acceptance_to_dossier': acceptance['dossier']['digest'] == sha(ARTIFACTS / 'P10-release-dossier.json'),
    }
    for key, child in dossier['child_receipts'].items():
        coherence['dossier_to_' + key] = child['digest'] == sha(ROOT / child['path'])
    for name, doc in artifacts.items():
        if name.startswith('P9-compatibility-'):
            coherence[name] = doc['source_locks_unchanged'] and all(c['exit_code'] == 0 for c in doc['checks'])
    p6 = read(ROOT / 'conformance/reports/p6-integrated.json')['namespaces']
    reports = p6['agentools']
    for path_key, digest_key in [
        ('reference_report_2026_08_regression', 'reference_digest_sha256'),
        ('reference_report_2026_09', 'reference_2026_09_digest_sha256'),
        ('php_report_2026_09', 'php_2026_09_digest_sha256'),
        ('dart_report_2026_09', 'dart_2026_09_digest_sha256'),
    ]:
        coherence[path_key] = reports[digest_key] == sha(ROOT / reports[path_key])
    for name in ['jcs_oracle', 'semantic_profile', 'mcp_official']:
        coherence[name] = p6[name]['digest_sha256'] == sha(ROOT / p6[name]['report'])
    errors.extend('digest/check mismatch: ' + key for key, valid in coherence.items() if not valid)

    preserved = None
    if args.baseline:
        baseline = read(args.baseline)
        paths = [entry[3:] for entry in baseline['status'].split('\0') if entry and entry[:2] != '??' and entry[3:] in baseline['hashes']]
        changed = []
        for rel in paths:
            path = ROOT / rel
            current = sha(path) if path.is_file() else None
            if current != baseline['hashes'][rel]:
                changed.append(rel)
        preserved = {'initial_dirty_tracked_paths_checked': len(paths), 'changed': changed}
        errors.extend('preexisting work changed: ' + path for path in changed)
    frozen = subprocess.check_output(['git', 'diff', '--name-only', '--', 'spec/revisions/2026-08', 'fixtures/2026-08'], cwd=ROOT, text=True).splitlines()
    errors.extend('frozen revision changed: ' + path for path in frozen)
    subprocess.run(['git', 'diff', '--check', '--', ':!plans', ':!CLAUDE.md', ':!AGENTS.md'], cwd=ROOT, check=True)
    for name in ['fixes.md', 'README.md', 'recommendations.md']:
        path = AUDIT / name
        for target in re.findall(r'\]\(([^)]+)\)', path.read_text()):
            if '://' in target or target == 'evidence/fix-verification.json':
                continue
            if not (path.parent / target.split('#')[0]).exists():
                errors.append('missing report link: ' + name + ' -> ' + target)
    if acceptance['verdict'] != 'green' or packet['public_authorized'] is not False or packet['authorized'] is not False:
        errors.append('unexpected private/public authorization verdict')
    output = {
        'schema': 'agentools.audit-fix-verification/v1',
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'source_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
        'scope': 'Current uncommitted source; hosted CI and existing-database migration were not executed.',
        'closed_findings': ['A%02d' % n for n in range(1, 21)],
        'commands': commands,
        'receipt_coherence': coherence,
        'preexisting_work': preserved,
        'frozen_revision_changes': frozen,
        'private_ready': dossier['verdicts']['private_ready'],
        'public_authorized': packet['public_authorized'],
        'errors': errors,
        'verdict': 'green' if not errors else 'red',
    }
    (EVIDENCE / 'fix-verification.json').write_text(json.dumps(output, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'verdict': output['verdict'], 'commands': len(commands), 'coherence_checks': len(coherence), 'preexisting_work': preserved, 'errors': errors}, ensure_ascii=False))
    return bool(errors)


if __name__ == '__main__':
    raise SystemExit(main())
