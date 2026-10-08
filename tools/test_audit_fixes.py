"""Regression tests for audit findings in verification infrastructure."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

try:
    from . import mutation_gate as mutation
    from . import private_release_gate as release
except ImportError:
    import mutation_gate as mutation
    import private_release_gate as release

ROOT = Path(__file__).resolve().parents[1]


class AuditInfrastructureTests(unittest.TestCase):
    def test_release_capture_preserves_matrix_digest_until_inputs_change(self):
        with tempfile.TemporaryDirectory() as directory:
            artifact = Path(directory) / 'matrix.json'
            finding = Path(directory) / 'matrix.md'
            matrix = release.ra.build_matrix()
            artifact.write_text(release.ra.canonical_json(matrix) + '\n')
            original = artifact.read_bytes()
            updated = dict(matrix, generated_at='2099-01-01T00:00:00Z')
            with patch.object(release.ra, 'ARTIFACT', artifact), patch.object(release.ra, 'FINDING', finding), patch.object(release.ra, 'build_matrix', return_value=updated):
                release.pin_acceptance_matrix()
                self.assertEqual(artifact.read_bytes(), original)
                updated['source_commit'] = 'changed-input'
                release.pin_acceptance_matrix()
                self.assertEqual(json.loads(artifact.read_text())['source_commit'], 'changed-input')
                self.assertTrue(finding.is_file())

    def test_dependency_failure_is_not_a_killed_mutant(self):
        specimen = mutation.patches()[0].copy()
        original = (ROOT / specimen['path']).read_bytes()
        specimen['anchor_sha256'] = mutation.sha256_bytes(original)
        with tempfile.TemporaryDirectory() as directory:
            tree = Path(directory)
            source = tree / specimen['path']
            source.parent.mkdir(parents=True)
            source.write_bytes(original)
            failure = subprocess.CompletedProcess([], 2, '', 'dependency unavailable')
            with patch.object(mutation, 'run_cmd', return_value=failure):
                result = mutation.run_patch(specimen, workspace=tree)
            self.assertEqual(result['outcome'], 'baseline-failed')
            self.assertFalse(result['killed'])
            self.assertEqual(original, source.read_bytes())

    def test_structured_assertion_is_required_for_mutation_kill(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / 'junit.xml'
            proc = subprocess.CompletedProcess([], 1, '', '')
            self.assertFalse(mutation.assertion_failure(proc, report))
            report.write_text('<testsuites><testsuite><testcase><error>dependency unavailable</error></testcase></testsuite></testsuites>')
            self.assertFalse(mutation.assertion_failure(proc, report))
            report.write_text('<testsuites><testsuite><testcase><failure>expected refusal</failure></testcase></testsuite></testsuites>')
            self.assertTrue(mutation.assertion_failure(proc, report))
            for assertion, expected in [(False, False), (True, True)]:
                output = '\n'.join(json.dumps(e) for e in [{'type': 'error', 'isFailure': assertion}, {'type': 'done', 'success': False}])
                self.assertEqual(mutation.assertion_failure(subprocess.CompletedProcess([], 1, output, ''), None), expected)

    def test_generator_preserves_external_blocks_when_source_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            tree = Path(directory)
            (tree / 'scripts').mkdir()
            (tree / '.ai/guidelines').mkdir(parents=True)
            shutil.copy2(ROOT / 'scripts/build-agent-docs.sh', tree / 'scripts/build-agent-docs.sh')
            source = tree / '.ai/guidelines/agent-entrypoint.md'
            source.write_text('# First\n')
            command = ['bash', 'scripts/build-agent-docs.sh']
            subprocess.run(command, cwd=tree, check=True)
            suffix = '\n<!-- swissknifeman:hub:start -->\nexternal bytes\n<!-- swissknifeman:hub:end -->\n<!-- maind:healthcheck hash=test -->\nmanaged\n<!-- /maind:healthcheck -->\n'
            target = tree / 'CLAUDE.md'
            target.write_text(target.read_text() + suffix)
            source.write_text('# Second\n')
            subprocess.run(command, cwd=tree, check=True)
            rendered = target.read_bytes()
            self.assertTrue(rendered.endswith(suffix.encode()))
            self.assertIn(b'# Second', rendered)
            subprocess.run(command, cwd=tree, check=True)
            self.assertEqual(rendered, target.read_bytes())
            subprocess.run(command + ['--check'], cwd=tree, check=True)

    def test_rc_bootstraps_all_dependencies_and_live_storage(self):
        bootstrap = (ROOT / 'scripts/ci-bootstrap.sh').read_text()
        for requirement in ['--working-dir=php', '--working-dir=conformance', 'dart/agentools && dart pub get', 'dart/agentools_mcp_dart && dart pub get', 'npm ci --ignore-scripts']:
            self.assertIn(requirement, bootstrap)
        for workflow in ['private-rc.yml', 'extended.yml']:
            text = (ROOT / '.github/workflows' / workflow).read_text()
            self.assertLess(text.index('bash scripts/ci-bootstrap.sh'), text.index('python3 tools/ci_gate.py --all'))
            self.assertIn('bash scripts/ci-postgres.sh', text)
            self.assertIn('python3 tools/storage_chaos.py run', text)
