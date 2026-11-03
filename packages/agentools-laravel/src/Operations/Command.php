<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** No actor/tenant CLI flags: the host supplies an authenticated context. */
final readonly class Command
{
    public string $resource;
    public function __construct(public ExecutionContext $context, public Ref $id, public string $verb,
        public string $target, public string $reason, public bool $apply = false,
        public int $limit = 50, public ?string $after = null, ?string $resource = null)
    {
        $context->session->tenant->assertSame($id->tenant);
        $this->resource=$resource??match($verb) { 'relay'=>'delivery','prune'=>'payload','doctor'=>'deployment',default=>'run' };
        if (!in_array($this->resource,match($verb) { 'relay'=>['delivery','usage'],'prune'=>['payload'],'doctor'=>['deployment'],default=>['run'] },true)) {
            throw new \InvalidArgumentException('Invalid operation resource');
        }
        if (!in_array($verb, ['doctor','inspect-run','recover','reconcile','relay','prune'], true)
            || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $reason) !== 1
            || $limit < 1 || $limit > 100 || strlen($target) > 256 || strlen($after ?? '') > 256
            || ($target === '' && $verb !== 'doctor') || ($apply && in_array($verb,['doctor','inspect-run'],true))) {
            throw new \InvalidArgumentException('Explicit bounded operation required');
        }
    }

    /** @return array<string,mixed> */
    public function identity(): array
    {
        return ['resource'=>$this->resource,'verb'=>$this->verb,'target'=>$this->target,'reason'=>$this->reason,'apply'=>$this->apply,
            'limit'=>$this->limit,'after'=>$this->after];
    }
}
