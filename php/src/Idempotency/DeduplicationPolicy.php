<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

/** Independent retention, confirmation, and client-retry durations. */
final readonly class DeduplicationPolicy
{
    public function __construct(
        public \DateInterval $retention,
        public \DateInterval $confirmationLifetime,
        public \DateInterval $clientRetryWindow,
    ) {
        $origin = new \DateTimeImmutable('@0');
        if ($origin->add($retention) < $origin->add($confirmationLifetime)->add($clientRetryWindow)) {
            throw new \InvalidArgumentException('Deduplication retention must cover confirmation lifetime plus the client retry window.');
        }
    }
}
