<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class SigningKey
{
    public function __construct(
        public KeyId $id,
        public string $algorithm,
        public string $secret,
        public KeyState $state = KeyState::Active,
    ) {
        if ($algorithm !== 'HS256') {
            throw new \InvalidArgumentException('Signing keys allow HS256 only.');
        }
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('HS256 keys must be at least 32 bytes.');
        }
        if ($state !== KeyState::Active) {
            throw ConfirmationFailure::keyDenied();
        }
    }
}
