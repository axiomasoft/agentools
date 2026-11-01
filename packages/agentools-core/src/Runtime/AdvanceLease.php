<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\RunId;

/** Attempt fencing capability; not authorization. Refreshed after every committed mutation. */
final readonly class AdvanceLease
{
    public function __construct(public RunId $runId, public int $version, public int $epoch, public string $owner)
    {
        if ($version < 1 || $epoch < 1 || $owner === '') { throw new \InvalidArgumentException('Claim required'); }
    }
}
