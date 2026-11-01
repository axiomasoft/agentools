<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Optional adapter lookup by the original invocation/request identity, never generate/replay.
 * Non-null is a verified bound outcome. Unsupported or inconclusive lookup returns null.
 */
interface ProviderLookup
{
    public function find(ExecutionContext $context, InvocationPlan $plan): ?ProviderOutcome;
}
