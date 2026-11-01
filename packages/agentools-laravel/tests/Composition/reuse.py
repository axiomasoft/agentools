#!/usr/bin/env python3
"""Generate/check attribution and consumer mapping for the P6.1 slice only."""
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
HERE = Path(__file__).resolve().parent
PACKAGE = ROOT / 'packages/agentools-laravel'

def expected():
    groups = [
        (['src/AgenToolsServiceProvider.php','src/Console/RunInput.php','src/Jobs/RunInput.php'],
         ['inventory.botkit.cli','inventory.botkit.http','inventory.botkit.jobs'],
         ['LifecycleTest.testProviderIsOptInScopedAndHasNoRoutes','LifecycleTest.testCommandUsesManagedAdmission']),
        (['src/Managed/Scope.php','src/Managed/HostBindings.php','src/Managed/LifecycleFailure.php','src/Managed/LifecycleHealth.php','src/Managed/ProductionBinding.php'],
         ['inventory.botkit.tenant-reset','inventory.botkit.security'],
         ['LifecycleTest.testTenantAThenBOnRecycledWorker','LifecycleTest.testOctaneCleansEventSandboxAndUnusedPorts','LifecycleTest.testResetFailureIsRedactedAndPoisonsScope']),
        (['src/Managed/Entry.php','src/Managed/Input.php','src/Managed/Work.php','src/Managed/HostAdmission.php',
          'src/Managed/Executor.php','src/Managed/Composition.php','src/Managed/DurableProposals.php','src/Managed/DeliveryReceipt.php'],
         ['inventory.botkit.managed','inventory.botkit.stream','inventory.botkit.raw-build','inventory.agentools.action','inventory.agentools.confirmation'],
         ['ManagedTest.testEveryEntrySharesMandatoryAdmission','ManagedTest.testUntrustedToolAndPluginFlagsGrantNothing',
          'ManagedTest.testUnknownBudgetCannotReachModelOrAction','ManagedTest.testRevocationBeforeDisclosurePreventsDelivery']),
    ]
    rows=[]
    for paths, inventory, consumers in groups:
        for name in paths:
            path=PACKAGE/name
            rows.append({'target':str(path.relative_to(ROOT)), 'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
                'inventory_rows':inventory,'consumer_tests':consumers,'disposition':'new target implementation; donor code not copied'})
    return {'subject':'PLAN2.P6.1','donor_revision':'59c701335f31b09f617ff5473a61df3c2fdda54c',
        'inventory_source':'docs/platform/bootstrap.json#reuse_decisions',
        'donor_source_transfer':False,'donor_license':'not applicable: zero donor code copied',
        'target_license':'proprietary (packages/agentools-laravel/composer.json)',
        'reused_in_place':['AgenToolsPlatform Core AdvanceRun/Actions','Postgres InvocationRepository/SubmissionLifecycle/Confirmation Service',
            'AgenTools SDK 2026-09 Actions/confirmation; no FQN or wire changes'], 'rows':rows}

def check():
    actual=json.loads((HERE/'reuse-map.json').read_text())
    if actual != expected(): raise RuntimeError('Missing/stale P6.1 source attribution map')
    for row in actual['rows']:
        for consumer in row['consumer_tests']:
            file, method=consumer.split('.')
            if 'function '+method+'(' not in (HERE/(file+'.php')).read_text():
                raise RuntimeError('Missing reuse consumer test '+consumer)

if __name__ == '__main__':
    if sys.argv[1:] == ['--write']: (HERE/'reuse-map.json').write_text(json.dumps(expected(),indent=2)+'\n')
    else: check(); print('P6.1 attribution and consumer map current')
