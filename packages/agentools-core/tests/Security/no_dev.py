#!/usr/bin/env python3
"""Standalone production install for current boundary/disclosure/worker values and adapters."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[4]
def verify():
    with tempfile.TemporaryDirectory(prefix='agentools_security_no_dev_') as directory:
        target=Path(directory);core=ROOT/'packages/agentools-core'
        for name in ('composer.json','composer.lock'):shutil.copy2(core/name,target/name)
        shutil.copytree(core/'src',target/'src')
        p=subprocess.run(['composer','install','--no-dev','--no-scripts','--no-interaction','--working-dir',str(target)],text=True,capture_output=True,timeout=60)
        sys.stderr.write(p.stdout+p.stderr)
        if p.returncode:raise RuntimeError('Standalone security installation failed')
        code=r"""require 'vendor/autoload.php';
        foreach (['AgenToolsPlatform\\Core\\Security\\BoundaryGuard','AgenToolsPlatform\\Core\\Security\\DisclosureStream',
            'AgenToolsPlatform\\Core\\Security\\WorkerScope','AgenToolsPlatform\\Core\\Security\\LogProjection',
            'AgenToolsPlatform\\Core\\Context\\GuardSnapshot','AgenToolsPlatform\\Core\\Runtime\\GuardedTransport'] as $class) { if (!class_exists($class)) { exit(1); } }
        foreach (['AgenToolsPlatform\\Tests\\Security\\Fixture','AgenToolsPlatform\\Testing\\ScriptedDisclosure',
            'AgenToolsPlatform\\Postgres\\Summaries\\Repository','AgenTools\\Schema\\Canonicalizer',
            'PHPUnit\\Framework\\TestCase','Illuminate\\Support\\ServiceProvider'] as $class) { if (class_exists($class)) { exit(2); } }
        if (!interface_exists('AgenToolsPlatform\\Core\\Security\\BoundaryPolicy') ||
            !interface_exists('AgenToolsPlatform\\Core\\Security\\DisclosureDelivery')) { exit(3); }
        echo json_encode(['standalone_core'=>true,'context_guards'=>true,'testing_autoload'=>false,'sdk_autoload'=>false,'postgres_autoload'=>false,'framework_autoload'=>false]);"""
        p=subprocess.run(['php','-r',code],cwd=target,text=True,capture_output=True,timeout=10);sys.stderr.write(p.stderr)
        if p.returncode:raise RuntimeError('Security production no-dev boundary failed')
        return json.loads(p.stdout)
if __name__=='__main__':
    try:print(json.dumps(verify()))
    except Exception as error:print(json.dumps({'error':str(error)}));sys.exit(1)
