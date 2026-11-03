#!/usr/bin/env python3
import importlib.util
import json
import hashlib
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('provider_review_packet', HERE / 'review_packet.py')
packet = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packet)
runner_spec = importlib.util.spec_from_file_location('provider_contract_runner', HERE / 'run.py')
runner = importlib.util.module_from_spec(runner_spec)
runner_spec.loader.exec_module(runner)


class ReviewPacketTests(unittest.TestCase):
    def test_complete_bytes_and_no_claimed_read_proof(self):
        with tempfile.TemporaryDirectory(prefix='agentools-review-test-') as directory:
            root = Path(directory)
            content = ('Full historical input: проверка\n' * 3000).encode()
            (root / 'source.json').write_bytes(content)
            bundle = packet.Bundle(root)
            bundle.add('source.json', 'complete-source-manifest', expected=packet.digest(content))
            out = root / packet.PLAN / 'artifacts/implementation/P9.5'
            handoff = bundle.write(out, {'head': 'test-candidate'}, {'historical_PASS_is_not_current_PASS': True}, {})
            self.assertEqual(handoff['status'], 'materialized_pending_coordinator_review')
            self.assertEqual(handoff['content_consumption']['reviewer_read_proof'], 'not_yet_available')
            record = handoff['input_index']['inputs'][0]
            with tarfile.open(out / 'review-inputs.tar') as archive:
                self.assertEqual(archive.extractfile(record['member']).read(), content)
            self.assertEqual(record['bytes'], len(content))
            self.assertFalse(handoff['reviewer_dispatched'])

    def test_missing_and_changed_exact_pin_block(self):
        with tempfile.TemporaryDirectory(prefix='agentools-review-test-') as directory:
            root = Path(directory)
            (root / 'receipt.json').write_text('{}')
            bundle = packet.Bundle(root)
            bundle.add('receipt.json', 'pinned-receipt', expected='0' * 64)
            bundle.add('missing.json', 'native-engine-envelope')
            result = bundle.write(root / packet.PLAN / 'artifacts/implementation/P9.5', {}, {}, {})
            self.assertEqual(result['status'], 'blocked_missing_exact_inputs')
            self.assertEqual(len(result['input_index']['missing']), 1)
            self.assertEqual(len(result['input_index']['pinned_artifact_drift']), 1)

    def test_source_drift_does_not_rewrite_history(self):
        with tempfile.TemporaryDirectory(prefix='agentools-review-test-') as directory:
            root = Path(directory)
            original = {'code.php': packet.digest(b'historical bytes')}
            (root / 'hashes.json').write_text(json.dumps(original))
            (root / 'code.php').write_bytes(b'current bytes')
            bundle = packet.Bundle(root)
            bundle.manifest('hashes.json', 'historical-source-manifest')
            self.assertEqual(len(bundle.source_drift), 1)
            self.assertEqual(json.loads((root / 'hashes.json').read_text()), original)
            with self.assertRaises(ValueError):
                bundle.write(root / 'outside-current-output', {}, {}, {})

    def test_actual_nested_case_and_isolation_reports(self):
        report = {'cases': [{'id': 'case-1', 'status': 'passed'}], 'isolation': {'engine': 'postgresql', 'isolated': True}}
        raw = json.dumps({'report': {'stdout': json.dumps(report)}})
        self.assertEqual(packet.log_reports(raw), [report])
        self.assertEqual(packet.log_reports('unstructured historical output retained raw'), [])

    def test_packet_current_inputs_and_archive_integrity(self):
        with tempfile.TemporaryDirectory(prefix='agentools-review-test-') as directory:
            root = Path(directory)
            (root / 'source.php').write_bytes(b'current source')
            hashes = {'source.php': packet.digest(b'current source')}
            candidate = {'dependency_manifest_digest': hashlib.sha256(json.dumps(hashes, sort_keys=True, separators=(',', ':')).encode()).hexdigest()}
            bundle = packet.Bundle(root)
            bundle.add('source.php', 'current-source')
            handoff = bundle.write(root / packet.PLAN / 'artifacts/implementation/P9.5', candidate, {}, {})
            self.assertEqual(runner.verify_packet(handoff, hashes, root)['input_count'], 1)
            with self.assertRaisesRegex(RuntimeError, 'current P9.5 source'):
                runner.verify_packet(handoff, {'source.php': '0' * 64}, root)
            (root / handoff['archive']['path']).write_bytes(b'tampered')
            with self.assertRaisesRegex(RuntimeError, 'archive changed'):
                runner.verify_packet(handoff, hashes, root)

    def test_cli_offline_and_missing_live_authorization(self):
        offline = subprocess.run(['php', str(HERE / 'probe.php'), '--offline'], cwd=packet.ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(offline.returncode, 0, offline.stderr)
        report = json.loads(offline.stdout)
        self.assertEqual(report['provider_calls'], 0)
        self.assertEqual(report['live']['status'], 'pending_opt_in')
        self.assertFalse(report['qualification_claim'])
        refused = subprocess.run(['php', str(HERE / 'probe.php'), '--live'], cwd=packet.ROOT, text=True, capture_output=True, timeout=10)
        self.assertEqual(refused.returncode, 1)
        self.assertIn('PROVIDER_PROBE_REFUSED_OR_UNKNOWN', refused.stderr)


if __name__ == '__main__':
    unittest.main()
