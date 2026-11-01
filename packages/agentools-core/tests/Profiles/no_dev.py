#!/usr/bin/env python3
"""Fresh production install of the current Profile sources; no in-place vendor mutation."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[4]

def verify():
    with tempfile.TemporaryDirectory(prefix='agentools_profiles_no_dev_') as directory:
        target = Path(directory)
        for relative, folders, files in (
            ('packages/agentools-core', ('src',), ('composer.json',)),
            ('packages/agentools-postgres', ('src', 'migrations'), ('composer.json', 'composer.lock')),
            ('php', ('src',), ('composer.json',)),
        ):
            destination = target / relative
            destination.mkdir(parents=True)
            for folder in folders: shutil.copytree(ROOT / relative / folder, destination / folder)
            for file in files: shutil.copy2(ROOT / relative / file, destination / file)
        pg = target / 'packages/agentools-postgres'
        install = subprocess.run(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--working-dir', str(pg)],
            text=True, capture_output=True, timeout=60)
        sys.stderr.write(install.stdout + install.stderr)
        if install.returncode: raise RuntimeError('Profiles production installation failed')
        code = r"""require 'vendor/autoload.php';
            foreach (['AgenToolsPlatform\\Core\\Profiles\\BundleManifest',
                'AgenToolsPlatform\\Core\\Profiles\\CodeRegistry',
                'AgenToolsPlatform\\Core\\Profiles\\Composition\\Composer',
                'AgenToolsPlatform\\Core\\Profiles\\Composition\\TypedCompiler',
                'AgenToolsPlatform\\Postgres\\Profiles\\BundleRepository',
                'AgenToolsPlatform\\Postgres\\Profiles\\SessionRepository'] as $class) {
                if (!class_exists($class)) { exit(1); }
            }
            foreach (['PHPUnit\\Framework\\TestCase', 'AgenToolsPlatform\\Tests\\Profiles\\Fixture',
                'AgenToolsPlatform\\Tests\\Profiles\\Merge\\ScriptedMaterials',
                'AgenToolsPlatform\\Postgres\\Tests\\Profiles\\TestAuthority',
                'AgenToolsPlatform\\Testing\\ScriptedModel'] as $class) { if (class_exists($class)) { exit(2); } }
            if (!interface_exists('AgenToolsPlatform\\Postgres\\Profiles\\Authority') ||
                !is_file('src/Profiles/schema.sql')) { exit(3); }
            echo json_encode(['profile_publisher'=>true,'profile_pins'=>true,'composition'=>true,'testing_autoload'=>false]);"""
        result = subprocess.run(['php', '-r', code], cwd=pg, text=True, capture_output=True, timeout=10)
        sys.stderr.write(result.stderr)
        if result.returncode: raise RuntimeError('Profiles production autoload boundary failed')
        return json.loads(result.stdout)

if __name__ == '__main__':
    try: print(json.dumps(verify()))
    except Exception as error:
        print(json.dumps({'error': str(error)})); sys.exit(1)
