<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\TokenConsumeAdmission;

interface ConfirmationTokenStore
{
    public function consume(
        string $tenantId,
        string $tokenId,
        string $tokenDigestHex,
        string $purpose,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): TokenConsumeAdmission;

    public function refineOutcome(string $tenantId, string $tokenId, string $outcome): void;

    public function cleanup(\DateTimeImmutable $now, \DateInterval $retention): int;
}
