<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationResolution;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use DateTimeImmutable;
use PDO;

/** SQLite deduplication store with atomic resolve inserts. */
final class SqliteIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DeduplicationPolicy $policy,
    ) {
    }

    public function resolve(IdempotencyRequest $request, DateTimeImmutable $now): DeduplicationResolution
    {
        $key = $request->scope->storageKey();
        $this->pdo->beginTransaction();
        try {
            $this->deleteExpired($now);
            $record = $this->findRecord($key);
            if ($record === null) {
                $this->insertInFlight($request, $now);
                $this->pdo->commit();
                return new DeduplicationResolution(DeduplicationState::New);
            }

            if ($record['fingerprint'] !== $request->argumentsFingerprint->value) {
                throw new IdempotencyFailure(new ToolError(
                    ToolErrorCode::IdempotencyKeyReuse->value,
                    ErrorCategory::VALIDATION,
                    false,
                    'The idempotency key was reused with different arguments.',
                    context: ['idempotency_key' => $request->scope->key->value],
                ));
            }

            if ($record['result_json'] === null) {
                $this->pdo->commit();
                return new DeduplicationResolution(DeduplicationState::ConcurrentInFlight, error: new ToolError(
                    ToolErrorCode::IdempotencyInFlight->value,
                    ErrorCategory::CONFLICT,
                    true,
                    'Work for this idempotency key is already in flight.',
                    context: ['idempotency_key' => $request->scope->key->value],
                ));
            }

            $decoded = StoredToolResultCodec::decode(RowCodec::string($record, 'result_json'), RowCodec::string($record, 'digest'));
            $this->pdo->commit();
            return new DeduplicationResolution(
                DeduplicationState::DuplicateCompleted,
                $decoded['result'],
                $decoded['digest'],
            );
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function complete(IdempotencyRequest $request, StoredToolResult $result, StoredResultDigest $digest): void
    {
        $key = $request->scope->storageKey();
        $encoded = StoredToolResultCodec::encode($result, $digest);
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_records
             SET result_json = :result_json, digest = :digest
             WHERE storage_key = :storage_key AND fingerprint = :fingerprint AND result_json IS NULL',
        );
        $statement->execute([
            'result_json' => $encoded['result_json'],
            'digest' => $encoded['digest'],
            'storage_key' => $key,
            'fingerprint' => $request->argumentsFingerprint->value,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \LogicException('Only the original in-flight request can complete a deduplication record.');
        }
    }

    private function deleteExpired(DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare('DELETE FROM idempotency_records WHERE expires_at <= :now');
        $statement->execute(['now' => $now->format('Y-m-d\TH:i:s\Z')]);
    }

    /** @return array<mixed, mixed>|null */
    private function findRecord(string $storageKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM idempotency_records WHERE storage_key = :storage_key LIMIT 1');
        $statement->execute(['storage_key' => $storageKey]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function insertInFlight(IdempotencyRequest $request, DateTimeImmutable $now): void
    {
        $expiresAt = $now->add($this->policy->retention)->format('Y-m-d\TH:i:s\Z');
        $statement = $this->pdo->prepare(
            'INSERT INTO idempotency_records (
                storage_key, tenant_id, tool_name, idempotency_key, fingerprint, expires_at, result_json, digest
            ) VALUES (
                :storage_key, :tenant_id, :tool_name, :idempotency_key, :fingerprint, :expires_at, NULL, NULL
            )',
        );
        $statement->execute([
            'storage_key' => $request->scope->storageKey(),
            'tenant_id' => $request->scope->tenantId,
            'tool_name' => $request->scope->toolName,
            'idempotency_key' => $request->scope->key->value,
            'fingerprint' => $request->argumentsFingerprint->value,
            'expires_at' => $expiresAt,
        ]);
    }
}
