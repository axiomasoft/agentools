<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Current owner revocation/emergency gate, applied on top of immutable installed pins. */
interface Availability
{
    public function assertCurrent(ExecutionContext $context, Reference $reference): void;
    /** Clear transient tenant/policy/credential caches; never change authoritative revocations. */
    public function reset(): void;
}
