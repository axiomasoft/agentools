<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Security\{AccessState,BoundaryClock,BoundaryPolicy,BoundaryStage,CurrentBoundary,EntryPoint,ExecutionContext,SnapshotEvidence,SourceDecision,SourcePin};

/** Real BoundaryGuard composition over this host's exact compiled sources. No ManualClock. */
final readonly class Boundary implements BoundaryPolicy,BoundaryClock
{
    public function __construct(private KnowledgeJourney $journey) {}
    public function now(): UtcInstant { return $this->journey->host->guard->now(); }
    public function current(ExecutionContext $context, SnapshotEvidence $snapshot, EntryPoint $entry, BoundaryStage $stage): CurrentBoundary
    {
        $j=$this->journey;$j->assertCurrent();
        return new CurrentBoundary($j->host->ref('knowledge:boundary'),AccessState::Allow,$context->identity->policyRevision,
            $snapshot->revision(),$snapshot->hash(),false,[]);
    }
    public function source(ExecutionContext $context, SnapshotEvidence $snapshot, SourcePin $source, BoundaryStage $stage): SourceDecision
    {
        $j=$this->journey;$j->assertCurrent();$found=false;
        foreach (($j->guardSnapshot ?? throw new \LogicException('Prepare context first'))->sources() as $known) {
            if ($known->toArray()===$source->toArray()) { $found=true;break; }
        }
        return new SourceDecision($j->host->ref('knowledge:source-decision'),$found ? AccessState::Allow : AccessState::Deny);
    }
}
