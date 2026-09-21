<?php

declare(strict_types=1);

namespace AgenTools\TestKit;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\TokenConsumeAdmission;
use AgenTools\Confirmation\TokenEnvelope;
use AgenTools\Contracts\ConfirmationTokenStore;

/** Testkit/reference only. Not a production constructor. */
final class InMemoryConfirmationTokenStore implements ConfirmationTokenStore
{
    /** @var array<string, array{digest: string, purpose: string, outcome: string, expiresAt: \DateTimeImmutable, presentedAt: \DateTimeImmutable}> */
    private array $rows = [];

    public function consume(
        string $tenantId,
        string $tokenId,
        string $tokenDigestHex,
        string $purpose,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): TokenConsumeAdmission {
        $key = $tenantId . "\0" . $tokenId;
        if (!isset($this->rows[$key])) {
            $this->rows[$key] = [
                'digest' => $tokenDigestHex,
                'purpose' => $purpose,
                'outcome' => 'presented',
                'expiresAt' => $expiresAt,
                'presentedAt' => $now,
            ];
            return TokenConsumeAdmission::Accepted;
        }
        if ($this->rows[$key]['digest'] !== $tokenDigestHex) {
            throw ConfirmationFailure::collision();
        }
        throw ConfirmationFailure::spent();
    }

    public function refineOutcome(string $tenantId, string $tokenId, string $outcome): void
    {
        $key = $tenantId . "\0" . $tokenId;
        $row = $this->rows[$key] ?? null;
        if ($row === null || $row['outcome'] !== 'presented') {
            return;
        }
        if (!in_array($outcome, ['authorized', 'authorization_denied', 'policy_denied'], true)) {
            throw new \InvalidArgumentException('Invalid consume outcome.');
        }
        $row['outcome'] = $outcome;
        $this->rows[$key] = $row;
    }

    public function cleanup(\DateTimeImmutable $now, \DateInterval $retention): int
    {
        $origin = new \DateTimeImmutable('@0');
        $seconds = $origin->add($retention)->getTimestamp() - $origin->getTimestamp();
        if ($seconds < TokenEnvelope::MIN_RETENTION_SECONDS) {
            throw new \InvalidArgumentException('Confirmation token retention must cover the maximum clock skew.');
        }
        $removed = 0;
        foreach ($this->rows as $key => $row) {
            if ($row['expiresAt']->add($retention) < $now) {
                unset($this->rows[$key]);
                $removed++;
            }
        }
        return $removed;
    }
}
