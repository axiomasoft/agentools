<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{DeliveryIntent,Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Testing\{Failpoints,FaultPoint,ScriptedDisclosure};

/** Core delivery values + the existing disclosure helper; bridge to a host delivery port in its tests. */
final class FakeDelivery
{
    public int $sent=0;
    public bool $loseAcknowledgement=false;
    private ?string $cache=null;
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state,
        private readonly ScriptedDisclosure $disclosure,public readonly Failpoints $faults) {}
    public function send(ExecutionContext $context,DeliveryIntent $intent): DeliveryResult
    {
        $this->scope->assertContext($context,'delivery',$intent->destination);$context->identity->tenant->assertSame($intent->id->tenant);
        $this->disclosure->release($context,$intent->responseRef,$intent->requestHash);
        $saved=$this->state->get($intent->id->tenant,'deliveries',$intent->id->value);
        if($saved!==null){$saved->hash->assertSame($intent->requestHash);if(!$saved->value instanceof DeliveryFact){throw new \LogicException('Invalid delivery fact');}
            $intent->assertReplay($saved->value->intent);return $this->ack($saved->value->result);}
        $this->faults->hit(FaultPoint::BeforeExternal);
        $result=new DeliveryResult($intent->id,new Ref($intent->id->tenant,'test:delivery:'.substr($intent->requestHash->value,8)),1);
        $this->state->put($intent->id->tenant,'deliveries',$intent->id->value,$intent->requestHash,new DeliveryFact($intent,$result));++$this->sent;
        $this->cache=$intent->id->value;$this->faults->hit(FaultPoint::AfterExternal);return $this->ack($result);
    }
    private function ack(DeliveryResult $result): DeliveryResult { return $this->loseAcknowledgement?new DeliveryResult($result->id,null,null):$result; }
    public function reset(): void { $this->cache=null;$this->disclosure->reset(); }
    public function isClear(): bool { return $this->cache===null; }
}
