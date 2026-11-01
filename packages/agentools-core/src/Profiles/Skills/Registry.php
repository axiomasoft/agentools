<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, TenantId};
use AgenToolsPlatform\Core\Profiles\ResourceRef;

/** Global manifests are installed from trusted code; tenant publication metadata is a separate overlay. */
final readonly class Registry implements \AgenToolsPlatform\Core\Profiles\Skills\ManifestCatalog
{
    /**
     * @var array<string, PublishedSkill>
     */ private array $global;
    /**
     * @param list<PublishedSkill> $global
     */
    public function __construct(array $global, private ManifestCatalog $tenantOverlay)
    {
        $indexed=[];
        foreach ($global as $entry) {
            $ref=$entry->manifest->dependency->ref;
            if ($ref->owner->kind !== 'platform' || isset($indexed[$ref->key()])) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Distinct trusted platform skill versions required'); }
            $indexed[$ref->key()]=$entry;
        }
        $this->global=$indexed;
    }
    public function get(ResourceRef $pin, TenantId $tenant): PublishedSkill
    {
        $pin->owner->assertVisible($tenant);
        $entry=$pin->owner->kind === 'platform'
            ? ($this->global[$pin->key()] ?? throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('MANIFEST_UNKNOWN','unknown'))
            : $this->tenantOverlay->get($pin,$tenant);
        $actual=$entry->manifest->dependency->ref;
        if ($actual->toArray() !== $pin->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('HASH_MISMATCH'); }
        $entry->qualification->ref->owner->assertVisible($tenant); return $entry;
    }
}
