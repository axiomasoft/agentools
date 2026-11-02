<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\Integrity;

/** Host-owned payload/view references. This package neither stores raw tool tables nor resolves these refs. */
final readonly class OwnerViews
{
    public function __construct(public string $tenant, public string $raw, public string $user, public string $audit)
    {
        foreach ([$tenant, $raw, $user, $audit] as $value) { Integrity::text($value); }
    }
}
