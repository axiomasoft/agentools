#!/usr/bin/env python3
"""Fresh standalone core installation; no host/SDK/PostgreSQL/test implementation is pulled in."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[5]
def verify():
    with tempfile.TemporaryDirectory(prefix='agentools_history_no_dev_') as directory:
        target=Path(directory);core=ROOT/'packages/agentools-core'
        for name in ('composer.json','composer.lock'):shutil.copy2(core/name,target/name)
        shutil.copytree(core/'src',target/'src')
        p=subprocess.run(['composer','install','--no-dev','--no-scripts','--no-interaction','--working-dir',str(target)],text=True,capture_output=True,timeout=60)
        sys.stderr.write(p.stdout+p.stderr)
        if p.returncode:raise RuntimeError('Standalone core install failed')
        code=r"""require 'vendor/autoload.php';
        foreach (['AgenToolsPlatform\\Core\\Context\\History\\SummaryRebuilder','AgenToolsPlatform\\Core\\Context\\History\\HistorySelector',
            'AgenToolsPlatform\\Core\\Context\\Memory\\MemoryLifecycle','AgenToolsPlatform\\Core\\Context\\Memory\\MemorySelector'] as $class) { if (!class_exists($class)) { exit(1); } }
        foreach (['AgenToolsPlatform\\Tests\\Context\\History\\Fixture','AgenToolsPlatform\\Testing\\ScriptedModel',
            'AgenToolsPlatform\\Postgres\\Summaries\\Repository','AgenTools\\Schema\\Canonicalizer','PHPUnit\\Framework\\TestCase',
            'Illuminate\\Support\\ServiceProvider'] as $class) { if (class_exists($class)) { exit(2); } }
        if (!interface_exists('AgenToolsPlatform\\Core\\Context\\History\\HistorySource') ||
            !interface_exists('AgenToolsPlatform\\Core\\Context\\Memory\\MemorySource')) { exit(3); }
        echo json_encode(['standalone_core'=>true,'history_memory'=>true,'testing_autoload'=>false,'sdk_autoload'=>false,'postgres_autoload'=>false,'framework_autoload'=>false]);"""
        p=subprocess.run(['php','-r',code],cwd=target,text=True,capture_output=True,timeout=10);sys.stderr.write(p.stderr)
        if p.returncode:raise RuntimeError('History/memory production no-dev boundary failed')
        return json.loads(p.stdout)
if __name__=='__main__':
    try:print(json.dumps(verify()))
    except Exception as error:print(json.dumps({'error':str(error)}));sys.exit(1)
