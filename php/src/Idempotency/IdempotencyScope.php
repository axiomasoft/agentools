<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

/** The unique deduplication scope is exactly tenant, tool name, and caller key. */
final readonly class IdempotencyScope
{
    public function __construct(
        public string $tenantId,
        public string $toolName,
        public IdempotencyKey $key,
    ) {
        if ($tenantId === '' || $toolName === '') {
            throw new \InvalidArgumentException('Tenant id and tool name must not be empty.');
        }
    }

    public function storageKey(): string
    {
        return $this->tenantId . "\0" . $this->toolName . "\0" . $this->key->value;
    }
}
