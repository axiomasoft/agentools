<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Every version is extracted from exact external sources, including periodic rebuilds. */
final readonly class SummaryRebuilder
{
    public function __construct(private HistorySource $source,private SummaryStore $store,private HistoryPolicy $policy,
        private SummaryExtractor $extractor,private Derivations $derivations,private CanonicalHasher $hasher) {}
    public function rebuild(ExecutionContext $context,Ref $branch,int $throughTurn,int $expectedVersion,WorkingState $state,UtcInstant $now): RebuildResult
    {
        $context->session->tenant->assertSame($branch->tenant);$branch->assertSame($state->value->branch);
        $this->policy->authorize($context,$branch,'summary.rebuild');$consent=$this->policy->consentVersion($context,$branch);
        if ($consent==='' || !Checks::future($state->expiresAt,$now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        $before=$this->store->head($context,$branch);
        if (($before===null?0:$before->version)!==$expectedVersion || ($before!==null && $throughTurn<$before->sources->throughTurn)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::OptimisticConflict); }
        $manifest=$this->source->manifest($context,$branch,$throughTurn);$branch->assertSame($manifest->branch);
        if ($manifest->status!==SourceStatus::Available || !$manifest->complete || $manifest->throughTurn!==$throughTurn || $manifest->messages===[]) {
            return new \AgenToolsPlatform\Core\Context\History\RebuildResult($manifest->status===SourceStatus::Available?SourceStatus::Unavailable:$manifest->status,null,false);
        }
        $messages=[];$classification=Classification::Public;
        foreach ($manifest->messages as $ref) {
            $read=$this->source->read($context,$ref);
            if (!$this->policy->sourceAllowed($context,$ref) || $read->status===SourceStatus::Revoked || $read->status===SourceStatus::Deleted) {
                $this->derivations->invalidate($context,$ref,$read->status===SourceStatus::Deleted?'source_deleted':'source_revoked');
                return new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Revoked,null,false);
            }
            if ($read->message===null || !Checks::future($read->message->expiresAt,$now)) { return new \AgenToolsPlatform\Core\Context\History\RebuildResult($read->status===SourceStatus::Available?SourceStatus::Unavailable:$read->status,null,false); }
            $this->sameSource($ref,$read->message->source);$messages[]=$read->message;
            $classification=$this->strictest($classification,$read->message->classification);
        }
        $draft=$this->extractor->extract($context,$branch,$messages,$state);
        $this->policy->authorize($context,$branch,'summary.commit');
        if ($this->policy->consentVersion($context,$branch)!==$consent) {
            foreach ($manifest->messages as $ref) { $this->derivations->invalidate($context,$ref,'consent_revoked'); }
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        }
        // Re-read exact dependencies after extraction; changed/revoked sources cannot be certified.
        foreach ($manifest->messages as $ref) {
            $read=$this->source->read($context,$ref);
            if (!$this->policy->sourceAllowed($context,$ref) || $read->status===SourceStatus::Revoked || $read->status===SourceStatus::Deleted) {
                $this->derivations->invalidate($context,$ref,$read->status===SourceStatus::Deleted?'source_deleted':'source_revoked');
                return new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Revoked,null,false);
            }
            if ($read->message===null || !Checks::future($read->message->expiresAt,$now)) { return new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Unavailable,null,false); }
            $this->sameSource($ref,$read->message->source);
        }
        $currentManifest=$this->source->manifest($context,$branch,$throughTurn);
        if ($currentManifest->status!==SourceStatus::Available || !$currentManifest->complete) {
            return new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Unavailable,null,false);
        }
        $manifest->hash->assertSame($currentManifest->hash);
        $expiry=$draft->expiresAt;
        foreach ($messages as $message) { if (Checks::future($expiry,$message->expiresAt)) { $expiry=$message->expiresAt; } }
        $version=new \AgenToolsPlatform\Core\Context\History\SummaryVersion($manifest,$expectedVersion+1,$draft->fields,
            $this->hasher->hash($branch->tenant,'history-summary-view',$draft->modelView),$draft->extractorVersion,
            $this->strictest($classification,$draft->classification),$now,$expiry,$this->hasher);
        $this->store->append($context,$version,$expectedVersion);
        return new \AgenToolsPlatform\Core\Context\History\RebuildResult(SourceStatus::Available,$version,true);
    }
    private function sameSource(MessageRef $expected,MessageRef $actual): void
    {
        $expected->ref->assertSame($actual->ref);$expected->branch->assertSame($actual->branch);$expected->hash->assertSame($actual->hash);
        if ($expected->version!==$actual->version || $expected->turn!==$actual->turn) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
    }
    private function strictest(Classification $a,Classification $b): Classification
    {
        if ($a===Classification::Restricted || $b===Classification::Restricted) { return Classification::Restricted; }
        return $a===Classification::Private || $b===Classification::Private?Classification::Private:Classification::Public;
    }
}
