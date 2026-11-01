<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, TenantId};

final readonly class LayeredCatalog implements \AgenToolsPlatform\Core\Profiles\DependencyCatalog
{
    public function __construct(private CodeRegistry $global, private DependencyCatalog $tenantOverlay) {}

    public function resolve(ResourceRef $ref, TenantId $tenant): Dependency
    {
        $ref->owner->assertVisible($tenant);
        $entry = $ref->owner->kind === 'platform'
            ? $this->global->resolve($ref, $tenant) : $this->tenantOverlay->resolve($ref, $tenant);
        if ($entry->ref->key() !== $ref->key()) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Dependency identity mismatch');
        }
        $ref->version->hash->assertSame($entry->ref->version->hash);
        return $entry;
    }
}
