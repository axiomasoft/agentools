<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

final readonly class Observation
{
    public function __construct(public ?UsageEvent $event, public bool $telemetryOnly) {}
}
