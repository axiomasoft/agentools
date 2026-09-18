<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Idempotency\DeduplicationResolution;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;

/** Deduplication record persistence. Production stores must make resolve's unique-key insert atomic. */
interface IdempotencyStore
{
    public function resolve(IdempotencyRequest $request, \DateTimeImmutable $now): DeduplicationResolution;

    public function complete(
        IdempotencyRequest $request,
        StoredToolResult $result,
        StoredResultDigest $digest,
    ): void;
}
