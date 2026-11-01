#!/usr/bin/env python3
"""Exact durable profile partition; reuse the proven restricted-role PostgreSQL lifecycle."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[3]
spec = importlib.util.spec_from_file_location('profile_pg_lifecycle', HERE.parent / 'run.py')
lifecycle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lifecycle)

CASES = {
    'testLifecycleAndPublishedImmutability': 'profiles.published-immutable',
    'testValidationFailureStaysDraft': 'profiles.validation-failure-draft',
    'testPublishRechecksDependencies': 'profiles.publish-current-dependencies',
    'testIdentityHashAndCasConflict': 'profiles.publication-identity-cas',
    'testLifecycleRetainsHistory': 'profiles.lifecycle-history',
    'testTenantAndCurrentOwner': 'profiles.tenant-current-owner',
    'testSchemaInstallVersioned': 'profiles.schema-version',
    'testNewAssignmentsPreserveActivePin': 'profiles.assignment-new-sessions-only',
    'testAssignmentCasReplayAndConflict': 'profiles.assignment-command-cas',
    'testCurrentGuardsOverrideEveryPin': 'profiles.current-guards-over-pins',
    'testExplicitMigrationWithAuditAndReplay': 'profiles.explicit-migration-audit',
    'testMigrationCompatibilityAndTenantDenied': 'profiles.migration-denial',
    'testConcurrentPublish': 'profiles.concurrent-publish',
    'testConcurrentPin': 'profiles.concurrent-pin',
    'testPublicationCrashRecovery': 'profiles.publish-sigkill-recovery',
    'testPinCrashRecovery': 'profiles.pin-sigkill-recovery',
    'testMigrationCrashRecovery': 'profiles.migration-sigkill-recovery',
    'testMigrationCommitFailureAtomic': 'profiles.migration-commit-rollback',
}

def main():
    with tempfile.TemporaryDirectory(prefix='agentools_profiles_observations_') as directory:
        path = Path(directory) / 'observations.json'
        before = os.environ.get('AGENTOOLS_PROFILE_OBSERVATIONS')
        os.environ['AGENTOOLS_PROFILE_OBSERVATIONS'] = str(path)
        try:
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = lifecycle.main(HERE / 'phpunit.xml', 'profiles', CASES)
            report = json.loads(output.getvalue())
            report['observations'] = json.loads(path.read_text()) if path.exists() else {}
            import platform_gate
            report['input_hashes'] = platform_gate.hashes(ROOT, platform_gate.PROFILE_INPUTS)
            print(json.dumps(report))
            return code
        finally:
            if before is None: os.environ.pop('AGENTOOLS_PROFILE_OBSERVATIONS', None)
            else: os.environ['AGENTOOLS_PROFILE_OBSERVATIONS'] = before

if __name__ == '__main__':
    sys.exit(main())
