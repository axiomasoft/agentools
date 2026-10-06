<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\ConfirmationTokenStore;

final class PdoPostgresConfirmationTokenStore implements ConfirmationTokenStore
{
    private const CONSUME_SQL = <<<'SQL'
INSERT INTO confirmation_consumed_tokens (
  tenant_id, token_id, token_digest, purpose, outcome, expires_at, presented_at
) VALUES (
  :tenant_id, :token_id, decode(:token_digest, 'hex'), :purpose, 'presented', :expires_at, :now
)
ON CONFLICT (tenant_id, token_id) DO NOTHING
RETURNING token_id
SQL;

    private const SELECT_DIGEST_SQL = <<<'SQL'
SELECT encode(token_digest, 'hex') AS token_digest
FROM confirmation_consumed_tokens
WHERE tenant_id = :tenant_id AND token_id = :token_id
SQL;

    private const REFINE_SQL = <<<'SQL'
UPDATE confirmation_consumed_tokens
SET outcome = :outcome
WHERE tenant_id = :tenant_id AND token_id = :token_id AND outcome = 'presented'
SQL;

    private const CLEANUP_SQL = <<<'SQL'
DELETE FROM confirmation_consumed_tokens
WHERE expires_at + make_interval(secs => :retention_seconds) < :now
SQL;

    public function __construct(private readonly \PDO $pdo)
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('PdoPostgresConfirmationTokenStore requires a PDO connection in ERRMODE_EXCEPTION.');
        }
    }

    public function consume(
        string $tenantId,
        string $tokenId,
        string $tokenDigestHex,
        string $purpose,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): TokenConsumeAdmission {
        $statement = $this->pdo->prepare(self::CONSUME_SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'token_id' => $tokenId,
            'token_digest' => $tokenDigestHex,
            'purpose' => $purpose,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'now' => $now->format(\DateTimeInterface::ATOM),
        ]);
        $returned = $statement->fetchColumn();
        if (is_string($returned) && $returned === $tokenId) {
            return TokenConsumeAdmission::Accepted;
        }
        $existing = $this->pdo->prepare(self::SELECT_DIGEST_SQL);
        $existing->execute(['tenant_id' => $tenantId, 'token_id' => $tokenId]);
        $digest = $existing->fetchColumn();
        if (is_string($digest) && !hash_equals(strtolower($digest), strtolower($tokenDigestHex))) {
            throw ConfirmationFailure::collision();
        }
        throw ConfirmationFailure::spent();
    }

    public function refineOutcome(string $tenantId, string $tokenId, string $outcome): void
    {
        if (!in_array($outcome, ['authorized', 'authorization_denied', 'policy_denied'], true)) {
            throw new \InvalidArgumentException('Invalid consume outcome.');
        }
        $statement = $this->pdo->prepare(self::REFINE_SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'token_id' => $tokenId,
            'outcome' => $outcome,
        ]);
    }

    public function cleanup(\DateTimeImmutable $now, \DateInterval $retention): int
    {
        $origin = new \DateTimeImmutable('@0');
        $seconds = $origin->add($retention)->getTimestamp() - $origin->getTimestamp();
        if ($seconds < TokenEnvelope::MIN_RETENTION_SECONDS) {
            throw new \InvalidArgumentException('Confirmation token retention must cover the maximum clock skew.');
        }
        $statement = $this->pdo->prepare(self::CLEANUP_SQL);
        $statement->execute([
            'retention_seconds' => $seconds,
            'now' => $now->format(\DateTimeInterface::ATOM),
        ]);
        return $statement->rowCount();
    }
}
