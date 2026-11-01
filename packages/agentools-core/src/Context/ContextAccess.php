<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Current owner disclosure/consent/revoke policy, checked at build and finalization. */
interface ContextAccess
{
    public function permits(ExecutionContext $context, ContextBlock $block): bool;
}
