<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

/** Host-approved bounded export policy. No implicit production allowance. */
final readonly class BacklogPolicy
{
    public function __construct(public int $maxPending, public int $maxAgeSeconds, public int $maxAttempts,
        public int $leaseSeconds = 30, public int $retrySeconds = 5)
    {
        if ($maxPending < 1 || $maxAgeSeconds < 1 || $maxAttempts < 1 || $leaseSeconds < 1 || $retrySeconds < 1
            || max($maxPending,$maxAgeSeconds,$maxAttempts,$leaseSeconds,$retrySeconds) > 1000000) {
            throw new \InvalidArgumentException('Explicit bounded accounting policy required');
        }
    }
}
