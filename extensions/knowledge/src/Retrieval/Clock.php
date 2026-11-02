<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

interface Clock
{
    /** Unix seconds, host-owned clock. */
    public function now(): int;
}
