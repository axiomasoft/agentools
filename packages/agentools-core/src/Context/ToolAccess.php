<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Current intersection of host/bundle/skill tool permissions. Unknown must deny. */
interface ToolAccess
{
    public function permits(ExecutionContext $context, string $toolId): bool;
}
