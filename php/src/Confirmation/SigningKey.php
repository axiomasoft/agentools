<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

/**
 * Active HS256 signing material. Verify-only/compromised/retired keys cannot be constructed here.
 *
 * The secret is hidden from stack traces and debug dumps; never log or serialize this object.
 */
final readonly class SigningKey
{
    public function __construct(
        public KeyId $id,
        public string $algorithm,
        #[\SensitiveParameter]
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

    /** @return array{id: KeyId, algorithm: string, secret: string, state: KeyState} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'algorithm' => $this->algorithm, 'secret' => '[redacted]', 'state' => $this->state];
    }
}
