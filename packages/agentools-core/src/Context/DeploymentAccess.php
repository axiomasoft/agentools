<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Current host constraints/capability snapshot, independent of provider SDKs. Unknown must deny. */
interface DeploymentAccess
{
    public function permits(ContextPlan $plan, ContextTarget $target, UtcInstant $now): bool;
}
