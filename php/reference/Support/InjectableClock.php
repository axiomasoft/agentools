<?php

declare(strict_types=1);

namespace AgenTools\Reference\Support;

use AgenTools\Contracts\Clock;
use AgenTools\Persistence\FixedClock;

/** Test-friendly clock that can be advanced without touching system time. */
final class InjectableClock implements Clock
{
    private FixedClock $inner;

    public function __construct(?\DateTimeImmutable $initial = null)
    {
        $this->inner = new FixedClock($initial ?? new \DateTimeImmutable('2026-08-05T10:00:00+00:00'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->inner->now();
    }

    public function set(\DateTimeImmutable $instant): void
    {
        $this->inner = new FixedClock($instant);
    }

    public function advance(\DateInterval|string $delta): void
    {
        $interval = is_string($delta) ? new \DateInterval($delta) : $delta;
        $this->inner = new FixedClock($this->inner->now()->add($interval));
    }
}
