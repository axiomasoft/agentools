<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,IntegrityConflict,PayloadHash,Ref};
use AgenToolsPlatform\Core\Security\{AccessState,DeliveryObservation,DeliveryState,DisclosureDelivery,DisclosureMode,DisclosurePayload,DisclosurePolicy,EntryPoint,ExecutionContext,GeneratedChunk};
use AgenToolsPlatform\Tests\Security\{Fixture,Proof};
use AgenToolsPlatform\Tests\Harness as H;

function chunk(Fixture $f,int $sequence,string $bytes,bool $segmentEnd=false,bool $responseEnd=false): GeneratedChunk
{ return new GeneratedChunk($f->core->ref('invocation:guard'),$f->core->ref('response:1'),$sequence,$bytes,$segmentEnd,$responseEnd); }

Proof::run([
    'CM-043'=>static function(): void {
        $f=new Fixture(true);$f->policy->outputSensitive=true;$stream=$f->disclosure();
        H::check($stream->push(chunk($f,1,'sensitive '))===null && $stream->push(chunk($f,2,'report',true))===null);
        H::check($f->policy->checks===0 && $f->policy->deliveries===0 && $stream->statistics()['delivered_bytes']===0);
        $payload=$stream->push(chunk($f,3,' complete',true,true));
        H::check($payload!==null && $payload->prechecked && $f->policy->checks===1 && $f->policy->completeChecks===[true]);
        H::check($f->policy->deliveries===0 && $stream->statistics()['generated_bytes']===25 && $stream->statistics()['delivered_bytes']===0);
        if ($payload===null) { throw new RuntimeException('Checked full payload required'); }
        $receipt=$stream->deliver($payload->ref,$f->policy);
        H::check($receipt->deliveredBytes===25 && $receipt->heardBytes===null);
        Proof::$observations['CM-043']=['early_content_bytes'=>0,'checks'=>1,'full_result_check'=>true,'generated_bytes'=>25,'delivered_before_receipt'=>0,'delivered_after_receipt'=>25,'heard_bytes'=>null];
    },
    'security.disclosure-denial-and-revoke'=>static function(): void {
        $f=new Fixture(true);$f->policy->inspection=AccessState::Deny;$stream=$f->disclosure();
        H::rejects(static fn()=> $stream->push(chunk($f,1,'sensitive',true,true)),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->policy->deliveries===0);
        $f=new Fixture();$stream=$f->disclosure();$f->policy->afterCheck=static function() use($f): void { $f->policy->denied=['message:source']; };
        H::rejects(static fn()=> $stream->push(chunk($f,1,'checked then revoked',true,true)),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->policy->deliveries===0);
        $f=new Fixture();$stream=$f->disclosure();$payload=$stream->push(chunk($f,1,'checked',true,true));H::check($payload!==null);
        if ($payload===null) { throw new RuntimeException('Payload required'); }$f->policy->denied=['content:data'];
        H::rejects(static fn()=> $stream->deliver($payload->ref,$f->policy),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->policy->deliveries===0);
    },
    'security.checked-segments-interruption'=>static function(): void {
        $f=new Fixture();$f->policy->mode=DisclosureMode::CheckedSegments;$stream=$f->disclosure(EntryPoint::Voice);
        H::check($stream->push(chunk($f,1,'part'))===null && $f->policy->checks===0);
        $payload=$stream->push(chunk($f,2,' closed',true));if ($payload===null) { throw new RuntimeException('Closed segment required'); }
        H::check($f->policy->completeChecks===[false] && $payload->prechecked);
        $f->policy->deliveryState=DeliveryState::Interrupted;$f->policy->heard=1;$receipt=$stream->deliver($payload->ref,$f->policy);
        H::check($receipt->deliveredBytes===3 && $receipt->heardBytes===1 && $stream->statistics()['stopped']===true);
        H::rejects(static fn()=> $stream->push(chunk($f,3,'remaining',true,true)),ContractViolation::class,ErrorCode::PolicyConflict);
        H::rejects(static fn()=> $stream->push(new GeneratedChunk($f->core->ref('fallback:invocation'),$f->core->ref('response:1'),3,'stitched',true,true)),ContractViolation::class,ErrorCode::IntegrityConflict);
    },
    'security.live-approved'=>static function(): void {
        $f=new Fixture();H::rejects(static fn()=> new DisclosurePolicy($f->core->ref('output:policy'),'v1',DisclosureMode::Live,false,100),InvalidArgumentException::class);
        $sensitive=new Fixture(true);$sensitive->policy->mode=DisclosureMode::Live;$sensitive->policy->liveApproval=$sensitive->core->ref('risk:approved');
        H::rejects(static fn()=> $sensitive->disclosure(),ContractViolation::class,ErrorCode::PolicyConflict);
        $f->policy->mode=DisclosureMode::Live;$f->policy->liveApproval=$f->core->ref('risk:approved');$stream=$f->disclosure();$payload=$stream->push(chunk($f,1,'live token'));
        if ($payload===null) { throw new RuntimeException('Approved live payload required'); }
        H::check(!$payload->prechecked && $payload->checkRef===null && $payload->liveApproval!==null && $f->policy->checks===0);
        $stream->deliver($payload->ref,$f->policy);H::check($f->policy->deliveries===1);
    },
    'security.unknown-delivery'=>static function(): void {
        $f=new Fixture();$stream=$f->disclosure();$payload=$stream->push(chunk($f,1,'response',true,true));if ($payload===null) { throw new RuntimeException('Payload required'); }
        $f->policy->throwDelivery=true;$receipt=$stream->deliver($payload->ref,$f->policy);
        H::check($receipt->state===DeliveryState::Unknown && $receipt->deliveredBytes===null && $stream->statistics()['delivered_bytes']===null);
        $again=$stream->deliver($payload->ref,$f->policy);H::check($again->toArray()===$receipt->toArray() && $f->policy->deliveries===1);
        Proof::$observations['unknown_delivery']=['owner_calls'=>1,'delivered_bytes'=>null,'hearing'=>null,'resend'=>false];
    },
    'security.output-order-bounds'=>static function(): void {
        $f=new Fixture();$f->policy->mode=DisclosureMode::CheckedSegments;$stream=$f->disclosure();
        $one=$stream->push(chunk($f,1,'one',true));$two=$stream->push(chunk($f,2,'two',true,true));if ($one===null||$two===null) { throw new RuntimeException('Two segments required'); }
        H::rejects(static fn()=> $stream->deliver($two->ref,$f->policy),ContractViolation::class,ErrorCode::PolicyConflict);
        H::check($f->policy->deliveries===0);$stream->deliver($one->ref,$f->policy);$stream->deliver($two->ref,$f->policy);H::check($stream->statistics()['delivered_bytes']===6);
        $f=new Fixture(true);$f->policy->limit=4;$stream=$f->disclosure();H::check($stream->push(chunk($f,1,'four'))===null);
        H::rejects(static fn()=> $stream->push(chunk($f,2,'overflow',true,true)),ContractViolation::class,ErrorCode::AdmissionDenied);
        H::check($f->policy->deliveries===0 && $f->policy->checks===0);
        $first=new Fixture();$a=$first->disclosure();$p=$a->push(chunk($first,1,'stable',true,true));if ($p===null) { throw new RuntimeException('Payload required'); }$a->deliver($p->ref,$first->policy);
        $second=new Fixture(sourceKind:\AgenToolsPlatform\Core\Context\SourceKind::Memory);$b=$second->disclosure();$q=$b->push(chunk($second,1,'stable',true,true));if ($q===null) { throw new RuntimeException('Replay required'); }
        H::check($first->snapshot->hash()->value!==$second->snapshot->hash()->value && $p->ref->value===$q->ref->value);
        $b->deliver($q->ref,$first->policy);H::check($first->policy->receiptWrites===1 && $first->policy->deliveries===2);
        $third=new Fixture();$c=$third->disclosure();$changed=$c->push(chunk($third,1,'different',true,true));if ($changed===null) { throw new RuntimeException('Conflict payload required'); }
        H::check($changed->ref->value===$p->ref->value);
        H::rejects(static fn()=> $c->deliver($changed->ref,$first->policy),ContractViolation::class,ErrorCode::IntegrityConflict);
        H::check($first->policy->receiptWrites===1);
        $f=new Fixture();$a=$f->disclosure();$b=$f->disclosure();
        $p=$a->push(chunk($f,1,'first',true,true));$q=$b->push(chunk($f,1,'different',true,true));
        if ($p===null || $q===null) { throw new RuntimeException('Conflicting payloads required'); }
        H::check($p->ref->value===$q->ref->value && $p->hash->value!==$q->hash->value);
        $owner=new class implements DisclosureDelivery {
            public ?PayloadHash $hash=null;public int $calls=0;
            public function deliver(ExecutionContext $context,DisclosurePayload $payload): DeliveryObservation
            {
                ++$this->calls;if ($this->hash!==null) { $this->hash->assertSame($payload->hash); }$this->hash=$payload->hash;
                return new DeliveryObservation(new Ref($payload->ref->tenant,'hash-owner:receipt'),$payload->ref,$payload->hash,DeliveryState::Delivered,strlen($payload->bytes));
            }
        };
        $a->deliver($p->ref,$owner);
        H::rejects(static fn()=> $b->deliver($q->ref,$owner),IntegrityConflict::class);
        H::check($b->statistics()['stopped']===true && $b->statistics()['receipts']===0 && $b->statistics()['delivered_bytes']===0);
        H::rejects(static fn()=> $b->deliver($q->ref,$owner),ContractViolation::class,ErrorCode::PolicyConflict);
        H::check($owner->calls===2 && $b->statistics()['receipts']===0);
    },
    'security.disclosure-policy-refresh'=>static function(): void {
        $f=new Fixture();$stream=$f->disclosure();$payload=$stream->push(chunk($f,1,'checked',true,true));if ($payload===null) { throw new RuntimeException('Payload required'); }
        $f->policy->outputVersion='v2';H::rejects(static fn()=> $stream->deliver($payload->ref,$f->policy),ContractViolation::class,ErrorCode::StaleContext);H::check($f->policy->deliveries===0);
        $f=new Fixture();$stream=$f->disclosure();$payload=$stream->push(chunk($f,1,'checked',true,true));if ($payload===null) { throw new RuntimeException('Payload required'); }
        $f->policy->revision=2;H::rejects(static fn()=> $stream->deliver($payload->ref,$f->policy),ContractViolation::class,ErrorCode::StaleContext);H::check($f->policy->deliveries===0);
    },
]);
