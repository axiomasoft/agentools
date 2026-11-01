#!/usr/bin/env python3
"""P6.2 isolated definition/assignment/plugin integration cases."""
import importlib.util
from pathlib import Path
import sys
HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[3]
spec=importlib.util.spec_from_file_location('definitions_pg_lifecycle', ROOT/'packages/agentools-postgres/tests/run.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
CASES={
 'testImmutableRegistryAndExplicitOwnership':'definitions.immutable-owner',
 'testDuplicatesAndDifferingHashesFail':'definitions.hash-conflict',
 'testDraftsCannotEditPublishedBehaviorOrGuards':'definitions.draft-isolation',
 'testRevocationOverPinnedDefinitionIsCurrent':'definitions.current-revocation',
 'testOnlySelectedCodePluginRunsAndFragmentsStayData':'plugins.selected-data-only',
 'testOptionalFailureNeverSwallowsMandatoryGuard':'plugins.failure-policy',
 'testBudgetsAndTenantBoundsCannotBeRelaxed':'plugins.bounds',
 'testResetAttemptsEveryPluginAndErasesState':'plugins.reset-failure',
 'testAssignmentsDraftsCasAndReplay':'instances.cas-replay',
 'testNewSessionsUseAssignmentAndExistingSessionKeepsPin':'instances.session-pins',
 'testCurrentAuthorityAndTenantApplyEvenOnReplay':'instances.current-tenant',
 'testCommitFaultRollsBackInstanceAndReceipt':'instances.commit-fault',
 'testSchemaInstallIsVersionedAndConflictFails':'instances.schema-version',
 'testEveryManagedAliasKeepsGuardsAndOneLoop':'definitions.managed-positive',
 'testPluginAllowFlagsNeverOverrideMandatoryDenial':'definitions.managed-negative',
 'testFragmentHashAndCurrentDataAccessCannotBeOmitted':'definitions.fragment-guards',
 'testSequentialTenantResetAndProductionDecoratorCheck':'definitions.managed-fault',
}
if __name__=='__main__':sys.exit(module.main(HERE/'phpunit.xml','platform-definitions',CASES))
