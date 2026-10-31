#!/usr/bin/env python3
"""Exercise a real clean --no-dev install without altering the working vendor."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

PACKAGE = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix="agentools_p3_no_dev_") as directory:
        target = Path(directory) / 'packages/agentools-postgres'
        target.mkdir(parents=True)
        core = PACKAGE.parent / 'agentools-core'
        core_target = target.parent / 'agentools-core'
        core_target.mkdir()
        shutil.copy2(core / 'composer.json', core_target / 'composer.json')
        shutil.copytree(core / 'src', core_target / 'src')
        sdk_target = Path(directory) / 'php'
        sdk_target.mkdir()
        sdk = PACKAGE.parents[1] / 'php'
        shutil.copy2(sdk / 'composer.json', sdk_target / 'composer.json')
        shutil.copytree(sdk / 'src', sdk_target / 'src')
        for name in ("composer.json", "composer.lock"):
            shutil.copy2(PACKAGE / name, target / name)
        for name in ("src", "migrations"):
            shutil.copytree(PACKAGE / name, target / name)
        install = subprocess.run(["composer", "install", "--no-dev", "--no-scripts", "--no-interaction", "--working-dir", str(target)],
                                 capture_output=True, text=True, timeout=60)
        sys.stderr.write(install.stdout + install.stderr)
        if install.returncode:
            return install.returncode
        code = r"""require 'vendor/autoload.php';
            if (!class_exists('AgenToolsPlatform\\Postgres\\Migrator') ||
                !class_exists('AgenToolsPlatform\\Postgres\\MigrationException') ||
                class_exists('AgenToolsPlatform\\Postgres\\Tests\\IsolatedDatabase') ||
                class_exists('PHPUnit\\Framework\\TestCase') ||
                !class_exists('AgenToolsPlatform\\Core\\Security\\TrustedInput') ||
                !class_exists('AgenToolsPlatform\\Postgres\\Actions\\ActionRepository') ||
                !class_exists('AgenTools\\Persistence\\PdoPostgresEventStore') ||
                !class_exists('AgenToolsPlatform\\Postgres\\Payloads\\PayloadRepository') ||
                !class_exists('AgenToolsPlatform\\Postgres\\Payloads\\JobBoundary') ||
                count(glob('migrations/*.sql')) !== 4) { exit(1); }
            echo json_encode(['case'=>'packaging.postgres-no-dev','status'=>'passed']);"""
        result = subprocess.run(["php", "-r", code], cwd=target, text=True, capture_output=True, timeout=10)
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
        return result.returncode


if __name__ == '__main__':
    sys.exit(main())
