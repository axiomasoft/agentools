<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

final readonly class Projection
{
    /**
     * @param list<UsageEvent> $history
     * @param array<string,UsageEvent> $active
     */
    public function __construct(public array $history, public array $active) {}
}
