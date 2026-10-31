<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Models;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Independent qualified clock for these transport tests; does not use ManualClock. */
final class PreciseClock
{
    private \DateTimeImmutable $value;
    public function __construct(UtcInstant $initial) { $this->value = new \DateTimeImmutable($initial->value); }
    public function now(): UtcInstant { return new UtcInstant($this->value->format('Y-m-d\TH:i:s.u\Z')); }
    public function advanceMicroseconds(int $microseconds): void
    {
        if ($microseconds < 0) { throw new \InvalidArgumentException('Clock cannot move backwards'); }
        $this->value = $this->value->modify('+' . $microseconds . ' microseconds');
    }
}
