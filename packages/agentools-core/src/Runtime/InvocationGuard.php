<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Current entitlement, emergency deployment switch, cancellation and a qualified clock. */
interface InvocationGuard
{
    public function now(): UtcInstant;
    public function assertCurrent(ExecutionContext $context, InvocationPlan $plan): void;
}
