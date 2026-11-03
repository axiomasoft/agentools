#!/usr/bin/env python3
"""Required offline fallback/SDK/cost/probe contracts. Live checks remain explicit pending opt-in."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]


def verify_packet(handoff, input_hashes, root=ROOT):
    if handoff.get('status') != 'materialized_pending_coordinator_review' or handoff.get('reviewer_dispatched') is not False:
        raise RuntimeError('Complete pre-review handoff required; executor cannot dispatch the review')
    index = handoff.get('input_index', {})
    if not index.get('inputs') or index.get('missing') or index.get('pinned_artifact_drift'):
        raise RuntimeError('Exact required review inputs missing/drifted')
    wanted = hashlib.sha256(json.dumps(input_hashes, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    if handoff.get('current_P9_5_candidate', {}).get('dependency_manifest_digest') != wanted:
        raise RuntimeError('Review packet does not match current P9.5 source inputs')
    archive = root / handoff['archive']['path']
    raw = archive.read_bytes()
    if len(raw) != handoff['archive']['bytes'] or hashlib.sha256(raw).hexdigest() != handoff['archive']['sha256']:
        raise RuntimeError('Review archive changed after materialization')
    return {'status': handoff['status'], 'input_count': len(index['inputs']), 'archive_sha256': handoff['archive']['sha256'],
            'reviewer_read_proof': 'not_yet_available', 'historical_pass_is_not_current_pass': True}


def run():
    reports = []
    for name in ('segments', 'cost', 'sdk', 'probe-guards'):
        process = subprocess.run(['php', str(HERE / (name + '.php'))], cwd=ROOT, text=True, capture_output=True, timeout=30)
        sys.stderr.write(process.stderr)
        report = json.loads(process.stdout)
        if process.returncode or not report.get('cases') or report.get('failures') != 0 or report.get('skips') != 0:
            raise RuntimeError('Required provider contract file failed: ' + name)
        reports.append(report)
    sys.path.insert(0, str(ROOT / 'tools'))
    import platform_gate
    rows = [row for report in reports for row in report['cases']]
    handoff = json.loads((ROOT / 'plans/2026.10.30-№1-AGENTOOLS-PLATFORM/artifacts/implementation/P9.5/review-handoff.json').read_text())
    hashes = platform_gate.hashes(ROOT, platform_gate.PROVIDER_FALLBACK_INPUTS)
    return {'mode': 'contract-scripted', 'tier': 'contract-scripted', 'cases': rows, 'count': len(rows), 'failures': 0, 'skips': 0,
            'input_hashes': hashes,
            'review_materialization': verify_packet(handoff, hashes), 'live': json.loads((HERE / 'profiles.json').read_text())['live'],
            'limitations': ['SDK wire checks use scripted HTTP; fallback boundary is in-memory and host must journal disclosure exposure before replacement IO.',
                            'No live provider calls, prices, expert/domain scoring, production or public qualification. Coordinator owns the sole read-only review.']}


if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except Exception as error:
        print(json.dumps({'cases': [], 'error': str(error)}))
        sys.exit(1)
