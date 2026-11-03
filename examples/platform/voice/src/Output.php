<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Voice;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\{AccessState, BoundaryPolicy, BoundaryStage, CurrentBoundary, DisclosureAuthority, DisclosureCheck, DisclosureInspector, DisclosurePolicy, EntryPoint, ExecutionContext, SnapshotEvidence, SourceDecision, SourcePin};
use AgenToolsPlatform\Postgres\Runtime\Connection as C;

/** Scripted source/disclosure policy on the ordinary central guard composition. */
final readonly class Output implements BoundaryPolicy,DisclosureAuthority,DisclosureInspector
{
    public function __construct(private Journey $j) {}
    public function current(ExecutionContext $context,SnapshotEvidence $snapshot,EntryPoint $entry,BoundaryStage $stage): CurrentBoundary
    {
        $s=$this->j->host->state();$allowed=$s['allowed']===true && $this->j->host->current($context,$snapshot->ref())===$snapshot->revision();
        return new CurrentBoundary($this->j->ref('guard:'.$stage->value),$allowed?AccessState::Allow:AccessState::Deny,
            C::text($s,'policy_revision'),$snapshot->revision(),$snapshot->hash(),false,[]);
    }
    public function source(ExecutionContext $context,SnapshotEvidence $snapshot,SourcePin $source,BoundaryStage $stage): SourceDecision
    {
        $context->assertCurrent($this->j->host);
        return new SourceDecision($this->j->ref('source:decision'),$this->j->host->state()['allowed']===true?AccessState::Allow:AccessState::Deny);
    }
    public function policy(ExecutionContext $context,SnapshotEvidence $snapshot,Ref $response): DisclosurePolicy { return $this->j->host->outputPolicy(); }
    public function check(ExecutionContext $context,Ref $response,string $bytes,PayloadHash $hash,bool $responseComplete): DisclosureCheck
    {
        return new DisclosureCheck($this->j->ref('inspected:'.substr($hash->value,8)),
            $this->j->host->denyInspection?AccessState::Deny:AccessState::Allow,$hash,true);
    }
}
