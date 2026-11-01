<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,UtcInstant};

/** Same fail-closed guard at invocation/tool/disclosure for every entry. No cached grant. */
final readonly class BoundaryGuard
{
    public function __construct(private HostAuthorization $host,private BoundaryPolicy $policy,private CanonicalHasher $hasher,private BoundaryClock $clock) {}
    public function now(): UtcInstant { return $this->clock->now(); }
    /** @param list<ToolPin> $tools */
    public function check(ExecutionContext $context,SnapshotEvidence $snapshot,EntryPoint $entry,BoundaryStage $stage,array $tools=[]): BoundaryDecision
    {
        $context->assertCurrent($this->host);$snapshot->assertBound($context,$this->hasher);
        $current=$this->policy->current($context,$snapshot,$entry,$stage);$this->assertCurrent($context,$snapshot,$current,$tools);
        foreach ($snapshot->sources() as $source) {
            $context->session->tenant->assertSame($source->source->tenant);$this->expiry($source);
            $decision=$this->policy->source($context,$snapshot,$source,$stage);$context->session->tenant->assertSame($decision->ref->tenant);
            $this->allowed($decision->access);
        }
        // Policy/consent/emergency may change during source checks; no pin overrides the fresh result.
        $current=$this->policy->current($context,$snapshot,$entry,$stage);$this->assertCurrent($context,$snapshot,$current,$tools);
        $context->assertCurrent($this->host);foreach ($snapshot->sources() as $source) { $this->expiry($source); }
        return new \AgenToolsPlatform\Core\Security\BoundaryDecision($current->decision,$snapshot->ref(),$snapshot->hash(),$entry,$stage);
    }
    /** @param list<ToolPin> $tools */
    private function assertCurrent(ExecutionContext $context,SnapshotEvidence $snapshot,CurrentBoundary $current,array $tools): void
    {
        $context->session->tenant->assertSame($current->decision->tenant);$this->allowed($current->access);
        if ($current->emergencyStop===true) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::DeploymentUnavailable); }
        if ($current->emergencyStop===null || $current->snapshotRevision===null || $current->snapshotHash===null) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
        if ($current->policyRevision!==$context->identity->policyRevision || $current->snapshotRevision!==$snapshot->revision()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        $current->snapshotHash->assertSame($snapshot->hash());$allowed=[];
        foreach ($current->tools as $tool) {
            if (isset($allowed[$tool->name])) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }$allowed[$tool->name]=$tool;
        }
        foreach ($tools as $tool) {
            if (!isset($allowed[$tool->name]) || $tool->toArray()!==$allowed[$tool->name]->toArray()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        }
    }
    private function allowed(AccessState $state): void
    {
        if ($state===AccessState::Deny) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        if ($state===AccessState::Unknown) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
    }
    private function expiry(SourcePin $source): void
    {
        if ($source->expiresAt!==null && strcmp($this->time($source->expiresAt),$this->time($this->clock->now()))<=0) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
    }
    private function time(UtcInstant $t): string { return substr($t->value,0,19).'.'.substr((strlen($t->value)>20?substr($t->value,20,-1):'').'000000',0,6); }
}
