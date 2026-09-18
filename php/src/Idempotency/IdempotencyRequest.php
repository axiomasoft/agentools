<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

final readonly class IdempotencyRequest
{
    public function __construct(
        public IdempotencyScope $scope,
        public ArgumentsFingerprint $argumentsFingerprint,
        public string $actionId,
    ) {
        if ($actionId === '') {
            throw new \InvalidArgumentException('Action id must not be empty.');
        }
    }
}
