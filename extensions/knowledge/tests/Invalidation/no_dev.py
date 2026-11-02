#!/usr/bin/env python3
"""Prove invalidation's standalone production autoload with no testing/SDK/framework/provider dependency."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

PACKAGE = Path(__file__).resolve().parents[2]

def verify():
    with tempfile.TemporaryDirectory(prefix='invalidation_no_dev_') as folder:
        target = Path(folder)
        for name in ('composer.json', 'composer.lock'):
            shutil.copyfile(PACKAGE / name, target / name)
        shutil.copytree(PACKAGE / 'src', target / 'src')
        result = subprocess.run(['composer','install','--no-dev','--no-interaction','--no-scripts','--no-plugins'],cwd=target,capture_output=True,text=True,timeout=60)
        if result.returncode:
            raise RuntimeError('Production install failed: ' + result.stderr)
        php = r'''require 'vendor/autoload.php';
foreach (['Node','PostgresGraph','DeletionWorker','DeletionRequest','DeletionResult'] as $name) {
    if (!class_exists('AgenToolsPlatform\\Knowledge\\Dependencies\\'.$name)) { exit(1); }
}
foreach (['AgenToolsPlatform\\Knowledge\\Tests\\Retrieval\\Fixture','PHPUnit\\Framework\\TestCase',
'AgenToolsPlatform\\Testing\\CaseResult','AgenToolsPlatform\\Core\\Runtime\\Kernel','Illuminate\\Support\\ServiceProvider'] as $name) {
    if (class_exists($name)) { exit(2); }
}
echo json_encode(['invalidation'=>true,'test_autoload'=>false,'sdk'=>false,'framework'=>false,'provider'=>false]);'''
        result = subprocess.run(['php','-r',php],cwd=target,capture_output=True,text=True,timeout=20)
        if result.returncode:
            raise RuntimeError('Production autoload failed: ' + result.stderr)
        return json.loads(result.stdout)

if __name__ == '__main__':
    print(json.dumps(verify()))
