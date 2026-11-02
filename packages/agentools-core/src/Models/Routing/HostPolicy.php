<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Trusted current host policy and quota, never model-provided permissions. */
interface HostPolicy
{
    public function check(RouteRequest $request, CatalogEntry $entry, UtcInstant $now): HostDecision;
}
