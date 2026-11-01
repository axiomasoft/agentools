<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};

/** Client cache over test-control synthetic secret-owner facts; input refs never grant resolution. */
final class FakeSecrets
{
    /** @var array<string,string> */ private array $cache=[];
    public function __construct(private readonly Scope $scope,private readonly ExternalState $state) {}
    public function seed(Ref $ref,#[\SensitiveParameter] string $value): void
    {
        $this->state->put($ref->tenant,'secrets',$ref->value,$this->scope->hasher->hash($ref->tenant,'test-secret',$value),$value);
    }
    public function resolve(Ref $ref): string
    {
        $this->scope->current('secret.resolve',$ref);$value=$this->state->get($ref->tenant,'secrets',$ref->value)?->value;
        if(!is_string($value)){throw new ContractViolation(ErrorCode::AccessDenied);}
        return $this->cache[$ref->value]=$value;
    }
    public function reset(): void { $this->cache=[]; }
    public function isClear(): bool { return $this->cache===[]; }
    public function __debugInfo(): array { return ['credentials'=>'[redacted]']; }
}
