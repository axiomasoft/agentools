<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Host-owned snapshot freshness/visibility lookup; no SQL lock may be held. */
interface ContextRevision
{
    public function current(ExecutionContext $context, Ref $snapshot): int;
}
