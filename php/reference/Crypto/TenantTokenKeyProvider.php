<?php

declare(strict_types=1);

namespace AgenTools\Reference\Crypto;

use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\KeyState;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenKeyProvider;
use AgenTools\Confirmation\TokenPurpose;
use AgenTools\Confirmation\VerificationKey;

/** Tenant-scoped HS256 key provider for the reference slice. */
final readonly class TenantTokenKeyProvider implements TokenKeyProvider
{
    private KeyId $kid;

    public function __construct(private string $tenantId, private string $secret)
    {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('The tenant id must not be empty.');
        }
        if ($secret === '') {
            throw new \InvalidArgumentException('The token-signing secret must not be empty.');
        }
        $this->kid = new KeyId('tenant-' . $tenantId);
    }

    public function signingKey(string $tenantId, TokenPurpose $purpose): SigningKey
    {
        if ($tenantId !== $this->tenantId) {
            throw new \InvalidArgumentException('Tenant id does not match this key provider.');
        }

        $material = strlen($this->secret) >= 32 ? $this->secret : hash('sha256', $this->secret, true);

        return new SigningKey($this->kid, 'HS256', $material);
    }

    public function verificationKey(string $tenantId, TokenPurpose $purpose, KeyId $kid): ?VerificationKey
    {
        if ($tenantId !== $this->tenantId || $kid->value !== $this->kid->value) {
            return null;
        }

        $material = strlen($this->secret) >= 32 ? $this->secret : hash('sha256', $this->secret, true);

        return new VerificationKey($this->kid, 'HS256', $material, KeyState::Active);
    }
}
