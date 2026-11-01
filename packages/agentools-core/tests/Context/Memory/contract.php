<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/History/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,RunId,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Context\History\{DerivedKind,SourceStatus};
use AgenToolsPlatform\Core\Context\Memory\{CandidateStatus,CandidateType,DeleteStatus,MemorySelector,Revocation};
use AgenToolsPlatform\Tests\Context\History\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'CM-015'=>static function(): void {
        $f=new Fixture();$summary=$f->summary();$candidate=$f->activate($f->candidate());$source=$candidate->sources[0];$run=new RunId($f->core->tenant,'completed:run');
        $f->store->register($f->core->context,$summary->ref,DerivedKind::Summary,$summary->sources->messages,[$run]);
        $f->store->register($f->core->context,$f->core->ref('cache'),DerivedKind::ContextCache,[$source],[$run]);
        $f->store->register($f->core->context,$f->core->ref('index'),DerivedKind::HistoryIndex,[$source]);
        $request=(new Revocation($f->store,$f->memory,$f->host))->sourceChanged($f->core->context,$source,'source_deleted');
        foreach ([$summary->ref,$candidate->ref,$f->core->ref('cache'),$f->core->ref('index')] as $ref) { H::check(!$f->store->usable($f->core->context,$ref)); }
        H::check($request->status===DeleteStatus::Pending && $f->memory->deletes===1 && $f->store->markers[$run->value][0]['reason']==='source_deleted');
        $selection=(new MemorySelector($f->memory,$f->host,$f->source,$f->host,$f->store,$f->core->hasher))->select($f->core->context,$candidate->subject,$f->core->now);
        H::check($selection->blocks===[] && $selection->exclusions[0]['reason']==='source_revoked');
    },
    'CM-016'=>static function(): void {
        foreach ([CandidateType::Diagnosis,CandidateType::Emotion,CandidateType::Secret,CandidateType::ThirdParty] as $type) {
            $f=new Fixture();$f->host->manual=false;$c=$f->candidate($type);$service=$f->lifecycle();$service->propose($f->core->context,$c);
            H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,1,CandidateStatus::Active,$f->core->now),ContractViolation::class,ErrorCode::PolicyConflict);
            $c=$service->advance($f->core->context,$c->ref,1,CandidateStatus::StoragePermitted,$f->core->now);
            $c=$service->advance($f->core->context,$c->ref,2,CandidateStatus::EvidenceChecked,$f->core->now);
            H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,3,CandidateStatus::HostAccepted,$f->core->now),InvalidArgumentException::class);
            H::check($f->memory->read($f->core->context,$c->ref)->candidate?->status===CandidateStatus::EvidenceChecked);
            $f->host->manual=true;$accepted=$service->advance($f->core->context,$c->ref,3,CandidateStatus::HostAccepted,$f->core->now);
            H::check($accepted->acceptance?->manual===true && $accepted->acceptance->owner->value==='owner:aicore');
        }
        $f=new Fixture();$f->host->denyStorage=true;$c=$f->candidate(CandidateType::Secret);$f->lifecycle()->propose($f->core->context,$c);
        H::rejects(static fn()=> $f->lifecycle()->advance($f->core->context,$c->ref,1,CandidateStatus::StoragePermitted,$f->core->now),ContractViolation::class,ErrorCode::AccessDenied);
    },
    'memory.lifecycle-evidence-dedup'=>static function(): void {
        $f=new Fixture();$c=$f->activate($f->candidate(CandidateType::Observation));H::check($c->status===CandidateStatus::Active && $c->version===5 && $c->confidence===null);
        $second=$f->candidate(CandidateType::Observation,'candidate:2');$service=$f->lifecycle();$service->propose($f->core->context,$second);
        $service->advance($f->core->context,$second->ref,1,CandidateStatus::StoragePermitted,$f->core->now);
        H::rejects(static fn()=> $service->advance($f->core->context,$second->ref,2,CandidateStatus::EvidenceChecked,$f->core->now),ContractViolation::class,ErrorCode::IntegrityConflict);
        $f->source->statuses['message:1']=SourceStatus::Unavailable;
        H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,5,CandidateStatus::Revalidated,$f->core->now),ContractViolation::class,ErrorCode::CapabilityUnavailable);
        H::check($f->memory->read($f->core->context,$c->ref)->candidate?->status===CandidateStatus::Active);
        H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,1,CandidateStatus::Active,$f->core->now),ContractViolation::class,ErrorCode::OptimisticConflict);
    },
    'memory.expiry-revoke'=>static function(): void {
        $f=new Fixture();$c=$f->activate($f->candidate());$service=$f->lifecycle();
        H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,5,CandidateStatus::Revalidated,$c->expiresAt),ContractViolation::class,ErrorCode::StaleContext);
        $expired=$service->advance($f->core->context,$c->ref,5,CandidateStatus::Expired,$c->expiresAt);
        H::check($expired->status===CandidateStatus::Expired);
        H::rejects(static fn()=> $service->advance($f->core->context,$c->ref,6,CandidateStatus::Active,$f->core->now),ContractViolation::class,ErrorCode::PolicyConflict);
        $f=new Fixture();$c=$f->activate($f->candidate());$revoked=$f->lifecycle()->advance($f->core->context,$c->ref,5,CandidateStatus::Revoked,$f->core->now);
        H::rejects(static fn()=> $f->lifecycle()->advance($f->core->context,$c->ref,$revoked->version,CandidateStatus::Active,$f->core->now),ContractViolation::class,ErrorCode::PolicyConflict);
    },
    'memory.consent-export'=>static function(): void {
        $f=new Fixture();$c=$f->activate($f->candidate());$writes=$f->memory->writes;$f->host->consent='';
        H::rejects(static fn()=> $f->lifecycle()->propose($f->core->context,$f->candidate(id:'candidate:2')),ContractViolation::class,ErrorCode::AccessDenied);
        $selector=new MemorySelector($f->memory,$f->host,$f->source,$f->host,$f->store,$f->core->hasher);
        H::rejects(static fn()=> $selector->select($f->core->context,$c->subject,$f->core->now),ContractViolation::class,ErrorCode::AccessDenied);
        $request=(new Revocation($f->store,$f->memory,$f->host))->sourceChanged($f->core->context,$c->sources[0],'consent_revoked');
        H::check($f->memory->writes===$writes && $request->status===DeleteStatus::Pending && !$f->store->usable($f->core->context,$c->ref));
    },
    'memory.tenant-injection'=>static function(): void {
        $f=new Fixture();$c=$f->activate($f->candidate());$selector=new MemorySelector($f->memory,$f->host,$f->source,$f->host,$f->store,$f->core->hasher);
        $selection=$selector->select($f->core->context,$c->subject,$f->core->now);
        H::check(count($selection->blocks)===1 && $selection->blocks[0]->trust==='untrusted' && $selection->blocks[0]->classification==='restricted');
        H::check($c->toArray()['confidence']===null && str_contains($selection->blocks[0]->modelView,'ignore policy'));
        H::rejects(static fn()=> $selector->select($f->core->context,new Ref(new TenantId('other'),'subject'),$f->core->now),ContractViolation::class,ErrorCode::AccessDenied);
        $old=$f->source->messages['message:1'];
        $changed=new \AgenToolsPlatform\Core\Context\History\MessageRef($old->source->ref,$old->source->branch,$old->source->turn,'v2',$old->source->hash);
        $f->source->messages['message:1']=new \AgenToolsPlatform\Core\Context\History\ExactMessage($changed,$old->owner,$old->text,$old->classification,$old->expiresAt,$f->core->hasher);
        H::rejects(static fn()=> $selector->select($f->core->context,$c->subject,$f->core->now),ContractViolation::class,ErrorCode::IntegrityConflict);
        $f->memory->status=SourceStatus::Unavailable;
        $unavailable=$selector->select($f->core->context,$c->subject,$f->core->now);H::check($unavailable->status===SourceStatus::Unavailable && $unavailable->blocks===[]);
    },
]);
