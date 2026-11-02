<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

/** Technical append/outbox port. Production adapter shares the invocation result transaction; external metering remains owner-owned. */
interface UsagePort
{
    /** Same identity/content returns same acknowledgement; conflicting content quarantines with UsageConflict. */
    public function append(UsageEvent $event): string;
}
