#!/usr/bin/env python3
"""Fresh root consumer: Composer resolves the private production closure."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[4]
HERE = Path(__file__).resolve().parent

def check_closure(names, required):
    forbidden = {'agentools/platform-testing', 'phpunit/phpunit', 'phpstan/phpstan',
                 'filament/filament', 'agentools/platform-filament', 'agentools/platform-chatom-bridge',
                 'agentools/platform-loqum', 'agentools/platform-scheduler', 'agentools/platform-knowledge'}
    if not required.issubset(names) or names.intersection(forbidden):
        raise RuntimeError('Incorrect production dependency closure')

def consumer_manifest(packages):
    return {'name': 'consumer/private-smoke', 'type': 'project',
        'require': {name: 'dev-main' for name in packages},
        'repositories': [{'type': 'path', 'url': str(ROOT / path),
            'options': {'symlink': False, 'versions': {name: 'dev-main'}}}
            for name, path in packages.items()],
        'config': {'allow-plugins': False}}

def run():
    tests = subprocess.run([sys.executable, '-B', str(HERE / 'test_consumer.py')],
                           text=True, capture_output=True, timeout=30)
    if tests.returncode:
        raise RuntimeError('Consumer negative controls failed: ' + tests.stderr)
    for command in ([sys.executable, '-B', str(ROOT / 'docs/platform/test_export_contract.py')],
                    [sys.executable, '-B', str(ROOT / 'docs/platform/export_contract.py'), '--check']):
        test = subprocess.run(command, text=True, capture_output=True, timeout=30)
        if test.returncode:
            raise RuntimeError('Contract bundle verification failed: ' + test.stderr)
    with tempfile.TemporaryDirectory(prefix='agentools-consumer-') as temp:
        root = Path(temp)
        packages = {
            'agentools/platform-core': 'packages/agentools-core',
            'agentools/platform-postgres': 'packages/agentools-postgres',
            'agentools/platform-model-adapter': 'packages/agentools-model-adapter',
            'agentools/platform-laravel': 'packages/agentools-laravel',
            'agentools/sdk': 'php',
        }
        manifest = consumer_manifest(packages)
        (root / 'composer.json').write_text(json.dumps(manifest))
        p = subprocess.run(['composer', 'update', '--no-dev', '--no-interaction', '--no-scripts', '--prefer-dist'],
                           cwd=root, text=True, capture_output=True, timeout=240)
        if p.returncode:
            raise RuntimeError('Fresh Composer consumer failed: ' + p.stderr[-6000:])
        installed = json.loads((root / 'vendor/composer/installed.json').read_text())['packages']
        names = {row['name'] for row in installed}
        check_closure(names, set(packages))
        p = subprocess.run(['php', str(HERE / 'consumer.php'), str(root)], text=True, capture_output=True, timeout=30)
        if p.returncode or p.stdout.strip() != 'consumer passed':
            raise RuntimeError('Consumer behavior failed: ' + p.stderr + p.stdout)
    return {'mode': 'contract-scripted', 'tier': 'contract-scripted',
            'cases': [{'id': name, 'status': 'passed'} for name in
                      ('consumer.fresh-no-dev', 'consumer.isolation-and-guards', 'consumer.optional-absent',
                       'consumer.negative-controls', 'consumer.contract-bundle')],
            'limitations': ['No external host, live provider, or production deployment is qualified.']}

if __name__ == '__main__':
    try:
        print(json.dumps(run()))
    except (OSError, subprocess.TimeoutExpired, RuntimeError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
