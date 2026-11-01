<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, TenantId};

/** Constructed only by trusted composition code. DB tenant overlays never extend this registry. */
final readonly class CodeRegistry implements \AgenToolsPlatform\Core\Profiles\DependencyCatalog
{
    /** @var array<string, Dependency> */
    private array $entries;

    /** @param list<Dependency> $entries */
    public function __construct(array $entries)
    {
        $indexed = [];
        foreach ($entries as $entry) {
            if ($entry->ref->owner->kind !== 'platform' || isset($indexed[$entry->ref->key()])) {
                throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Only distinct explicit platform code registrations are allowed');
            }
            $indexed[$entry->ref->key()] = $entry;
        }
        $this->entries = $indexed;
    }

    public function resolve(ResourceRef $ref, TenantId $tenant): Dependency
    {
        $entry = $this->entries[$ref->key()] ?? throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable);
        $entry->ref->version->hash->assertSame($ref->version->hash);
        return $entry;
    }
}
