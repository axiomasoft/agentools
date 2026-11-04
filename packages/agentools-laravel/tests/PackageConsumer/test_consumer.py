import unittest
import json
from pathlib import Path
import subprocess
import tempfile
from run import check_closure, consumer_manifest


class ConsumerClosureTest(unittest.TestCase):
    def test_composer_resolves_transitive_dev_package(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            core = root / 'core'
            app = root / 'app'
            consumer = root / 'consumer'
            for path in (core, app, consumer):
                path.mkdir()
            (core / 'composer.json').write_text(json.dumps({'name': 'fixture/core'}))
            (app / 'composer.json').write_text(json.dumps({
                'name': 'fixture/app', 'require': {'fixture/core': 'dev-main'}}))
            manifest = consumer_manifest({'fixture/app': str(app), 'fixture/core': str(core)})
            manifest['repositories'].append({'packagist.org': False})
            def resolve():
                (consumer / 'composer.json').write_text(json.dumps(manifest))
                return subprocess.run(['composer', 'update', '--no-install', '--no-dev', '--no-interaction', '--no-scripts'],
                                      cwd=consumer, text=True, capture_output=True, timeout=30)
            del manifest['require']['fixture/core']
            self.assertNotEqual(0, resolve().returncode)
            manifest['require']['fixture/core'] = 'dev-main'
            result = resolve()
            self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_missing_required_and_development_packages(self):
        check_closure({'agentools/platform-core'}, {'agentools/platform-core'})
        with self.assertRaises(RuntimeError):
            check_closure(set(), {'agentools/platform-core'})
        with self.assertRaises(RuntimeError):
            check_closure({'agentools/platform-core', 'phpunit/phpunit'}, {'agentools/platform-core'})
        with self.assertRaises(RuntimeError):
            check_closure({'agentools/platform-core', 'filament/filament'}, {'agentools/platform-core'})


if __name__ == '__main__':
    unittest.main()
