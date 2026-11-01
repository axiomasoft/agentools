<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult,LookupStatus,OwnerPort,Request,Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Testing\{Failpoints,FaultPoint};

/** Stateful scripted OWNER, not a handler/lifecycle replacement or exactly-once qualification. */
final class FakeOwner implements OwnerPort
{
    public int $writes=0;
    private ?string $cache=null;
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state,
        public readonly Failpoints $faults,private readonly EffectBoundary $boundary=new RejectEffects()) {}
    public function namespace(): string { return 'test.composition-owner'; }
    public function strategy(): Strategy { return Strategy::NativeKey; }
    public function execute(Request $request): LookupResult
    {
        $this->check($request);$this->boundary->assertAccepted($request);
        $saved=$this->state->get($request->action->tenant,'effects',$request->operationKey);
        if($saved!==null){$saved->hash->assertSame($request->requestHash);if(!$saved->value instanceof LookupResult){throw new \LogicException('Invalid effect fact');}
            $saved->value->assertRequest($request);return $saved->value;}
        $this->faults->hit(FaultPoint::BeforeExternal);
        $result=new LookupResult(LookupStatus::Succeeded,new Ref($request->action->tenant,$request->operationKey),$this->namespace(),$request->requestHash,
            new Ref($request->action->tenant,'test:effect:'.substr($request->requestHash->value,8)));
        $this->state->put($request->action->tenant,'effects',$request->operationKey,$request->requestHash,$result);
        ++$this->writes;$this->cache=$request->operationKey;$this->faults->hit(FaultPoint::AfterExternal);return $result;
    }
    public function lookup(Request $request): LookupResult
    {
        $this->check($request);$saved=$this->state->get($request->action->tenant,'effects',$request->operationKey);
        if($saved===null){return new LookupResult(LookupStatus::Unknown,new Ref($request->action->tenant,$request->operationKey),$this->namespace(),$request->requestHash);}
        $saved->hash->assertSame($request->requestHash);if(!$saved->value instanceof LookupResult){throw new \LogicException('Invalid effect fact');}
        $saved->value->assertRequest($request);return $saved->value;
    }
    private function check(Request $request): void
    {
        $this->scope->assertContext($request->context,'owner');
        if($request->ownerNamespace!==$this->namespace() || $request->strategy!==$this->strategy()){throw new ContractViolation(ErrorCode::PolicyConflict);}
    }
    public function reset(): void { $this->cache=null;$this->boundary->reset(); }
    public function isClear(): bool { return $this->cache===null; }
}
