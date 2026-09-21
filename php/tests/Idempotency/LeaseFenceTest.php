<?php

declare(strict_types=1);

namespace AgenTools\Tests\Idempotency;

use AgenTools\Contracts\IdempotencyStore;
use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\TestKit\InMemoryDeduplicationStore;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LeaseFenceTest extends TestCase
{
    public function testProductionInterfaceIsFenced(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(IdempotencyStore::class))->getMethods(),
        );
        self::assertNotContains('resolve', $methods);
        $complete = (new ReflectionClass(IdempotencyStore::class))->getMethod('complete');
        $type = $complete->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(IdempotencyLease::class, $type->getName());
    }

    public function testFiftyAcquiresYieldOneWinner(): void
    {
        $store = $this->store();
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $winner = 0;
        $inFlight = 0;
        for ($i = 0; $i < 50; $i++) {
            $acquisition = $store->acquire($request, $now, LeaseDuration::default());
            if ($acquisition->state === DeduplicationState::New) {
                $winner++;
            }
            if ($acquisition->state === DeduplicationState::ConcurrentInFlight) {
                $inFlight++;
            }
        }
        self::assertSame(1, $winner);
        self::assertSame(49, $inFlight);
    }

    public function testInFlightIsImmediate(): void
    {
        $store = $this->store();
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $store->acquire($request, $now, LeaseDuration::default());
        $second = $store->acquire($request, $now, LeaseDuration::default());
        self::assertSame(DeduplicationState::ConcurrentInFlight, $second->state);
        self::assertInstanceOf(ToolError::class, $second->error);
        self::assertSame(ToolErrorCode::IdempotencyInFlight->value, $second->error->code);
        self::assertTrue($second->error->retryable);
        self::assertNull($second->lease);
    }

    public function testFingerprintReuse(): void
    {
        $store = $this->store();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $store->acquire($this->request('{"amount":1}'), $now, LeaseDuration::default());
        $this->expectException(IdempotencyFailure::class);
        $store->acquire($this->request('{"amount":2}'), $now, LeaseDuration::default());
    }

    public function testTakeoverAndStaleCompleteFailHeartbeat(): void
    {
        $store = $this->store();
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $first = $store->acquire($request, $now, new \DateInterval('PT30S'));
        $stale = $first->lease;
        self::assertInstanceOf(IdempotencyLease::class, $stale);
        self::assertSame(1, $stale->attempt);
        $later = $now->add(new \DateInterval('PT31S'));
        $takeover = $store->acquire($request, $later, new \DateInterval('PT30S'));
        self::assertTrue($takeover->takeover);
        $winner = $takeover->lease;
        self::assertInstanceOf(IdempotencyLease::class, $winner);
        self::assertSame(2, $winner->attempt);
        $result = new StoredToolResult(value: ['ok' => true]);
        $digest = StoredResultDigest::fromJcs('{"ok":true}');
        try {
            $store->complete($stale, $result, $digest, $later);
            self::fail('stale complete');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyLostLease->value, $failure->error->code);
        }
        try {
            $store->fail($stale, new StoredToolResult(error: new ToolError('agentools.precondition.idempotency_ambiguous_effect', ErrorCategory::PRECONDITION, false, 'ambiguous')), $digest, $later);
            self::fail('stale fail');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyLostLease->value, $failure->error->code);
        }
        try {
            $store->heartbeat($stale, $later, new \DateInterval('PT30S'));
            self::fail('stale heartbeat');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyLostLease->value, $failure->error->code);
        }
        $store->heartbeat($winner, $later, new \DateInterval('PT30S'));
    }

    public function testCompletedErrorReplayAndExpiry(): void
    {
        $store = $this->store(new \DateInterval('PT1S'));
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $lease = $store->acquire($request, $now, LeaseDuration::default())->lease;
        self::assertNotNull($lease);
        $error = new ToolError('billing.validation.period_closed', ErrorCategory::VALIDATION, false, 'Period is closed');
        $store->fail($lease, new StoredToolResult(error: $error), StoredResultDigest::fromJcs('{"error":"period_closed"}'), $now);
        $replay = $store->acquire($request, $now, LeaseDuration::default());
        self::assertSame(DeduplicationState::DuplicateCompleted, $replay->state);
        self::assertSame($error->code, $replay->storedResult?->error?->code);
        $fresh = $store->acquire($request, $now->add(new \DateInterval('PT2S')), LeaseDuration::default());
        self::assertSame(DeduplicationState::New, $fresh->state);
        self::assertFalse($fresh->takeover);
    }

    public function testInProgressSurvivesRecordTtlCleanup(): void
    {
        $store = $this->store(new \DateInterval('PT1S'));
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $store->acquire($request, $now, new \DateInterval('PT30S'));
        self::assertSame(0, $store->cleanup($now->add(new \DateInterval('PT2S'))));
        $still = $store->acquire($request, $now->add(new \DateInterval('PT2S')), new \DateInterval('PT30S'));
        self::assertSame(DeduplicationState::ConcurrentInFlight, $still->state);
    }

    public function testCleanupDoesNotDeleteUnexpiredCompleted(): void
    {
        $store = $this->store();
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $lease = $store->acquire($request, $now, LeaseDuration::default())->lease;
        self::assertNotNull($lease);
        $store->complete($lease, new StoredToolResult(value: ['ok' => true]), StoredResultDigest::fromJcs('{"ok":true}'), $now);
        self::assertSame(0, $store->cleanup($now));
        $replay = $store->acquire($request, $now, LeaseDuration::default());
        self::assertSame(DeduplicationState::DuplicateCompleted, $replay->state);
    }

    private function store(\DateInterval $retention = new \DateInterval('P1D')): InMemoryDeduplicationStore
    {
        return new InMemoryDeduplicationStore(new DeduplicationPolicy($retention, new \DateInterval('PT0S'), new \DateInterval('PT0S')));
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
