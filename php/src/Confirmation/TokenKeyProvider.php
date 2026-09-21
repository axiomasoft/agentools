<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

interface TokenKeyProvider
{
    public function signingKey(string $tenantId, TokenPurpose $purpose): SigningKey;

    public function verificationKey(string $tenantId, TokenPurpose $purpose, KeyId $kid): ?VerificationKey;
}
