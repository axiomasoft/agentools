<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

final readonly class StoredResultDigest
{
    public function __construct(public string $value)
    {
        if (preg_match('/^sha-256:[0-9a-f]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Stored result digest must be a sha-256 digest.');
        }
    }

    public static function fromJcs(string $canonicalResult): self
    {
        return new self('sha-256:' . hash('sha256', $canonicalResult));
    }
}
