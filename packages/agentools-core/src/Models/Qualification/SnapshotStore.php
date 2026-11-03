<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{PayloadHash, TenantId, UtcInstant};
interface SnapshotStore
{
    /** Latest issued assessment, including rejection/expiry; no fallback to older green. */
    public function latest(TenantId $tenant, PayloadHash $tupleHash, UtcInstant $now): ?Snapshot;
}
