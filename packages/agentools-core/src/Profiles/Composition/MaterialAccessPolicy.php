<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Profiles\{ResourceRef, UseBoundary};

/** Required current host policy; bind it to the authenticated job context. No permissive implementation. */
interface MaterialAccessPolicy
{
    public function assertCurrent(TenantId $tenant, ResourceRef $material, UseBoundary $boundary): void;
}
