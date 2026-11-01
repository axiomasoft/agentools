#!/usr/bin/env python3
"""Owning source/inventory/consumer mapping; no donor source was transferred."""
import hashlib
import json
from pathlib import Path
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]

def expected():
    rows=[]
    for folder,inventory,tests in (
        ('Definitions',['inventory.botkit.registry','inventory.botkit.options'],['RegistryTest','InstanceTest']),
        ('Plugins',['inventory.botkit.plugins','inventory.botkit.managed','inventory.botkit.stream','inventory.botkit.raw-build'],['PluginTest','AdmissionTest']),
    ):
        for p in sorted((ROOT/'packages/agentools-laravel/src'/folder).glob('*')):
            if p.suffix not in ('.php','.sql'):continue
            rows.append({'path':str(p.relative_to(ROOT)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),
                'inventory_rows':inventory,'consumer_test_files':['packages/agentools-laravel/tests/Definitions/'+name+'.php' for name in tests]})
    return {'subject':'PLAN2.P6.2','donor_revision':'59c701335f31b09f617ff5473a61df3c2fdda54c','donor_source_copied':False,
        'donor_license':'not applicable: zero donor code copied','target_license':'proprietary',
        'source':'docs/platform/bootstrap.json#reuse_decisions','disposition':'new target implementation; existing Core/PG/SDK reused in place','rows':rows}

def check():
    if json.loads((HERE/'attribution.json').read_text())!=expected():raise RuntimeError('Missing/stale definitions attribution')
    for row in expected()['rows']:
        for name in row['consumer_test_files']:
            if not (ROOT/name).is_file():raise RuntimeError('Missing consumer test '+name)

if __name__=='__main__':
    if sys.argv[1:]==['--write']:(HERE/'attribution.json').write_text(json.dumps(expected(),indent=2)+'\n')
    else:check();print('Definitions source attribution current')
