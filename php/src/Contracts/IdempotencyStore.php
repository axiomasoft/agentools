<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Idempotency\IdempotencyAcquisition;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;

/** Fenced deduplication persistence. Production stores must CAS on lease_token. */
interface IdempotencyStore
{
    public function acquire(
        IdempotencyRequest $request,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyAcquisition;

    public function heartbeat(
        IdempotencyLease $lease,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyLease;

    public function complete(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        \DateTimeImmutable $now,
    ): void;

    public function fail(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        \DateTimeImmutable $now,
    ): void;

    public function cleanup(\DateTimeImmutable $now): int;
}
