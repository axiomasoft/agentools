<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Claims;

/** Recovery coordination is bounded; exhausting it never authorizes effect replay. */
final readonly class ClaimPolicy
{
    public function __construct(
        public int $leaseMilliseconds = 30000,
        public int $maxClaims = 8,
        public int $deadlineSeconds = 86400,
    ) {
        if ($leaseMilliseconds < 1 || $leaseMilliseconds > 3600000
            || $maxClaims < 1 || $maxClaims > 1000 || $deadlineSeconds < 1 || $deadlineSeconds > 2592000) {
            throw new \InvalidArgumentException('Bounded claim policy required');
        }
    }
}
