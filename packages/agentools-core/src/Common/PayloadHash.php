<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final readonly class PayloadHash
{
    public function __construct(public string $value)
    {
        if (preg_match('/^sha-256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected a SHA-256 digest');
        }
    }

    public function assertSame(self $other): void
    {
        if (!hash_equals($this->value, $other->value)) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Identity payload hash conflict');
        }
    }
}
