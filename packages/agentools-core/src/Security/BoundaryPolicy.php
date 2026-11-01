<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

/** Current profile/session/material/skill/consent/emergency intersection, including exact snapshot revision. */
interface BoundaryPolicy
{
    public function current(ExecutionContext $context,SnapshotEvidence $snapshot,EntryPoint $entry,BoundaryStage $stage): CurrentBoundary;
    public function source(ExecutionContext $context,SnapshotEvidence $snapshot,SourcePin $source,BoundaryStage $stage): SourceDecision;
}
