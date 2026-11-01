<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{TenantId, ContractViolation, ErrorCode};

/** Explicit tagged ownership; absence of a tenant is never an implicit platform grant. */
final readonly class OwnerScope
{
    private function __construct(public string $kind, public ?TenantId $tenant) {}
    public static function platform(): self { return new self('platform',null); }
    public static function tenant(TenantId $tenant): self { return new self('tenant',$tenant); }
    public function assertVisible(TenantId $tenant): void
    {
        if ($this->kind === 'tenant') { ($this->tenant ?? throw new ContractViolation(ErrorCode::AccessDenied))->assertSame($tenant); }
    }
    public function key(): string { return $this->kind.':'.($this->tenant===null?'trusted-code':$this->tenant->value); }
    /** @return array{kind:string,tenant_id?:string} */
    public function toArray(): array { return $this->tenant === null ? ['kind'=>'platform'] : ['kind'=>'tenant','tenant_id'=>$this->tenant->value]; }
}
