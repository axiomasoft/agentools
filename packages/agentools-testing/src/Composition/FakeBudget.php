<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Budget\{Admission,AdmissionRequest,BudgetPort};
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\{ModelRequest,Time};
use AgenToolsPlatform\Testing\{Failpoints,FaultPoint};
use AgenToolsPlatform\Testing\Models\PreciseClock;

final class FakeBudget implements BudgetPort
{
    public int $reservations=0;
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state,
        private readonly PreciseClock $clock,private readonly UtcInstant $expires,public readonly Failpoints $faults) {}
    public function enqueue(Ref $operation,string $outcome): void
    {
        if(!in_array($outcome,['admitted','denied','unknown','unavailable'],true)){throw new \InvalidArgumentException('Explicit admission outcome required');}
        $this->state->put($operation->tenant,'budget-script',$operation->value,$this->scope->hasher->hash($operation->tenant,'test-budget-script',$outcome),$outcome);
    }
    public function status(AdmissionRequest $request): Admission
    {
        $c=$this->scope->current('budget');$c->identity->tenant->assertSame($request->operationKey->tenant);
        $request->hash->assertSame($this->scope->hasher->hash($request->operationKey->tenant,'invocation-admission',$request->content));
        if(($request->content['context_scope']??$request->content['scope_hash']??null)!==$c->scopeHash($this->scope->hasher)->value){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        $saved=$this->state->get($request->operationKey->tenant,'budget',$request->operationKey->value);
        if($saved===null){return new Admission('not_found');}$saved->hash->assertSame($request->hash);
        if(!$saved->value instanceof BudgetFact){throw new \LogicException('Invalid synthetic budget fact');}
        return $saved->value->outcome;
    }
    public function admit(AdmissionRequest $request): Admission
    {
        $saved=$this->status($request);if($saved->status!=='not_found'){return $saved;}
        $this->faults->hit(FaultPoint::BeforeExternal);$script=$this->state->get($request->operationKey->tenant,'budget-script',$request->operationKey->value);$status=$script===null?'denied':$script->value;
        if(!is_string($status)){throw new \LogicException('Invalid budget script');}
        $t=$request->operationKey->tenant;$key=substr($request->hash->value,8);
        $outcome=$status==='admitted'?new Admission('admitted',new Ref($t,'test:admission:'.$key),new Ref($t,'test:reservation:'.$key),$this->expires,'scripted-owner-v1'):new Admission($status);
        $this->state->put($t,'budget',$request->operationKey->value,$request->hash,new BudgetFact($request,$outcome));
        if($outcome->reservationRef!==null){$this->state->put($t,'reservations',$outcome->reservationRef->value,$request->hash,new BudgetFact($request,$outcome));}
        if($status==='admitted'){++$this->reservations;}$this->faults->hit(FaultPoint::AfterExternal);return $outcome;
    }
    public function assertModel(ModelRequest $request,bool $spend=true): void
    {
        $c=$this->scope->current('model',$request->credentialRef);$c->identity->tenant->assertSame($request->invocationId->tenant);
        $fact=$this->state->get($request->invocationId->tenant,'budget','admit:'.$request->invocationId->value)?->value;
        if(!$fact instanceof BudgetFact || $fact->outcome->status!=='admitted' || $fact->outcome->expiresAt===null
            || ($spend && Time::compare($this->clock->now(),$fact->outcome->expiresAt)>=0)){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        $fact->request->hash->assertSame($this->scope->hasher->hash($request->invocationId->tenant,'invocation-admission',$fact->request->content));
        if($spend && $fact->outcome->reservationRef!==null && $this->state->get($request->invocationId->tenant,'release',$fact->outcome->reservationRef->value)!==null){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        $plan=$fact->request->content['plan']??null;
        if(!is_array($plan) || ($plan['invocation_id']??null)!==$request->invocationId->value
            || ($plan['request_hash']??null)!==RequestHash::model($request,$this->scope->hasher)->value
            || ($fact->request->content['context_scope']??null)!==$c->scopeHash($this->scope->hasher)->value){throw new ContractViolation(ErrorCode::AdmissionDenied);}
    }
    public function markSent(ModelRequest $request): void
    {
        $this->assertModel($request);$t=$request->invocationId->tenant;
        $fact=$this->state->get($t,'budget','admit:'.$request->invocationId->value)?->value;
        if(!$fact instanceof BudgetFact || $fact->outcome->reservationRef===null){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        $this->state->put($t,'sent-reservations',$fact->outcome->reservationRef->value,$fact->request->hash,true);
    }
    public function settle(Ref $reservation,Ref $usageEvent): void
    {
        $this->reservation($reservation);$reservation->tenant->assertSame($usageEvent->tenant);
        $this->state->put($reservation->tenant,'settlement',$usageEvent->value,
            $this->scope->hasher->hash($reservation->tenant,'test-settlement',[$reservation->toArray(),$usageEvent->toArray()]),true);
    }
    public function release(Ref $reservation,string $provenNotSentReason): void
    {
        $this->reservation($reservation);
        if($provenNotSentReason==='' || $this->state->get($reservation->tenant,'sent-reservations',$reservation->value)!==null){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        $this->state->put($reservation->tenant,'release',$reservation->value,
            $this->scope->hasher->hash($reservation->tenant,'test-release',[$reservation->toArray(),$provenNotSentReason]),true);
    }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { $this->scope->current('budget');throw new ContractViolation(ErrorCode::CapabilityUnavailable); }
    private function reservation(Ref $reservation): BudgetFact
    {
        $c=$this->scope->current('budget');$c->identity->tenant->assertSame($reservation->tenant);
        $fact=$this->state->get($reservation->tenant,'reservations',$reservation->value)?->value;
        if(!$fact instanceof BudgetFact || ($fact->request->content['context_scope']??$fact->request->content['scope_hash']??null)!==$c->scopeHash($this->scope->hasher)->value){throw new ContractViolation(ErrorCode::AdmissionDenied);}
        return $fact;
    }
    public function reset(): void { /* Owner facts and explicitly queued test script survive client reset. */ }
}
