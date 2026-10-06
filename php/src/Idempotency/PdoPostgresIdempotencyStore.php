<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Contracts\IdempotencyStore;
use PDO;

final class PdoPostgresIdempotencyStore implements IdempotencyStore
{
    private const INSERT_SQL = <<<'SQL'
INSERT INTO agentools_idempotency_leases (
  tenant_id, tool_name, idempotency_key, fingerprint, state,
  lease_token, lease_until, attempt, result_json, result_digest, expires_at
) VALUES (
  :tenant_id, :tool_name, :idempotency_key, :fingerprint, 'in_progress',
  :lease_token, :lease_until, 1, NULL, NULL, :expires_at
)
ON CONFLICT (tenant_id, tool_name, idempotency_key) DO NOTHING
RETURNING tenant_id, attempt, lease_token, lease_until
SQL;

    private const SELECT_SQL = <<<'SQL'
SELECT fingerprint, state, lease_token, lease_until, attempt, result_json, result_digest, expires_at
FROM agentools_idempotency_leases
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
SQL;

    private const TAKEOVER_SQL = <<<'SQL'
UPDATE agentools_idempotency_leases
SET lease_token = :lease_token, attempt = attempt + 1, lease_until = :lease_until
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
  AND state = 'in_progress' AND lease_until <= :now
RETURNING attempt, lease_token, lease_until
SQL;

    private const DELETE_COMPLETED_SQL = <<<'SQL'
DELETE FROM agentools_idempotency_leases
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
  AND state = 'completed' AND expires_at <= :now
SQL;

    private const HEARTBEAT_SQL = <<<'SQL'
UPDATE agentools_idempotency_leases
SET lease_until = :lease_until
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
  AND state = 'in_progress' AND lease_token = :lease_token
RETURNING lease_until
SQL;

    private const FINISH_SQL = <<<'SQL'
UPDATE agentools_idempotency_leases
SET state = 'completed', result_json = :result_json, result_digest = :result_digest,
    lease_token = NULL, lease_until = NULL
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
  AND state = 'in_progress' AND lease_token = :lease_token
RETURNING tenant_id
SQL;

    private const CLEANUP_SQL = <<<'SQL'
DELETE FROM agentools_idempotency_leases
WHERE state = 'completed' AND expires_at < :now
SQL;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DeduplicationPolicy $policy,
    ) {
        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('PdoPostgresIdempotencyStore requires a PDO connection in ERRMODE_EXCEPTION.');
        }
    }

    public function acquire(
        IdempotencyRequest $request,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyAcquisition {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        for ($i = 0; $i < 3; $i++) {
            $result = $this->attemptAcquire($request, $now, $seconds);
            if ($result !== null) {
                return $result;
            }
        }

        throw new \RuntimeException('Idempotency acquire could not converge.');
    }

    public function heartbeat(
        IdempotencyLease $lease,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyLease {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        $until = $now->add(new \DateInterval('PT' . $seconds . 'S'));
        $statement = $this->pdo->prepare(self::HEARTBEAT_SQL);
        $statement->execute($this->scopeParams($lease->request) + [
            'lease_until' => $this->time($until),
            'lease_token' => $lease->token,
        ]);
        if ($statement->fetch() === false) {
            throw IdempotencyFailure::lostLease();
        }

        return new IdempotencyLease($lease->request, $lease->token, $lease->attempt, $until);
    }

    public function complete(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        \DateTimeImmutable $now,
    ): void {
        $this->finish($lease, $result, $digest);
    }

    public function fail(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        \DateTimeImmutable $now,
    ): void {
        $this->finish($lease, $result, $digest);
    }

    public function cleanup(\DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(self::CLEANUP_SQL);
        $statement->execute(['now' => $this->time($now)]);

        return $statement->rowCount();
    }

    private function attemptAcquire(
        IdempotencyRequest $request,
        \DateTimeImmutable $now,
        int $seconds,
    ): ?IdempotencyAcquisition {
        $leaseUntil = $now->add(new \DateInterval('PT' . $seconds . 'S'));
        $expiresAt = $now->add($this->policy->retention);
        $token = bin2hex(random_bytes(16));
        $insert = $this->pdo->prepare(self::INSERT_SQL);
        $insert->execute($this->scopeParams($request) + [
            'fingerprint' => $request->argumentsFingerprint->value,
            'lease_token' => $token,
            'lease_until' => $this->time($leaseUntil),
            'expires_at' => $this->time($expiresAt),
        ]);
        $inserted = $insert->fetch(PDO::FETCH_ASSOC);
        if (is_array($inserted)) {
            return new IdempotencyAcquisition(
                DeduplicationState::New,
                new IdempotencyLease($request, $token, 1, $leaseUntil),
            );
        }

        $select = $this->pdo->prepare(self::SELECT_SQL);
        $select->execute($this->scopeParams($request));
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (($row['fingerprint'] ?? '') !== $request->argumentsFingerprint->value) {
            throw IdempotencyFailure::keyReuse($request->scope->key->value);
        }
        if (($row['state'] ?? '') === 'completed') {
            $expires = new \DateTimeImmutable($this->stringField($row, 'expires_at'));
            if ($expires <= $now) {
                $delete = $this->pdo->prepare(self::DELETE_COMPLETED_SQL);
                $delete->execute($this->scopeParams($request) + ['now' => $this->time($now)]);

                return null;
            }
            $decoded = StoredToolResultCodec::decode($this->stringField($row, 'result_json'), $this->stringField($row, 'result_digest'));

            return new IdempotencyAcquisition(
                DeduplicationState::DuplicateCompleted,
                storedResult: $decoded['result'],
                storedResultDigest: $decoded['digest'],
            );
        }
        $leaseUntilExisting = new \DateTimeImmutable($this->stringField($row, 'lease_until'));
        if ($leaseUntilExisting > $now) {
            $failure = IdempotencyFailure::inFlight($request->scope->key->value);

            return new IdempotencyAcquisition(DeduplicationState::ConcurrentInFlight, error: $failure->error);
        }
        $takeoverToken = bin2hex(random_bytes(16));
        $takeoverUntil = $now->add(new \DateInterval('PT' . $seconds . 'S'));
        $takeover = $this->pdo->prepare(self::TAKEOVER_SQL);
        $takeover->execute($this->scopeParams($request) + [
            'lease_token' => $takeoverToken,
            'lease_until' => $this->time($takeoverUntil),
            'now' => $this->time($now),
        ]);
        $taken = $takeover->fetch(PDO::FETCH_ASSOC);
        if (!is_array($taken)) {
            return null;
        }

        return new IdempotencyAcquisition(
            DeduplicationState::New,
            new IdempotencyLease($request, $takeoverToken, $this->intField($taken, 'attempt'), $takeoverUntil),
            takeover: true,
        );
    }

    private function finish(IdempotencyLease $lease, StoredToolResult $result, StoredResultDigest $digest): void
    {
        $encoded = StoredToolResultCodec::encode($result, $digest);
        $statement = $this->pdo->prepare(self::FINISH_SQL);
        $statement->execute($this->scopeParams($lease->request) + [
            'result_json' => $encoded['result_json'],
            'result_digest' => $encoded['digest'],
            'lease_token' => $lease->token,
        ]);
        if ($statement->fetch() === false) {
            throw IdempotencyFailure::lostLease();
        }
    }

    /** @return array{tenant_id: string, tool_name: string, idempotency_key: string} */
    private function scopeParams(IdempotencyRequest $request): array
    {
        return [
            'tenant_id' => $request->scope->tenantId,
            'tool_name' => $request->scope->toolName,
            'idempotency_key' => $request->scope->key->value,
        ];
    }

    private function time(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    /** @param array<mixed, mixed> $row */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Idempotency row field %s must be a string.', $key));
        }

        return $value;
    }

    /** @param array<mixed, mixed> $row */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \RuntimeException(sprintf('Idempotency row field %s must be an integer.', $key));
    }

}
