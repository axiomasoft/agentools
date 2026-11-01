#!/usr/bin/env python3
"""Fresh standalone core install: skills require no SDK, PostgreSQL, framework or test owner."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[5]

def verify():
    with tempfile.TemporaryDirectory(prefix='agentools_skills_no_dev_') as directory:
        target=Path(directory)
        core=ROOT/'packages/agentools-core'
        for file in ('composer.json','composer.lock'):shutil.copy2(core/file,target/file)
        shutil.copytree(core/'src',target/'src')
        process=subprocess.run(['composer','install','--no-dev','--no-scripts','--no-interaction','--working-dir',str(target)],text=True,capture_output=True,timeout=60)
        sys.stderr.write(process.stdout+process.stderr)
        if process.returncode:raise RuntimeError('Standalone core production installation failed')
        code=r"""require 'vendor/autoload.php';
            foreach (['AgenToolsPlatform\\Core\\Profiles\\Skills\\Router',
                'AgenToolsPlatform\\Core\\Profiles\\Skills\\Publisher',
                'AgenToolsPlatform\\Core\\Profiles\\Skills\\SkillManifest',
                'AgenToolsPlatform\\Core\\Profiles\\Skills\\ManifestCodec'] as $class) { if (!class_exists($class)) { exit(1); } }
            foreach (['AgenToolsPlatform\\Tests\\Profiles\\Skills\\HostPolicy',
                'AgenToolsPlatform\\Testing\\ScriptedModel','PHPUnit\\Framework\\TestCase',
                'AgenToolsPlatform\\Postgres\\Profiles\\BundleRepository','AgenTools\\Schema\\Canonicalizer',
                'Illuminate\\Support\\ServiceProvider'] as $class) { if (class_exists($class)) { exit(2); } }
            if (!interface_exists('AgenToolsPlatform\\Core\\Profiles\\Skills\\Policy') ||
                !interface_exists('AgenToolsPlatform\\Core\\Profiles\\Skills\\TokenCounter')) { exit(3); }
            echo json_encode(['standalone_core'=>true,'skills'=>true,'testing_autoload'=>false,
                'sdk_autoload'=>false,'postgres_autoload'=>false,'framework_autoload'=>false]);"""
        process=subprocess.run(['php','-r',code],cwd=target,text=True,capture_output=True,timeout=10)
        sys.stderr.write(process.stderr)
        if process.returncode:raise RuntimeError('Standalone skills production boundary failed')
        return json.loads(process.stdout)

if __name__=='__main__':
    try:print(json.dumps(verify()))
    except Exception as error:print(json.dumps({'error':str(error)}));sys.exit(1)
