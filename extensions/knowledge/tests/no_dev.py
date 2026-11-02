#!/usr/bin/env python3
"""Prove the optional extension's production closure without testing/SDK/framework/OCR deps."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent

def verify():
    with tempfile.TemporaryDirectory(prefix='knowledge_no_dev_') as directory:
        target = Path(directory)
        for name in ('composer.json', 'composer.lock'):
            shutil.copyfile(HERE.parent / name, target / name)
        shutil.copytree(HERE.parent / 'src', target / 'src')
        result = subprocess.run(['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts', '--no-plugins'],
            cwd=target, capture_output=True, text=True, timeout=60)
        if result.returncode:
            raise RuntimeError('Production composer install failed: ' + result.stderr)
        php = '''require 'vendor/autoload.php';
if (!class_exists('AgenToolsPlatform\\\\Knowledge\\\\Pipeline')
    || !class_exists('AgenToolsPlatform\\\\Knowledge\\\\Adapters\\\\Postgres\\\\Repository')
    || class_exists('AgenToolsPlatform\\\\Knowledge\\\\Tests\\\\Fixture')
    || class_exists('PHPUnit\\\\Framework\\\\TestCase')
    || class_exists('AgenToolsPlatform\\\\Testing\\\\CaseResult')
    || class_exists('AgenToolsPlatform\\\\Core\\\\Runtime\\\\Kernel')) { exit(1); }
echo json_encode(['knowledge'=>true,'test_autoload'=>false,'sdk'=>false,'framework'=>false]);'''
        result = subprocess.run(['php', '-r', php], cwd=target, capture_output=True, text=True, timeout=20)
        if result.returncode:
            raise RuntimeError('Production autoload boundary failed: ' + result.stderr)
        return json.loads(result.stdout)

if __name__ == '__main__':
    print(json.dumps(verify()))
