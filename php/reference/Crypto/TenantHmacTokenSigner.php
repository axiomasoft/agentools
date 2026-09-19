<?php

declare(strict_types=1);

namespace AgenTools\Reference\Crypto;

use AgenTools\Contracts\TokenSigner;

/** Tenant-scoped HMAC signer; each instance owns one tenant secret, not a global key. */
final readonly class TenantHmacTokenSigner implements TokenSigner
{
    public function __construct(private string $tenantId, private string $secret)
    {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('The tenant id must not be empty.');
        }
        if ($secret === '') {
            throw new \InvalidArgumentException('The token-signing secret must not be empty.');
        }
    }

    public function tenantId(): string
    {
        return $this->tenantId;
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
