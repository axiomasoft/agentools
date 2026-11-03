<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Budget;

/** Optional Tariffler/host mapping. No lookup support means unresolved/manual, never blind retry.
 * Look up using operation.request.operationKey (the original admission key) and exact operation payload.
 */
interface StatusPort
{
    public function lookup(Operation $operation): OwnerResolution;
}
