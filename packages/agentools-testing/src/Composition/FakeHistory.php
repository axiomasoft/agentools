<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Core\Context\{ContextAccess,ContextBlock};
use AgenToolsPlatform\Core\Models\Time;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Testing\Models\PreciseClock;

/** Synthetic history owner over the real ContextAccess contract. Source text stays data. */
final class FakeHistory implements ContextAccess
{
    /** @var list<string> */ private array $cache=[];
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state,private readonly PreciseClock $clock) {}
    public function append(ExecutionContext $context,ContextBlock $block): void
    {
        $this->scope->assertContext($context,'history.write',$block->source);$context->identity->tenant->assertSame($block->ref->tenant);
        if($block->trust!=='untrusted' || $block->purpose!==$context->purpose){throw new ContractViolation(ErrorCode::AccessDenied);}
        $hash=$this->scope->hasher->hash($block->ref->tenant,'test-history-record',$block->manifest());
        $this->state->put($block->ref->tenant,'history',$block->ref->value,$hash,$block);
    }
    public function read(ExecutionContext $context,Ref $id): ContextBlock
    {
        $this->scope->assertContext($context,'history.read');$context->identity->tenant->assertSame($id->tenant);
        $block=$this->state->get($id->tenant,'history',$id->value)?->value;
        if(!$block instanceof ContextBlock || !$this->permits($context,$block)){throw new ContractViolation(ErrorCode::AccessDenied);}
        $this->cache[]=$id->value;return $block;
    }
    public function permits(ExecutionContext $context,ContextBlock $block): bool
    {
        try{$this->scope->assertContext($context,'history.read',$block->source);}
        catch(ContractViolation){return false;}
        return $block->purpose===$context->purpose && Time::compare($this->clock->now(),$block->expiresAt)<0;
    }
    public function reset(): void { $this->cache=[]; }
    public function isClear(): bool { return $this->cache===[]; }
}
