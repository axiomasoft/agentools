<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyAcquisition;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use AgenTools\Idempotency\StoredToolResultCodec;
use DateTimeImmutable;
use PDO;

/**
 * SQLite fenced store. Implements the P4 CAS methods for the reference slice.
 * Not a durable multi-worker arbiter; P4.3/P4.4 close on PostgreSQL evidence.
 */
final class SqliteIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DeduplicationPolicy $policy,
    ) {
    }

    public function acquire(
        IdempotencyRequest $request,
        DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyAcquisition {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        $this->pdo->beginTransaction();
        try {
            $key = $request->scope->storageKey();
            $record = $this->findRecord($key);
            if ($record !== null && ($record['state'] ?? '') === 'completed' && $this->stringField($record, 'expires_at') <= $this->time($now)) {
                $this->pdo->prepare('DELETE FROM idempotency_records WHERE storage_key = :storage_key AND state = \'completed\'')
                    ->execute(['storage_key' => $key]);
                $record = null;
            }
            if ($record === null) {
                $lease = IdempotencyLease::issue($request, $now->add(new \DateInterval('PT' . $seconds . 'S')));
                $this->insertInProgress($request, $lease, $now);
                $this->pdo->commit();

                return new IdempotencyAcquisition(DeduplicationState::New, $lease);
            }
            if (($record['fingerprint'] ?? '') !== $request->argumentsFingerprint->value) {
                throw IdempotencyFailure::keyReuse($request->scope->key->value);
            }
            if (($record['state'] ?? '') === 'completed') {
                $decoded = StoredToolResultCodec::decode($this->stringField($record, 'result_json'), $this->stringField($record, 'digest'));
                $this->pdo->commit();

                return new IdempotencyAcquisition(
                    DeduplicationState::DuplicateCompleted,
                    storedResult: $decoded['result'],
                    storedResultDigest: $decoded['digest'],
                );
            }
            $leaseUntil = new DateTimeImmutable($this->stringField($record, 'lease_until'));
            if ($leaseUntil > $now) {
                $this->pdo->commit();
                $failure = IdempotencyFailure::inFlight($request->scope->key->value);

                return new IdempotencyAcquisition(DeduplicationState::ConcurrentInFlight, error: $failure->error);
            }
            $attempt = $this->intField($record, 'attempt') + 1;
            $lease = IdempotencyLease::issue($request, $now->add(new \DateInterval('PT' . $seconds . 'S')), $attempt);
            $this->pdo->prepare(
                'UPDATE idempotency_records SET lease_token = :lease_token, attempt = :attempt, lease_until = :lease_until
                 WHERE storage_key = :storage_key AND state = \'in_progress\'',
            )->execute([
                'lease_token' => $lease->token,
                'attempt' => $attempt,
                'lease_until' => $this->time($lease->leaseUntil),
                'storage_key' => $key,
            ]);
            $this->pdo->commit();

            return new IdempotencyAcquisition(DeduplicationState::New, $lease, takeover: true);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function heartbeat(
        IdempotencyLease $lease,
        DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyLease {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        $until = $now->add(new \DateInterval('PT' . $seconds . 'S'));
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_records SET lease_until = :lease_until
             WHERE storage_key = :storage_key AND state = \'in_progress\' AND lease_token = :lease_token',
        );
        $statement->execute([
            'lease_until' => $this->time($until),
            'storage_key' => $lease->request->scope->storageKey(),
            'lease_token' => $lease->token,
        ]);
        if ($statement->rowCount() !== 1) {
            throw IdempotencyFailure::lostLease();
        }

        return new IdempotencyLease($lease->request, $lease->token, $lease->attempt, $until);
    }

    public function complete(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        DateTimeImmutable $now,
    ): void {
        $this->finish($lease, $result, $digest);
    }

    public function fail(
        IdempotencyLease $lease,
        StoredToolResult $result,
        StoredResultDigest $digest,
        DateTimeImmutable $now,
    ): void {
        $this->finish($lease, $result, $digest);
    }

    public function cleanup(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM idempotency_records WHERE state = \'completed\' AND expires_at < :now',
        );
        $statement->execute(['now' => $this->time($now)]);

        return $statement->rowCount();
    }

    private function finish(IdempotencyLease $lease, StoredToolResult $result, StoredResultDigest $digest): void
    {
        $encoded = StoredToolResultCodec::encode($result, $digest);
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_records
             SET state = \'completed\', result_json = :result_json, digest = :digest, lease_token = NULL, lease_until = NULL
             WHERE storage_key = :storage_key AND state = \'in_progress\' AND lease_token = :lease_token',
        );
        $statement->execute([
            'result_json' => $encoded['result_json'],
            'digest' => $encoded['digest'],
            'storage_key' => $lease->request->scope->storageKey(),
            'lease_token' => $lease->token,
        ]);
        if ($statement->rowCount() !== 1) {
            throw IdempotencyFailure::lostLease();
        }
    }

    /** @return array<mixed, mixed>|null */
    private function findRecord(string $storageKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM idempotency_records WHERE storage_key = :storage_key LIMIT 1');
        $statement->execute(['storage_key' => $storageKey]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function insertInProgress(IdempotencyRequest $request, IdempotencyLease $lease, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO idempotency_records (
                storage_key, tenant_id, tool_name, idempotency_key, fingerprint, state,
                lease_token, lease_until, attempt, expires_at, result_json, digest
            ) VALUES (
                :storage_key, :tenant_id, :tool_name, :idempotency_key, :fingerprint, \'in_progress\',
                :lease_token, :lease_until, :attempt, :expires_at, NULL, NULL
            )',
        );
        $statement->execute([
            'storage_key' => $request->scope->storageKey(),
            'tenant_id' => $request->scope->tenantId,
            'tool_name' => $request->scope->toolName,
            'idempotency_key' => $request->scope->key->value,
            'fingerprint' => $request->argumentsFingerprint->value,
            'lease_token' => $lease->token,
            'lease_until' => $this->time($lease->leaseUntil),
            'attempt' => $lease->attempt,
            'expires_at' => $this->time($now->add($this->policy->retention)),
        ]);
    }

    private function time(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
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
