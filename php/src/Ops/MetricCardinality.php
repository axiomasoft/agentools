<?php

declare(strict_types=1);

namespace AgenTools\Ops;

/** Closed low-cardinality metric labels for the supported journal path. */
final class MetricCardinality
{
    /** @param array<string, string> $labels */
    public function assertAllowed(array $labels): void
    {
        (new \AgenTools\Persistence\MetricCardinality())->assertAllowed($labels);
    }
}
