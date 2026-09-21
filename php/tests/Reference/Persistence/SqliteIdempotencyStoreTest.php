<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Persistence;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Reference\Persistence\SqliteIdempotencyStore;
use PHPUnit\Framework\TestCase;

final class SqliteIdempotencyStoreTest extends TestCase
{
    /** IDE-12; tenant A cannot observe tenant B deduplication records. */
    public function testCrossTenantIsolation(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        $policy = new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
        $store = new SqliteIdempotencyStore($database->pdo(), $policy);
        $now = new \DateTimeImmutable('2026-08-03T09:14:22Z');
        $tenantA = $this->request('tenant-a', '{"amount":1}');
        $tenantB = $this->request('tenant-b', '{"amount":1}');

        self::assertSame(DeduplicationState::New, $store->acquire($tenantA, $now, new \DateInterval('PT30S'))->state);
        self::assertSame(DeduplicationState::New, $store->acquire($tenantB, $now, new \DateInterval('PT30S'))->state);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $store->acquire($tenantA, $now, new \DateInterval('PT30S'))->state);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $store->acquire($tenantB, $now, new \DateInterval('PT30S'))->state);
    }

    public function testStaleCompleteAfterTakeoverIsLostLease(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        $policy = new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
        $store = new SqliteIdempotencyStore($database->pdo(), $policy);
        $now = new \DateTimeImmutable('2026-08-03T09:14:22Z');
        $request = $this->request('tenant-a', '{"amount":1}');
        $first = $store->acquire($request, $now, new \DateInterval('PT30S'));
        self::assertNotNull($first->lease);
        $stale = $first->lease;
        $later = $now->add(new \DateInterval('PT31S'));
        $takeover = $store->acquire($request, $later, new \DateInterval('PT30S'));
        self::assertTrue($takeover->takeover);
        try {
            $store->complete(
                $stale,
                new StoredToolResult(value: ['ok' => true]),
                StoredResultDigest::fromJcs('{"ok":true}'),
                $later,
            );
            self::fail('stale complete');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyLostLease->value, $failure->error->code);
        }
        $winner = $takeover->lease;
        self::assertNotNull($winner);
        $store->complete(
            $winner,
            new StoredToolResult(value: ['ok' => true]),
            StoredResultDigest::fromJcs('{"ok":true}'),
            $later,
        );
        self::assertSame(DeduplicationState::DuplicateCompleted, $store->acquire($request, $later, new \DateInterval('PT30S'))->state);
    }

    private function request(string $tenantId, string $jcs): IdempotencyRequest
    {
        return new IdempotencyRequest(
            new IdempotencyScope($tenantId, 'payment_send', new IdempotencyKey('at1:abcdefgh')),
            ArgumentsFingerprint::fromJcs($jcs),
            'action-1',
        );
    }
}
