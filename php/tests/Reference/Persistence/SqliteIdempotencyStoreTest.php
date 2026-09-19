<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Persistence;

use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
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

        self::assertSame(DeduplicationState::New, $store->resolve($tenantA, $now)->state);
        self::assertSame(DeduplicationState::New, $store->resolve($tenantB, $now)->state);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $store->resolve($tenantA, $now)->state);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $store->resolve($tenantB, $now)->state);
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
