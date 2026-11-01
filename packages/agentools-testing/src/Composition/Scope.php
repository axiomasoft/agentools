<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Core\Security\{ExecutionContext,HostAuthorization};

/** Active client frame only. No permissions can be supplied by a model/input/plugin flag. */
final class Scope
{
    private ?ExecutionContext $context=null;
    /** @var list<string> */ private array $operations=[];
    /** @var array<string,list<string>> */ private array $resources=[];
    public function __construct(private readonly HostAuthorization $host,public readonly CanonicalHasher $hasher) {}
    /**
     * @param list<string> $operations
     * @param array<string,list<Ref>> $resources Resource grants are specific to each operation.
     */
    public function begin(ExecutionContext $context,array $operations,array $resources): void
    {
        $this->reset();$context->assertCurrent($this->host);
        $grants=[];foreach($resources as $operation=>$refs){
            if(!in_array($operation,$operations,true)){throw new ContractViolation(ErrorCode::AccessDenied);}
            foreach($refs as $ref){$context->identity->tenant->assertSame($ref->tenant);}
            $grants[$operation]=array_map(static fn(Ref $r):string=>$r->value,$refs);
        }
        $this->context=$context;$this->operations=$operations;$this->resources=$grants;
    }
    public function current(string $operation,?Ref $resource=null): ExecutionContext
    {
        $c=$this->context??throw new ContractViolation(ErrorCode::Unauthenticated);$c->assertCurrent($this->host);
        if(!in_array($operation,$this->operations,true)){throw new ContractViolation(ErrorCode::AccessDenied);}
        if($resource!==null){$c->identity->tenant->assertSame($resource->tenant);if(!in_array($resource->value,$this->resources[$operation]??[],true)){throw new ContractViolation(ErrorCode::AccessDenied);}}
        return $c;
    }
    public function assertContext(ExecutionContext $context,string $operation,?Ref $resource=null): void
    {
        $current=$this->current($operation,$resource);$current->session->assertSame($context->session);
        $current->scopeHash($this->hasher)->assertSame($context->scopeHash($this->hasher));$context->assertCurrent($this->host);
    }
    public function reset(): void { $this->context=null;$this->operations=[];$this->resources=[]; }
    public function isClear(): bool { return $this->context===null && $this->operations===[] && $this->resources===[]; }
    public function __debugInfo(): array { return ['active'=>$this->context!==null]; }
}
