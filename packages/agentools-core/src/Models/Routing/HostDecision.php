<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Models\Support;

final readonly class HostDecision
{
    public function __construct(public Support $legal, public Support $permission, public Support $quota) {}
}
