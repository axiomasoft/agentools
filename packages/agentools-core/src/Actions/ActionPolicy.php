<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Security\ExecutionContext;

interface ActionPolicy
{
    /** Recheck current owner permissions, revocation/emergency guards and availability over pins. */
    public function current(ExecutionContext $context): ActionAccess;
}
