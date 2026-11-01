<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\History\{Checks,DerivedKind,Derivations,HistoryPolicy,HistorySource,SourceStatus};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** No permissive default or automatic sensitive-memory engine. */
final readonly class MemoryLifecycle
{
    public function __construct(private MemorySource $owner,private MemoryPolicy $policy,private HistorySource $history,
        private HistoryPolicy $historyPolicy,private Derivations $derivations,private CanonicalHasher $hasher) {}
    public function propose(ExecutionContext $context,Candidate $candidate): void
    {
        $context->session->tenant->assertSame($candidate->ref->tenant);$this->policy->authorize($context,$candidate->subject,'memory.extract');
        if ($this->policy->consentVersion($context,$candidate->subject)==='' || $candidate->version!==1 || $candidate->status!==CandidateStatus::Proposed) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        $this->owner->persist($context,$candidate,0);
    }
    public function advance(ExecutionContext $context,Ref $ref,int $expectedVersion,CandidateStatus $target,UtcInstant $now): Candidate
    {
        $context->session->tenant->assertSame($ref->tenant);$read=$this->owner->read($context,$ref);
        $candidate=$read->candidate??throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        $ref->assertSame($candidate->ref);$this->policy->authorize($context,$candidate->subject,'memory.transition');
        $consent=$this->policy->consentVersion($context,$candidate->subject);
        if ($consent==='') { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        if ($candidate->version!==$expectedVersion) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::OptimisticConflict); }
        if (in_array($candidate->status,[CandidateStatus::Revoked,CandidateStatus::Deleted,CandidateStatus::Expired],true)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        $next=match($candidate->status) {
            CandidateStatus::Proposed=>CandidateStatus::StoragePermitted,CandidateStatus::StoragePermitted=>CandidateStatus::EvidenceChecked,
            CandidateStatus::EvidenceChecked=>CandidateStatus::HostAccepted,CandidateStatus::HostAccepted=>CandidateStatus::Active,
            CandidateStatus::Active,CandidateStatus::Revalidated=>CandidateStatus::Revalidated};
        if ($target!==$next && !in_array($target,[CandidateStatus::Revoked,CandidateStatus::Deleted,CandidateStatus::Expired],true)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        if ($target===CandidateStatus::Expired && Checks::future($candidate->expiresAt,$now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::PolicyConflict); }
        if (!in_array($target,[CandidateStatus::Expired,CandidateStatus::Revoked,CandidateStatus::Deleted],true)
            && (!Checks::future($candidate->expiresAt,$now) || Checks::future($candidate->validFrom,$now))) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::StaleContext); }
        if ($target===CandidateStatus::StoragePermitted) { $this->policy->permitsStorage($context,$candidate); }
        if (in_array($target,[CandidateStatus::EvidenceChecked,CandidateStatus::HostAccepted,CandidateStatus::Active,CandidateStatus::Revalidated],true)) {
            $this->policy->checkEvidence($context,$candidate);$this->assertSources($context,$candidate,$now);
            $duplicate=$this->owner->duplicate($context,$candidate->dedupKey);
            if ($duplicate!==null && $duplicate->value!==$candidate->ref->value) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        }
        $acceptance=$target===CandidateStatus::HostAccepted?$this->policy->accept($context,$candidate):null;
        $updated=$candidate->next($target,$this->hasher,$acceptance);
        $this->policy->authorize($context,$candidate->subject,'memory.commit');
        if ($this->policy->consentVersion($context,$candidate->subject)!==$consent) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied); }
        if (in_array($target,[CandidateStatus::EvidenceChecked,CandidateStatus::Active,CandidateStatus::Revalidated],true)) {
            $this->derivations->register($context,$candidate->ref,DerivedKind::Candidate,$candidate->sources);
        }
        $this->owner->persist($context,$updated,$expectedVersion);return $updated;
    }
    private function assertSources(ExecutionContext $context,Candidate $candidate,UtcInstant $now): void
    {
        foreach ($candidate->sources as $source) {
            $read=$this->history->read($context,$source);
            if (!$this->historyPolicy->sourceAllowed($context,$source) || $read->status===SourceStatus::Deleted || $read->status===SourceStatus::Revoked) {
                $this->derivations->invalidate($context,$source,$read->status===SourceStatus::Deleted?'source_deleted':'source_revoked');
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
            }
            if ($read->message===null || !Checks::future($read->message->expiresAt,$now)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
            $source->ref->assertSame($read->message->source->ref);$source->branch->assertSame($read->message->source->branch);$source->hash->assertSame($read->message->source->hash);
            if ($source->version!==$read->message->source->version || $source->turn!==$read->message->source->turn) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
        }
    }
}
