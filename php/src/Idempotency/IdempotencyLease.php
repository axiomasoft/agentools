<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

/** Opaque fencing handle. Never log the token. */
final readonly class IdempotencyLease
{
    public function __construct(
        public IdempotencyRequest $request,
        public string $token,
        public int $attempt,
        public \DateTimeImmutable $leaseUntil,
    ) {
        if ($token === '' || $attempt < 1) {
            throw new \InvalidArgumentException('Lease token must be nonempty and attempt must be >= 1.');
        }
    }

    public static function issue(IdempotencyRequest $request, \DateTimeImmutable $leaseUntil, int $attempt = 1): self
    {
        return new self($request, bin2hex(random_bytes(16)), $attempt, $leaseUntil);
    }
}
