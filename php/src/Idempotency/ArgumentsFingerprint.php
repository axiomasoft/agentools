<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

/** SHA-256 fingerprint of JCS canonicalized domain arguments. */
final readonly class ArgumentsFingerprint
{
    public function __construct(public string $value)
    {
        if (preg_match('/^sha-256:[0-9a-f]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Argument fingerprint must be a sha-256 digest.');
        }
    }

    public static function fromJcs(string $canonicalArguments): self
    {
        return new self('sha-256:' . hash('sha256', $canonicalArguments));
    }
}
