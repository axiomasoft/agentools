<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Contracts\Clock;

final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $fixed)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->fixed;
    }
}
