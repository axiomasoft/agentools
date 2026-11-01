<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Host authorizes, encrypts, audits and enforces TTL; no default implementation. */
interface Diagnostics
{
    public function capture(ExecutionContext $context, CompiledContext $compiled, UtcInstant $expiresAt): DiagnosticRef;
}
