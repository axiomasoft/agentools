<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

/** Persisted resource bounds. Dispatch is sequential, including read tools. */
final readonly class ContinuationPolicy
{
    public function __construct(public int $maxInvocations = 4, public int $maxTools = 16,
        public int $maxRepeatedErrors = 2, public int $maxParallelReads = 1, public int $maxEstimatedUnits = 100000)
    {
        if ($maxInvocations < 1 || $maxInvocations > 64 || $maxTools < 0 || $maxTools > 256
            || $maxRepeatedErrors < 1 || $maxRepeatedErrors > 16 || $maxParallelReads !== 1
            || $maxEstimatedUnits < 0 || $maxEstimatedUnits > 9007199254740991) {
            throw new \InvalidArgumentException('Bounded serial continuation required');
        }
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['maxInvocations' => $this->maxInvocations, 'maxTools' => $this->maxTools,
            'maxRepeatedErrors' => $this->maxRepeatedErrors, 'maxParallelReads' => $this->maxParallelReads,
            'maxEstimatedUnits' => $this->maxEstimatedUnits];
    }
}
