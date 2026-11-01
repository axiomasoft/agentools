<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\{Deployment,EventKind,ModelEvent,ModelRequest,ModelResponse,ModelTransport,TransportPreflight};
use AgenToolsPlatform\Core\Runtime\{InvocationPlan,ProviderLookup,ProviderOutcome};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Testing\{Failpoints,FaultPoint};
use AgenToolsPlatform\Testing\Models\PreciseClock;

/** Proposal-only transport, with original-request lookup and no effect callback or tool loop. */
final class FakeTransport implements ModelTransport,ProviderLookup
{
    public int $exchanges=0;
    private ?string $cache=null;
    public function __construct(private readonly Scope $scope,private readonly FakeBudget $budget,private readonly ExternalState $state,
        private readonly PreciseClock $clock,public readonly Failpoints $faults,private readonly ?FakeSecrets $secrets=null) {}
    public function enqueue(ModelResponse $response): void { $this->state->put($response->invocationId->tenant,'model-script',$response->invocationId->value,$this->scope->hasher->hash($response->invocationId->tenant,'test-model-script',json_decode(json_encode($response,JSON_THROW_ON_ERROR),false,128,JSON_THROW_ON_ERROR)),$response); }
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    {
        TransportPreflight::check($request,$deployment,$this->clock->now());$this->budget->assertModel($request);
        $this->secrets?->resolve($request->credentialRef);
        $hash=RequestHash::model($request,$this->scope->hasher);$id=$request->invocationId;
        $saved=$this->state->get($id->tenant,'model',$id->value);
        if($saved!==null){$saved->hash->assertSame($hash);if(!$saved->value instanceof ModelFact){throw new \LogicException('Invalid synthetic model fact');}
            $saved->value->response->assertFor($request,$deployment);return $saved->value->response;}
        $reply=$this->state->get($id->tenant,'model-script',$id->value)?->value;
        if(!$reply instanceof ModelResponse){throw new \UnderflowException('Model script exhausted');}
        $reply->assertFor($request,$deployment);$this->faults->hit(FaultPoint::BeforeExternal);$this->budget->markSent($request);++$this->exchanges;
        $this->state->put($id->tenant,'model',$id->value,$hash,new ModelFact($deployment,$reply));$this->cache=$id->value;
        $this->faults->hit(FaultPoint::AfterExternal);return $reply;
    }
    public function stream(ModelRequest $request,Deployment $deployment): iterable
    {
        TransportPreflight::check($request,$deployment,$this->clock->now(),true);$this->budget->assertModel($request);
        yield new ModelEvent($request->invocationId,1,EventKind::Started);
        $reply=$this->generate($request,$deployment);$this->budget->assertModel($request);
        TransportPreflight::check($request,$deployment,$this->clock->now(),true);
        yield new ModelEvent($request->invocationId,2,EventKind::Terminal,response:$reply);
    }
    public function find(ExecutionContext $context,InvocationPlan $plan): ?ProviderOutcome
    {
        $this->scope->assertContext($context,'model',$plan->request->credentialRef);$this->budget->assertModel($plan->request,false);
        $saved=$this->state->get($plan->runId->tenant,'model',$plan->request->invocationId->value);
        if($saved===null){return null;}$saved->hash->assertSame($plan->requestHash);
        if(!$saved->value instanceof ModelFact){throw new \LogicException('Invalid synthetic model fact');}
        $saved->value->response->assertFor($plan->request,$plan->deployment);
        return new ProviderOutcome($saved->value->response,$saved->hash,new Ref($plan->runId->tenant,'test:model-receipt:'.$plan->request->invocationId->value));
    }
    public function reset(): void { $this->cache=null; }
    public function isClear(): bool { return $this->cache===null; }
}
