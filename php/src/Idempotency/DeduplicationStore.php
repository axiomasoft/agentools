<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

interface DeduplicationStore
{
    public function resolve(IdempotencyRequest $request, \DateTimeImmutable $now): DeduplicationResolution;

    public function complete(
        IdempotencyRequest $request,
        StoredToolResult $result,
        StoredResultDigest $digest,
    ): void;
}
