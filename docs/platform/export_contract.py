#!/usr/bin/env python3
"""Export a deterministic private contract bundle, without credentials or runtime claims."""
import argparse
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'docs/platform/downstream-contracts.zip'
INPUTS = (
    'docs/platform/downstream-handoff.md', 'docs/platform/integrations/README.md',
    'spec/platform/v1', 'fixtures/platform/v1',
    'examples/platform/edufy-host/contract.json', 'examples/platform/edufy-host/change-request.json',
    'extensions/chatom/contract.md', 'extensions/chatom/pin-matrix.json',
    'extensions/loqum/src/Ingress/README.md', 'extensions/loqum/src/Delivery/README.md',
    'packages/agentools-core/src/Common', 'packages/agentools-core/src/Budget',
    'packages/agentools-core/src/Usage', 'packages/agentools-core/src/Actions/Effects/OwnerPort.php',
    'packages/agentools-postgres/src/Usage/README.md',
    'packages/agentools-laravel/tests/Composition/reuse-map.json',
)

def contents():
    result = {}
    for entry in INPUTS:
        path = ROOT / entry
        if not path.exists():
            raise FileNotFoundError(entry)
        for item in sorted(path.rglob('*') if path.is_dir() else [path]):
            if item.is_file():
                result[item.relative_to(ROOT).as_posix()] = item.read_bytes()
    return result

def verify(expected):
    with zipfile.ZipFile(OUTPUT) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or set(names) != set(expected):
            raise ValueError('contract bundle file set differs')
        if any(archive.read(name) != content for name, content in expected.items()):
            raise ValueError('contract bundle is stale')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    expected = contents()
    if not args.check:
        with zipfile.ZipFile(OUTPUT, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, content in sorted(expected.items()):
                info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o644 << 16
                archive.writestr(info, content)
    verify(expected)
    print('private contract bundle verified')

if __name__ == '__main__':
    main()
