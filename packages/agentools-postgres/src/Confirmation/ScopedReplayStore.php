<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

use AgenTools\Confirmation\{PdoPostgresConfirmationTokenStore, TokenConsumeAdmission};
use AgenTools\Contracts\ConfirmationTokenStore;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use PDO;

/** Reuse the dated SQL replay gate on a dedicated connection, committing consumption before IO. */
final readonly class ScopedReplayStore implements ConfirmationTokenStore
{
    private Connection $db;
    private PdoPostgresConfirmationTokenStore $store;

    public function __construct(private PDO $pdo, private string $schema)
    {
        $this->db = new Connection($pdo, $schema);
        $this->store = new PdoPostgresConfirmationTokenStore($pdo);
    }

    public function consume(string $tenantId, string $tokenId, string $tokenDigestHex, string $purpose,
        \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): TokenConsumeAdmission
    {
        return $this->db->transaction(function () use ($tenantId, $tokenId, $tokenDigestHex, $purpose, $expiresAt, $now): TokenConsumeAdmission {
            $this->scope();
            return $this->store->consume($tenantId, $tokenId, $tokenDigestHex, $purpose, $expiresAt, $now);
        });
    }

    public function refineOutcome(string $tenantId, string $tokenId, string $outcome): void
    {
        $this->db->transaction(function () use ($tenantId, $tokenId, $outcome): void {
            $this->scope();
            $this->store->refineOutcome($tenantId, $tokenId, $outcome);
        });
    }

    public function cleanup(\DateTimeImmutable $now, \DateInterval $retention): int
    {
        return $this->db->transaction(function () use ($now, $retention): int {
            $this->scope();
            return $this->store->cleanup($now, $retention);
        });
    }

    private function scope(): void
    {
        $this->pdo->exec('SET LOCAL search_path TO "' . $this->schema . '", pg_catalog');
    }
}
