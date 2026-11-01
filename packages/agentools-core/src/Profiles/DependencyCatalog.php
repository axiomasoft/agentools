<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

use AgenToolsPlatform\Core\Common\TenantId;

interface DependencyCatalog
{
    /** Resolve exact installed content/provenance; missing/unknown is an error, never an empty success. */
    public function resolve(ResourceRef $ref, TenantId $tenant): Dependency;
}
