<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\UtcInstant;

final readonly class Batch
{
    /** @param list<UtcInstant> $slots */
    public function __construct(public Schedule $schedule, public array $slots) {}
}
