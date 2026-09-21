<?php

declare(strict_types=1);

namespace AgenTools\TestKit;

use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyAcquisition;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;

/** Process-local reference implementation. Testkit only; not a production constructor. */
final class InMemoryDeduplicationStore implements IdempotencyStore
{
    /**
     * @var array<string, array{
     *     fingerprint: ArgumentsFingerprint,
     *     state: string,
     *     lease: ?IdempotencyLease,
     *     expiresAt: \DateTimeImmutable,
     *     result: ?StoredToolResult,
     *     digest: ?StoredResultDigest
     * }>
     */
    private array $records = [];

    public function __construct(private readonly DeduplicationPolicy $policy)
    {
    }

    public function acquire(
        IdempotencyRequest $request,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyAcquisition {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        $key = $request->scope->storageKey();
        $record = $this->records[$key] ?? null;
        if ($record !== null && $record['state'] === 'completed' && $record['expiresAt'] <= $now) {
            unset($this->records[$key]);
            $record = null;
        }
        if ($record === null) {
            $lease = IdempotencyLease::issue($request, $now->add(new \DateInterval('PT' . $seconds . 'S')));
            $this->records[$key] = $this->inProgress($request, $lease, $now);

            return new IdempotencyAcquisition(DeduplicationState::New, $lease);
        }
        if ($record['fingerprint']->value !== $request->argumentsFingerprint->value) {
            throw IdempotencyFailure::keyReuse($request->scope->key->value);
        }
        if ($record['state'] === 'completed') {
            return new IdempotencyAcquisition(
                DeduplicationState::DuplicateCompleted,
                storedResult: $record['result'],
                storedResultDigest: $record['digest'],
            );
        }
        $existing = $record['lease'];
        if ($existing !== null && $existing->leaseUntil > $now) {
            $failure = IdempotencyFailure::inFlight($request->scope->key->value);

            return new IdempotencyAcquisition(DeduplicationState::ConcurrentInFlight, error: $failure->error);
        }
        $attempt = ($existing === null ? 1 : $existing->attempt) + 1;
        $lease = IdempotencyLease::issue($request, $now->add(new \DateInterval('PT' . $seconds . 'S')), $attempt);
        $this->records[$key]['lease'] = $lease;

        return new IdempotencyAcquisition(DeduplicationState::New, $lease, takeover: true);
    }

    public function heartbeat(
        IdempotencyLease $lease,
        \DateTimeImmutable $now,
        \DateInterval $leaseDuration,
    ): IdempotencyLease {
        $seconds = LeaseDuration::assertValid($leaseDuration);
        $record = $this->requireLease($lease);
        $extended = new IdempotencyLease(
            $lease->request,
            $lease->token,
            $lease->attempt,
            $now->add(new \DateInterval('PT' . $seconds . 'S')),
        );
        $record['lease'] = $extended;
        $this->records[$lease->request->scope->storageKey()] = $record;

        return $extended;
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
        $removed = 0;
        foreach ($this->records as $key => $record) {
            if ($record['state'] === 'completed' && $record['expiresAt'] < $now) {
                unset($this->records[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{
     *     fingerprint: ArgumentsFingerprint,
     *     state: string,
     *     lease: ?IdempotencyLease,
     *     expiresAt: \DateTimeImmutable,
     *     result: ?StoredToolResult,
     *     digest: ?StoredResultDigest
     * }
     */
    private function inProgress(IdempotencyRequest $request, IdempotencyLease $lease, \DateTimeImmutable $now): array
    {
        return [
            'fingerprint' => $request->argumentsFingerprint,
            'state' => 'in_progress',
            'lease' => $lease,
            'expiresAt' => $now->add($this->policy->retention),
            'result' => null,
            'digest' => null,
        ];
    }

    /**
     * @return array{
     *     fingerprint: ArgumentsFingerprint,
     *     state: string,
     *     lease: ?IdempotencyLease,
     *     expiresAt: \DateTimeImmutable,
     *     result: ?StoredToolResult,
     *     digest: ?StoredResultDigest
     * }
     */
    private function requireLease(IdempotencyLease $lease): array
    {
        $key = $lease->request->scope->storageKey();
        $record = $this->records[$key] ?? null;
        if (
            $record === null
            || $record['state'] !== 'in_progress'
            || $record['lease'] === null
            || $record['lease']->token !== $lease->token
        ) {
            throw IdempotencyFailure::lostLease();
        }

        return $record;
    }

    private function finish(IdempotencyLease $lease, StoredToolResult $result, StoredResultDigest $digest): void
    {
        $record = $this->requireLease($lease);
        $record['state'] = 'completed';
        $record['lease'] = null;
        $record['result'] = $result;
        $record['digest'] = $digest;
        $this->records[$lease->request->scope->storageKey()] = $record;
    }
}
