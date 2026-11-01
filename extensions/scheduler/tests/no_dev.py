#!/usr/bin/env python3
"""Fresh production source closures: headless without scheduler, scheduler without dev dependencies."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[3]
SOURCES = ('extensions/scheduler', 'packages/agentools-laravel', 'packages/agentools-core',
    'packages/agentools-postgres', 'packages/agentools-model-adapter', 'php')

def verify():
    results = {}
    for mode, entry in (('headless', 'packages/agentools-laravel'), ('adapter', 'extensions/scheduler')):
        with tempfile.TemporaryDirectory(prefix='agentools_scheduler_no_dev_') as directory:
            base = Path(directory)
            for relative in SOURCES:
                source, target = ROOT / relative, base / relative
                target.mkdir(parents=True)
                shutil.copyfile(source / 'composer.json', target / 'composer.json')
                shutil.copytree(source / 'src', target / 'src')
                if relative == entry:
                    shutil.copyfile(source / 'composer.lock', target / 'composer.lock')
            package = base / entry
            process = subprocess.run(['composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-scripts'],
                cwd=package, text=True, capture_output=True, timeout=100)
            if process.returncode:
                raise RuntimeError('No-dev installation failed: ' + process.stderr[-3000:])
            process = subprocess.run(['php', str(Path(__file__).with_name('no-dev.php')), str(package), mode],
                cwd=ROOT, text=True, capture_output=True, timeout=20)
            if process.returncode:
                raise RuntimeError('No-dev smoke failed: ' + process.stderr[-3000:])
            results[mode] = json.loads(process.stdout)
            installed = json.loads((package / 'vendor/composer/installed.json').read_text())['packages']
            if any(any(x in row['name'] for x in ('phpunit', 'phpstan', 'platform-testing', 'cadence', 'filament')) for row in installed):
                raise RuntimeError('Unselected/development dependency in production closure')
    return {**results['headless'], 'scheduler_absent_headless': results['headless']['scheduler'] is False,
        'adapter': results['adapter']}

if __name__ == '__main__':
    print(json.dumps(verify()))
