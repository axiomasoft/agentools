#!/usr/bin/env python3
"""Negative fixtures for the owner-table/container boundary, no live owners required."""
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tools'))
import platform_gate as gate


class BoundaryTest(unittest.TestCase):
    def test_ports_and_direct_owner_imports(self):
        allowed = r"<?php namespace AgenToolsPlatform\Laravel\Host; use AgenToolsPlatform\Core\Common\Ref; interface Port { public function read(Ref $ref): Ref; }"
        self.assertEqual(gate.host_source_errors(ROOT, allowed, 'allowed.php'), [])
        public_owner_api = r"<?php namespace AgenToolsPlatform\Laravel\Host; use Chatom\Contracts\HistorySource;"
        self.assertEqual(gate.host_source_errors(ROOT, public_owner_api, 'public-owner-api.php'), [])
        denied = (
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Edufy\Models\EducationSession as Record;",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Chatom\Models\Message;",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Illuminate\Database\Eloquent\Model as Record;",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Illuminate\Support\Facades\DB as Store;",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; new \PDO('host');",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; app('owner')->table('reports');",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Illuminate\Container\Container as Services;",
            r"<?php namespace AgenToolsPlatform\Laravel\Host; use Illuminate\{Database\Connection, Container\Container};",
        )
        for source in denied:
            with self.subTest(source=source):
                self.assertTrue(gate.host_source_errors(ROOT, source, 'denied.php'))
        sources = sorted((ROOT / 'packages/agentools-laravel/src/Host').glob('*.php'))
        self.assertTrue({'Boundary.php', 'ContextState.php', 'EducationApi.php', 'Envelope.php',
                         'HostOwner.php', 'HostRequest.php', 'ReportAdapter.php', 'ReportEvidence.php'}
                        .issubset({path.name for path in sources}))
        for path in sources:
            self.assertEqual(gate.host_source_errors(ROOT, path.read_text(), str(path)), [])
        # Exercise the global guard on a tiny synthetic tree, not the acceptance candidate.
        sys.path.insert(0, str(ROOT))
        from tools.tests.test_platform_gate import GateTests
        with GateTests().tree() as root:
            package = root / 'packages/agentools-laravel'
            host = package / 'src/Host'
            host.mkdir(parents=True)
            (package / 'composer.json').write_text(json.dumps({
                'name': 'agentools/platform-laravel', 'license': 'proprietary',
                'autoload': {'psr-4': {'AgenToolsPlatform\\Laravel\\': 'src/'}},
                'require': {'php': '>=8.3'}}))
            source = host / 'Port.php'
            source.write_text(allowed)
            self.assertEqual(len(gate.check_boundaries(root)['cases']), 4)
            source.write_text(denied[0])
            with self.assertRaisesRegex(gate.GateError, 'host direct owner table/container dependency'):
                gate.check_boundaries(root)


if __name__ == '__main__':
    result = unittest.TextTestRunner(stream=sys.stderr).run(unittest.defaultTestLoader.loadTestsFromTestCase(BoundaryTest))
    ok = result.wasSuccessful() and result.testsRun == 1 and not result.skipped
    print(json.dumps({'mode': 'contract-scripted', 'tier': 'contract-scripted',
                      'cases': [{'id': 'host.no-direct-owner-access', 'status': 'passed' if ok else 'failed'}],
                      'count': 1, 'failures': 0 if ok else 1, 'skips': 0}))
    sys.exit(0 if ok else 1)
