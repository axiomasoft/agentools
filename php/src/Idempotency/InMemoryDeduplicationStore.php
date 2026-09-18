<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;

/**
 * Process-local reference implementation. Production stores must make resolve's
 * unique-key insert atomic (tenant, tool_name, key).
 */
final class InMemoryDeduplicationStore implements DeduplicationStore
{
    /** @var array<string, array{fingerprint: ArgumentsFingerprint, expiresAt: \DateTimeImmutable, result: ?StoredToolResult, digest: ?StoredResultDigest}> */
    private array $records = [];

    public function __construct(private readonly DeduplicationPolicy $policy)
    {
    }

    public function resolve(IdempotencyRequest $request, \DateTimeImmutable $now): DeduplicationResolution
    {
        $key = $request->scope->storageKey();
        $record = $this->records[$key] ?? null;
        if ($record !== null && $record['expiresAt'] <= $now) {
            unset($this->records[$key]);
            $record = null;
        }
        if ($record === null) {
            $this->records[$key] = [
                'fingerprint' => $request->argumentsFingerprint,
                'expiresAt' => $now->add($this->policy->retention),
                'result' => null,
                'digest' => null,
            ];
            return new DeduplicationResolution(DeduplicationState::New);
        }
        if ($record['fingerprint']->value !== $request->argumentsFingerprint->value) {
            throw new IdempotencyFailure(new ToolError(
                ToolErrorCode::IdempotencyKeyReuse->value,
                ErrorCategory::VALIDATION,
                false,
                'The idempotency key was reused with different arguments.',
                context: ['idempotency_key' => $request->scope->key->value],
            ));
        }
        if ($record['result'] === null) {
            return new DeduplicationResolution(DeduplicationState::ConcurrentInFlight, error: new ToolError(
                ToolErrorCode::IdempotencyInFlight->value,
                ErrorCategory::CONFLICT,
                true,
                'Work for this idempotency key is already in flight.',
                context: ['idempotency_key' => $request->scope->key->value],
            ));
        }
        return new DeduplicationResolution(DeduplicationState::DuplicateCompleted, $record['result'], $record['digest']);
    }

    public function complete(IdempotencyRequest $request, StoredToolResult $result, StoredResultDigest $digest): void
    {
        $key = $request->scope->storageKey();
        $record = $this->records[$key] ?? null;
        if ($record === null || $record['fingerprint']->value !== $request->argumentsFingerprint->value) {
            throw new \LogicException('Only the original in-flight request can complete a deduplication record.');
        }
        if ($record['result'] !== null) {
            throw new \LogicException('A deduplication record can only be completed once.');
        }
        $record['result'] = $result;
        $record['digest'] = $digest;
        $this->records[$key] = $record;
    }
}
