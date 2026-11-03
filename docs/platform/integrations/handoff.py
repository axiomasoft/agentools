#!/usr/bin/env python3
"""Materialize or verify the private handoff's immutable input hashes."""
import argparse
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[3]
OUT=Path(__file__).with_name('handoff.json')
FILES=(
 'docs/platform/integrations/README.md', 'docs/platform/integrations/handoff.py',
 'spec/platform/v1/host/README.md', 'examples/platform/edufy-host/contract.json',
 'examples/platform/edufy-host/change-request.json', 'extensions/chatom/contract.md',
 'extensions/chatom/pin-matrix.json', 'packages/agentools-core/src/Security/HostAuthorization.php',
 'packages/agentools-core/src/Context/ContextAccess.php', 'packages/agentools-core/src/Actions/Effects/OwnerPort.php',
 'conformance/platform/host-e2e/SchoolOwner.php', 'packages/agentools-laravel/tests/Journeys/HostE2eTest.php',
 'conformance/platform/host-e2e/pg.py',
 'docs/platform/integrations/focused-evidence.json',
)
def build():
    return {'revision':'local-host-handoff/v1','evidence_kind':'host_contract_harness',
        'focused_evidence_tier':'isolated-postgres', 'external_host_e2e':'not_executed',
        'live_provider':'not_executed', 'production_readiness':'not_claimed',
        'files':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in FILES}}

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--check',action='store_true');args=parser.parse_args()
    doc=build()
    if args.check:
        if not OUT.is_file() or json.loads(OUT.read_text()) != doc: raise SystemExit('stale or missing handoff')
    else: OUT.write_text(json.dumps(doc,indent=2,ensure_ascii=False)+'\n')
    print('handoff hashes verified' if args.check else 'handoff generated')
if __name__=='__main__': main()
