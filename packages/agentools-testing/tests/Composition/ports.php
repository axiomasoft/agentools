<?php

declare(strict_types=1);

require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Core\Actions\Effects\{LookupStatus,Request,Strategy};
use AgenToolsPlatform\Core\Budget\AdmissionRequest;
use AgenToolsPlatform\Core\Common\{ActionId,ContractViolation,ErrorCode,IntegrityConflict,PayloadHash,Ref};
use AgenToolsPlatform\Core\Models\StreamState;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Testing\{FaultPoint,InjectedFault};
use AgenToolsPlatform\Testing\Tests\Composition\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

$cases=[
 'fake.ports-identity-state'=>static function():void{
    $f=new Fixture();$c=$f->begin();$plan=$f->plan($c);$request=$f->admission($c,$plan);
    H::check($f->budget->status($request)->status==='not_found');$f->budget->enqueue($request->operationKey,'admitted');
    $a=$f->budget->admit($request);H::check($a->status==='admitted' && $f->budget->reservations===1);
    H::check($f->budget->admit($request)->admissionRef?->value===$a->admissionRef?->value && $f->budget->reservations===1);
    H::rejects(fn()=>$f->budget->admit(new AdmissionRequest($request->operationKey,$request->content+['different'=>true],$f->hasher)),IntegrityConflict::class);
    $f->model->enqueue($f->response($plan));$response=$f->model->generate($plan->request,$plan->deployment);
    H::check($response->usage->inputTokens===null && $response->usage->outputTokens===0);
    H::check($f->model->generate($plan->request,$plan->deployment)===$response && $f->model->exchanges===1);
    $reservation=$a->reservationRef??throw new LogicException();$usage=$f->ref($c,'usage-event');
    $f->budget->settle($reservation,$usage);$f->budget->settle($reservation,$usage);
    H::rejects(fn()=>$f->budget->settle($f->ref($c,'unknown-reservation'),$usage),ContractViolation::class);
    H::rejects(fn()=>$f->budget->release($a->reservationRef??throw new LogicException(),'not_sent'),ContractViolation::class);
    $block=$f->history($c);$f->history->append($c,$block);H::check($f->history->read($c,$block->ref)->trust==='untrusted');
    H::rejects(fn()=>$f->history->append($c,$f->history($c,'changed')),IntegrityConflict::class);
    $bytes='{"payload":"synthetic"}';$hash=new PayloadHash('sha-256:'.hash('sha256',$bytes));
    $f->payloads->put($c,$f->ref($c,'payload'),$hash,$bytes);H::check($f->payloads->get($c,$f->ref($c,'payload'))===$bytes);
    H::rejects(fn()=>$f->payloads->put($c,$f->ref($c,'payload'),$hash,'{}'),IntegrityConflict::class);
 },
 'fake.raw-admission-and-purpose-denial'=>static function():void{
    $f=new Fixture();$c=$f->begin();$plan=$f->plan($c);$f->model->enqueue($f->response($plan));
    H::rejects(fn()=>$f->model->generate($plan->request,$plan->deployment),ContractViolation::class);
    $request=$f->admission($c,$plan);H::check($f->budget->admit($request)->status==='denied' && $f->model->exchanges===0);
    H::rejects(fn()=>ExecutionContext::fromHost($f->authority,$c->session,'other.purpose',$c->correlation),ContractViolation::class);
    $f->secrets->seed($f->ref($c,'credential'),'synthetic-alpha-key');H::check($f->secrets->resolve($f->ref($c,'credential'))==='synthetic-alpha-key');
    $f->secrets->seed($f->ref($c,'history-source'),'synthetic-forbidden-key');
    H::rejects(fn()=>$f->secrets->resolve($f->ref($c,'history-source')),ContractViolation::class);
    $f->authority->revoke($c->session,$c->purpose);H::rejects(fn()=>$f->secrets->resolve($f->ref($c,'credential')),ContractViolation::class);
 },
 'fake.delivery-disclosure-and-unknown'=>static function():void{
    $f=new Fixture();$c=$f->begin();$intent=$f->intent($c);
    H::rejects(fn()=>$f->delivery->send($c,$intent),ContractViolation::class);H::check($f->delivery->sent===0);
    $f->disclosure->allow($intent->responseRef,$intent->requestHash);$f->delivery->loseAcknowledgement=true;
    $unknown=$f->delivery->send($c,$intent);H::check($unknown->receipt===null && $unknown->acknowledgedUnits===null && $f->delivery->sent===1);
    $f->delivery->loseAcknowledgement=false;$known=$f->delivery->send($c,$intent);H::check($known->receipt!==null && $known->acknowledgedUnits===1 && $f->delivery->sent===1);
    H::rejects(fn()=>$f->delivery->send($c,$f->intent($c,2)),IntegrityConflict::class);
    $f->authority->revoke($c->session,$c->purpose);H::rejects(fn()=>$f->delivery->send($c,$intent),ContractViolation::class);
 },
 'fake.owner-requires-durable-boundary'=>static function():void{
    $f=new Fixture();$c=$f->begin();$request=new Request(new ActionId($c->identity->tenant,'action'),'effect',$f->owner->namespace(),'operation',
        $f->hasher->hash($c->identity->tenant,'effect','synthetic'),$f->ref($c,'request'),$c,1,Strategy::NativeKey);
    H::rejects(fn()=>$f->owner->execute($request),ContractViolation::class);H::check($f->owner->writes===0);
    $unknown=$f->owner->lookup($request);H::check($unknown->status===LookupStatus::Unknown && $unknown->receipt===null);
 },
 'fake.external-fault-and-lookup'=>static function():void{
    $f=new Fixture();$c=$f->begin();$plan=$f->plan($c);$admit=$f->admission($c,$plan);$f->budget->enqueue($admit->operationKey,'admitted');
    $f->budget->faults->arm(FaultPoint::BeforeExternal);H::rejects(fn()=>$f->budget->admit($admit),InjectedFault::class);
    H::check($f->budget->status($admit)->status==='not_found' && $f->budget->reservations===0);
    $f->budget->faults->arm(FaultPoint::AfterExternal);H::rejects(fn()=>$f->budget->admit($admit),InjectedFault::class);
    H::check($f->budget->status($admit)->status==='admitted' && $f->budget->reservations===1);$f->budget->faults->assertConsumed();
    $f->model->enqueue($f->response($plan));$f->model->faults->arm(FaultPoint::AfterExternal);
    H::rejects(fn()=>$f->model->generate($plan->request,$plan->deployment),InjectedFault::class);H::check($f->model->exchanges===1);
    $f->clock->advanceMicroseconds(86400*366*1000000);
    H::check($f->model->find($c,$plan)?->response->usage->inputTokens===null && $f->model->exchanges===1);
    $f->model->faults->assertConsumed();
 },
 'fake.stream-terminal-loss-stays-unknown'=>static function():void{
    $f=new Fixture();$c=$f->begin();$p=$f->plan($c);$a=$f->admission($c,$p);$f->budget->enqueue($a->operationKey,'admitted');$f->budget->admit($a);
    $f->model->enqueue($f->response($p));$f->model->faults->arm(FaultPoint::AfterExternal);$state=new StreamState($p->request->invocationId);
    try{foreach($f->model->stream($p->request,$p->deployment) as $event){$state->accept($event);}throw new LogicException('Fault did not fire');}catch(InjectedFault){}
    H::check($state->isUnknown() && $state->usage()->inputTokens===null && $state->usage()->outputTokens===null);
 },
 'fake.tenant-cache-reset'=>static function():void{
    $f=new Fixture();$a=$f->begin();$f->secrets->seed($f->ref($a,'credential'),'synthetic-alpha-key');$f->secrets->resolve($f->ref($a,'credential'));
    $block=$f->history($a);$f->history->append($a,$block);$f->history->read($a,$block->ref);
    $f->profile->reset();H::check($f->scope->isClear() && $f->secrets->isClear() && $f->history->isClear() && !$f->authority->hasCachedIdentity());
    $b=$f->begin('beta');H::rejects(fn()=>$f->secrets->resolve($f->ref($a,'credential')),ContractViolation::class);
    H::rejects(fn()=>$f->history->read($b,$block->ref),ContractViolation::class);
    H::rejects(fn()=>$f->payloads->get($a,$f->ref($a,'payload')),ContractViolation::class);
    $f->secrets->seed($f->ref($b,'credential'),'synthetic-beta-key');H::check($f->secrets->resolve($f->ref($b,'credential'))==='synthetic-beta-key');
    H::check(!str_contains(json_encode($f->secrets,JSON_THROW_ON_ERROR),'synthetic-alpha-key'));
    $f->profile->reset();H::check($f->scope->isClear() && $f->secrets->isClear());
 }
];
H::run($cases,'contract-scripted','contract-scripted');
