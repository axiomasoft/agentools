<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, TenantId};
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class Registry
{
    /** @var array<string,Definition> */
    private array $definitions;
    /** @param list<Definition> $definitions */
    public function __construct(array $definitions)
    {
        $entries=[];
        foreach ($definitions as $definition) {
            if (isset($entries[$definition->ref->key()])) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
            $entries[$definition->ref->key()]=$definition;
        }
        ksort($entries,SORT_STRING);$this->definitions=$entries;
    }
    public function resolve(Reference $ref,TenantId $tenant): Definition
    {
        $ref->owner->assertVisible($tenant);
        $definition=$this->definitions[$ref->key()]??throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $ref->version->hash->assertSame($definition->ref->version->hash);
        return $definition;
    }
    public function current(Reference $ref,ExecutionContext $context,Availability $availability): Definition
    {
        $definition=$this->resolve($ref,$context->identity->tenant);
        $availability->assertCurrent($context,$ref);
        if (!in_array($context->purpose,$definition->restrictions->purposes,true)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return $definition;
    }
}
