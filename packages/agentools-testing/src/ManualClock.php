<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Test time only, never reads the system clock. */
final class ManualClock
{
    public function __construct(private UtcInstant $instant) {}
    public function now(): UtcInstant { return $this->instant; }
    public function advanceSeconds(int $seconds): void
    {
        if ($seconds < 0) { throw new \InvalidArgumentException('Clock must advance monotonically'); }
        $next = (new \DateTimeImmutable($this->instant->value))->add(new \DateInterval('PT' . $seconds . 'S'));
        $this->instant = new UtcInstant($next->format('Y-m-d\TH:i:s.u\Z'));
    }
    public function advanceMicroseconds(int $microseconds): void
    {
        if ($microseconds < 0) { throw new \InvalidArgumentException('Clock must advance monotonically'); }
        $next = (new \DateTimeImmutable($this->instant->value))->modify('+' . $microseconds . ' microseconds');
        $this->instant = new UtcInstant($next->format('Y-m-d\TH:i:s.u\Z'));
    }
}
