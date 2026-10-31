<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Claims;

use AgenToolsPlatform\Postgres\Runtime\StoredRun;

/** A CAS capability, not permission to execute or repeat an external effect. */
final readonly class RunClaim
{
    public function __construct(public StoredRun $state, public string $owner, public ?string $leaseUntil)
    {
    }
}
