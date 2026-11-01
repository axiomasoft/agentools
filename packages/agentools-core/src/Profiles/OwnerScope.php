<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\TenantId;

/** Platform ownership is an explicit tag, never a missing tenant. */
final readonly class OwnerScope
{
    private function __construct(public string $kind, public ?TenantId $tenant) {}

    public static function platform(): self { return new \AgenToolsPlatform\Core\Profiles\OwnerScope('platform', null); }
    public static function tenant(TenantId $tenant): self { return new \AgenToolsPlatform\Core\Profiles\OwnerScope('tenant', $tenant); }

    public function assertVisible(TenantId $tenant): void
    {
        $this->tenant?->assertSame($tenant);
    }

    /** @return array{kind: string, tenant_id?: string} */
    public function toArray(): array
    {
        return $this->tenant === null ? ['kind' => 'platform'] : ['kind' => 'tenant', 'tenant_id' => $this->tenant->value];
    }
}
