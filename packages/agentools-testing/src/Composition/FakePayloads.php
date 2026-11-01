<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** In-process synthetic owner facts, explicitly NOT production encrypted/durable persistence. */
final class FakePayloads implements InvocationPayloads
{
    private ?string $cache=null;
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state) {}
    public function put(ExecutionContext $context,Ref $key,PayloadHash $hash,#[\SensitiveParameter] string $json): void
    {
        $this->scope->assertContext($context,'payload.write');$context->identity->tenant->assertSame($key->tenant);
        $hash->assertSame(new PayloadHash('sha-256:'.hash('sha256',$json)));
        $record=$this->state->put($key->tenant,'payload',$key->value,$hash,new PayloadFact($context->session,$context->scopeHash($this->scope->hasher),$json));
        $this->binding($context,$record);$this->cache=$key->value;
    }
    public function find(ExecutionContext $context,Ref $key): ?string
    {
        $this->scope->assertContext($context,'payload.read');$context->identity->tenant->assertSame($key->tenant);
        $saved=$this->state->get($key->tenant,'payload',$key->value);if($saved===null){return null;}
        $fact=$this->binding($context,$saved);$saved->hash->assertSame(new PayloadHash('sha-256:'.hash('sha256',$fact->json)));
        $this->cache=$key->value;return $fact->json;
    }
    public function get(ExecutionContext $context,Ref $key): string { return $this->find($context,$key)??throw new \UnderflowException('Missing synthetic payload'); }
    private function binding(ExecutionContext $context,Record $record): PayloadFact
    {
        if(!$record->value instanceof PayloadFact){throw new \LogicException('Invalid synthetic payload fact');}
        $context->session->assertSame($record->value->session);$context->scopeHash($this->scope->hasher)->assertSame($record->value->scope);return $record->value;
    }
    public function reset(): void { $this->cache=null; }
    public function isClear(): bool { return $this->cache===null; }
}
