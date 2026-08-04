<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\TokenSigner;

/** HMAC implementation; the secret is supplied by the consuming runtime. */
final readonly class HmacTokenSigner implements TokenSigner
{
    public function __construct(private string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('The token-signing secret must not be empty.');
        }
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
