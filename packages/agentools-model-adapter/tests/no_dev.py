#!/usr/bin/env python3
"""Install the locked production closure in a disposable directory; no vendor copies."""
from pathlib import Path
import json
import shutil
import subprocess
import sys
import tempfile
ROOT = Path(__file__).resolve().parents[3]
PACKAGE = ROOT/'packages/agentools-model-adapter'

def main():
    with tempfile.TemporaryDirectory(prefix='agentools_model_no_dev_') as folder:
        folder=Path(folder)
        for name in ['agentools-model-adapter','agentools-core']:
            source=ROOT/'packages'/name; target=folder/name; target.mkdir()
            shutil.copyfile(source/'composer.json',target/'composer.json')
            shutil.copytree(source/'src',target/'src')
            if (source/'resources').exists(): shutil.copytree(source/'resources',target/'resources')
        target=folder/'agentools-model-adapter'
        shutil.copyfile(PACKAGE/'composer.lock',target/'composer.lock')
        install=subprocess.run(['composer','install','--no-dev','--classmap-authoritative','--no-interaction','--prefer-dist'],cwd=target,text=True,capture_output=True)
        if install.returncode:
            print(install.stdout+install.stderr,file=sys.stderr); return install.returncode
        check=subprocess.run(['php',str(PACKAGE/'tests/no_dev.php'),str(target/'vendor/autoload.php')],cwd=target,text=True,capture_output=True)
        print(check.stdout,end='');print(check.stderr,end='',file=sys.stderr)
        return check.returncode
if __name__=='__main__':sys.exit(main())
