<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Profiles\ResourceRef;

interface ManifestCatalog
{
    /** Metadata only, exact owner/id/version/hash; never read fragments or substitute latest. */
    public function get(ResourceRef $pin, TenantId $tenant): PublishedSkill;
}
