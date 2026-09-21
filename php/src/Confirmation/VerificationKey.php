<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class VerificationKey
{
    public function __construct(
        public KeyId $id,
        public string $algorithm,
        public string $secret,
        public KeyState $state,
    ) {
        if ($algorithm !== 'HS256') {
            throw new \InvalidArgumentException('Verification keys allow HS256 only.');
        }
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('HS256 keys must be at least 32 bytes.');
        }
    }

    public function canVerify(): bool
    {
        return $this->state === KeyState::Active || $this->state === KeyState::VerifyOnly;
    }
}
