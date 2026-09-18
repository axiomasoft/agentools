<?php

declare(strict_types=1);

namespace AgenTools\Tests\Idempotency;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\Idempotency\InMemoryDeduplicationStore;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class InMemoryDeduplicationStoreTest extends TestCase
{
    /** IDE-10; the deduplication state enum is closed to exactly three values. */
    public function testDeduplicationStateHasExactlyThreeValues(): void
    {
        self::assertCount(3, DeduplicationState::cases());
    }

    /** IDE-10, IDE-12, IDE-13, IDE-14, IDE-15, IDE-23; duplicate_completed returns stored errors as-is. */
    public function testItCreatesThenReturnsTheOriginalCompletedError(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy('P1D'));
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-08-03T09:14:22Z');

        self::assertSame(DeduplicationState::New, $store->resolve($request, $now)->state);
        $inFlight = $store->resolve($request, $now);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $inFlight->state);
        self::assertNotNull($inFlight->error);
        self::assertSame(ToolErrorCode::IdempotencyInFlight->value, $inFlight->error->code);
        self::assertTrue($inFlight->error->retryable);

        $error = new ToolError('billing.validation.period_closed', ErrorCategory::VALIDATION, false, 'Period is closed');
        $store->complete($request, new StoredToolResult(error: $error), StoredResultDigest::fromJcs('{"error":"period_closed"}'));

        $duplicate = $store->resolve($request, $now);
        self::assertSame(DeduplicationState::DuplicateCompleted, $duplicate->state);
        self::assertSame($error, $duplicate->storedResult?->error);
    }

    /** IDE-16; the same key with a different args_fingerprint is a caller error. */
    public function testItRejectsAKeyReusedForDifferentArguments(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy('P1D'));
        $now = new \DateTimeImmutable('2026-08-03T09:14:22Z');
        $store->resolve($this->request('{"amount":1}'), $now);

        try {
            $store->resolve($this->request('{"amount":2}'), $now);
            self::fail('Expected an idempotency key reuse error.');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyKeyReuse->value, $failure->error->code);
        }
    }

    /** IDE-9; idempotent_commit without a client key is rejected. */
    public function testARequiredIdempotencyKeyProducesTheSpecifiedToolError(): void
    {
        try {
            IdempotencyKey::required(null);
            self::fail('Expected an idempotency key error.');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyKeyMissing->value, $failure->error->code);
            self::assertFalse($failure->error->retryable);
        }
    }

    /** IDE-21; an expired retention window allows a fresh new resolution. */
    public function testExpiredRecordsBecomeNewIntent(): void
    {
        $store = new InMemoryDeduplicationStore($this->policy('PT1S'));
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-08-03T09:14:22Z');
        $store->resolve($request, $now);

        self::assertSame(DeduplicationState::New, $store->resolve($request, $now->modify('+2 seconds'))->state);
    }

    /** IDE-18, IDE-19; retention must cover confirmation lifetime plus the client retry window. */
    public function testRetentionMustCoverTheOtherIndependentTimers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeduplicationPolicy(new \DateInterval('PT1S'), new \DateInterval('PT1S'), new \DateInterval('PT1S'));
    }

    /** IDE-3, IDE-7; argument order cannot create a different fingerprint outside JCS. */
    public function testItComputesArgumentFingerprintsThroughTheJcsFacade(): void
    {
        $canonicalizer = new Canonicalizer();

        self::assertSame(
            ArgumentsFingerprint::fromArguments(['a' => 1, 'b' => 2], $canonicalizer)->value,
            ArgumentsFingerprint::fromArguments(['b' => 2, 'a' => 1], $canonicalizer)->value,
        );
    }

    private function policy(string $retention): DeduplicationPolicy
    {
        return new DeduplicationPolicy(new \DateInterval($retention), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
    }

    private function request(string $jcs): IdempotencyRequest
    {
        return new IdempotencyRequest(
            new IdempotencyScope('tenant-1', 'payment_send', new IdempotencyKey('at1:abcdefgh')),
            ArgumentsFingerprint::fromJcs($jcs),
            'action-1',
        );
    }
}
