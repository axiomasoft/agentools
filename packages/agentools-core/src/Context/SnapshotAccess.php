<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Current exact snapshot dependency, owner permission and emergency guard check, even for a cache hit. */
interface SnapshotAccess
{
    public function permits(ExecutionContext $context, ContextSnapshot $snapshot): bool;
}
