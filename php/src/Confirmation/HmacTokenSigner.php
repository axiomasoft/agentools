<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\TokenSigner;

/**
 * HMAC-SHA256 implementation; the secret is supplied by the consuming runtime.
 *
 * The secret must carry at least 32 bytes, the HS256 floor also enforced by SigningKey, and is
 * hidden from stack traces and debug dumps.
 */
final readonly class HmacTokenSigner implements TokenSigner
{
    private const MIN_SECRET_BYTES = 32;

    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException('The token-signing secret must be at least 32 bytes.');
        }
    }

    /** @return array{secret: string} */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]'];
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }
}
