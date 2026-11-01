<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Testing;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};
use AgenToolsPlatform\Core\Security\{ExecutionContext,TrustedInput};
use AgenToolsPlatform\Laravel\Definitions\{Availability,Reference};
use AgenToolsPlatform\Laravel\Plugins\{Contribution,DataSink,HookInput,Plugin};
use AgenToolsPlatform\Testing\Composition\{ExternalState,FakeHistory,Scope};

/** Consumer bridge: typed hook ports over the same current scope and history owner. */
final class HookDoubles implements Availability,Plugin,DataSink
{
    /** @var list<string> */ private array $cache=[];
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state,private readonly FakeHistory $history) {}
    public function id(): string { return 'test.observer'; }
    public function revoke(ExecutionContext $context,Reference $reference): void
    {
        $reference->owner->assertVisible($context->identity->tenant);
        $this->state->put($context->identity->tenant,'hook-revoked',$reference->key(),
            $this->scope->hasher->hash($context->identity->tenant,'test-hook-revoke',$reference->toArray()),true);
    }
    public function assertCurrent(ExecutionContext $context,Reference $reference): void
    {
        $this->scope->assertContext($context,'history.read');$reference->owner->assertVisible($context->identity->tenant);
        if($this->state->get($context->identity->tenant,'hook-revoked',$reference->key())!==null){throw new ContractViolation(ErrorCode::AccessDenied);}
        $this->cache[]=$reference->key();
    }
    public function apply(HookInput $input): Contribution
    {
        $c=$this->scope->current('history.read');$c->session->assertSame($input->session);
        if($c->purpose!==$input->purpose){throw new ContractViolation(ErrorCode::AccessDenied);}
        $this->cache[]=$input->inputRef->value;
        return new Contribution();
    }
    public function accept(TrustedInput $input,Contribution $contribution): void
    {
        $this->scope->assertContext($input->context,'history.read');
        foreach($contribution->fragments as $fragment){
            $block=$this->history->read($input->context,$fragment->ref);
            $block->hash->assertSame($fragment->hash);
            if($fragment->trust!=='untrusted_data'){throw new ContractViolation(ErrorCode::AccessDenied);}
            $this->cache[]=$fragment->ref->value;
        }
    }
    public function reset(): void { $this->cache=[]; }
    public function isClear(): bool { return $this->cache===[]; }
}
