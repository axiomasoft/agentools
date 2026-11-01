#!/usr/bin/env python3
"""Install the private package closure from source archives, with no development autoload."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[4]

def verify():
    with tempfile.TemporaryDirectory(prefix='agentools_laravel_no_dev_') as directory:
        base = Path(directory)
        for relative in ('packages/agentools-laravel', 'packages/agentools-core',
                         'packages/agentools-postgres', 'packages/agentools-model-adapter', 'php'):
            source, target = ROOT / relative, base / relative
            target.mkdir(parents=True)
            shutil.copyfile(source / 'composer.json', target / 'composer.json')
            shutil.copytree(source / 'src', target / 'src')
            if relative == 'packages/agentools-laravel':
                shutil.copyfile(source / 'composer.lock', target / 'composer.lock')
        package = base / 'packages/agentools-laravel'
        process = subprocess.run(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-scripts'],
            cwd=package, text=True, capture_output=True, timeout=90)
        if process.returncode:
            raise RuntimeError('No-dev Composer installation failed: ' + process.stderr[-3000:])
        process = subprocess.run(['php', str(Path(__file__).with_name('no-dev.php')), str(package)],
            cwd=ROOT, text=True, capture_output=True, timeout=15)
        if process.returncode:
            raise RuntimeError('No-dev smoke failed: ' + process.stderr[-3000:])
        observations = json.loads(process.stdout)
        if observations != {'provider': True, 'default_routes': 0, 'default_host_bindings': False, 'testing_autoload': False}:
            raise RuntimeError('Invalid no-dev observations')
        installed = json.loads((package / 'vendor/composer/installed.json').read_text())['packages']
        if any(any(x in row['name'] for x in ('phpunit', 'phpstan', 'platform-testing')) for row in installed):
            raise RuntimeError('Testing package in production closure')
        return observations

if __name__ == '__main__':
    print(json.dumps(verify()))
