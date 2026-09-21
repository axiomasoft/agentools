<?php

declare(strict_types=1);

namespace AgenTools\TestKit;

use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\KeyState;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenKeyProvider;
use AgenTools\Confirmation\TokenPurpose;
use AgenTools\Confirmation\VerificationKey;

/** Single-key provider for tests and reference contours. */
final readonly class StaticTokenKeyProvider implements TokenKeyProvider
{
    public function __construct(private SigningKey $signing)
    {
    }

    public function signingKey(string $tenantId, TokenPurpose $purpose): SigningKey
    {
        return $this->signing;
    }

    public function verificationKey(string $tenantId, TokenPurpose $purpose, KeyId $kid): ?VerificationKey
    {
        if ($kid->value !== $this->signing->id->value) {
            return null;
        }

        return new VerificationKey(
            $this->signing->id,
            $this->signing->algorithm,
            $this->signing->secret,
            KeyState::Active,
        );
    }
}
