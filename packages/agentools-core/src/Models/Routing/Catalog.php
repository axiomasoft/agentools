<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\TenantId;

interface Catalog { public function view(TenantId $tenant): CatalogView; }
