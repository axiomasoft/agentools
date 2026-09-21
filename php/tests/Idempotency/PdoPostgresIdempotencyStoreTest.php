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
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\Idempotency\LeaseDuration;
use AgenTools\Idempotency\PdoPostgresIdempotencyStore;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use PHPUnit\Framework\TestCase;

final class PdoPostgresIdempotencyStoreTest extends TestCase
{
    private static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        try {
            self::$pdo = new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            self::$pdo = null;
        }
        if (self::$pdo === null) {
            return;
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/idempotency-lease.v1.sql');
        self::assertIsString($sql);
        self::$pdo->exec('DROP TABLE IF EXISTS agentools_idempotency_leases');
        self::$pdo->exec($sql);
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('PostgreSQL test database is not available.');
        }
        self::$pdo->exec('TRUNCATE agentools_idempotency_leases');
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

    public function testTwoConnectionsYieldOneLease(): void
    {
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $storeA = new PdoPostgresIdempotencyStore(self::connect(), $this->policy());
        $storeB = new PdoPostgresIdempotencyStore(self::connect(), $this->policy());
        $first = $storeA->acquire($request, $now, LeaseDuration::default());
        $second = $storeB->acquire($request, $now, LeaseDuration::default());
        self::assertSame(DeduplicationState::New, $first->state);
        self::assertSame(DeduplicationState::ConcurrentInFlight, $second->state);
        self::assertNull($second->lease);
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
        $store->complete($winner, $result, $digest, $later);
        $replay = $store->acquire($request, $later, new \DateInterval('PT30S'));
        self::assertSame(DeduplicationState::DuplicateCompleted, $replay->state);
        self::assertSame(['ok' => true], $replay->storedResult?->value);
    }

    public function testFingerprintMismatchDoesNotOverwrite(): void
    {
        $store = $this->store();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $store->acquire($this->request('{"amount":1}'), $now, LeaseDuration::default());
        try {
            $store->acquire($this->request('{"amount":2}'), $now, LeaseDuration::default());
            self::fail('reuse expected');
        } catch (IdempotencyFailure $failure) {
            self::assertSame(ToolErrorCode::IdempotencyKeyReuse->value, $failure->error->code);
        }
        $still = $store->acquire($this->request('{"amount":1}'), $now, LeaseDuration::default());
        self::assertSame(DeduplicationState::ConcurrentInFlight, $still->state);
    }

    public function testCleanupDoesNotDeleteInProgressOrUnexpiredCompleted(): void
    {
        $store = $this->store();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $inProgress = $this->request('{"amount":1}', 'at1:inflight1');
        $store->acquire($inProgress, $now, new \DateInterval('PT30S'));
        $completed = $this->request('{"amount":2}', 'at1:complete');
        $lease = $store->acquire($completed, $now, LeaseDuration::default())->lease;
        self::assertNotNull($lease);
        $store->complete($lease, new StoredToolResult(value: ['ok' => true]), StoredResultDigest::fromJcs('{"ok":true}'), $now);
        self::assertSame(0, $store->cleanup($now->add(new \DateInterval('PT2S'))));
        self::assertSame(DeduplicationState::ConcurrentInFlight, $store->acquire($inProgress, $now->add(new \DateInterval('PT2S')), new \DateInterval('PT30S'))->state);
        self::assertSame(DeduplicationState::DuplicateCompleted, $store->acquire($completed, $now, LeaseDuration::default())->state);
    }

    public function testExpiredCompletedBecomesNew(): void
    {
        $store = new PdoPostgresIdempotencyStore(self::connect(), new DeduplicationPolicy(new \DateInterval('PT1S'), new \DateInterval('PT0S'), new \DateInterval('PT0S')));
        $request = $this->request('{"amount":1}');
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $lease = $store->acquire($request, $now, LeaseDuration::default())->lease;
        self::assertNotNull($lease);
        $store->complete($lease, new StoredToolResult(value: ['ok' => true]), StoredResultDigest::fromJcs('{"ok":true}'), $now);
        $fresh = $store->acquire($request, $now->add(new \DateInterval('PT2S')), LeaseDuration::default());
        self::assertSame(DeduplicationState::New, $fresh->state);
        self::assertFalse($fresh->takeover);
        self::assertInstanceOf(IdempotencyLease::class, $fresh->lease);
    }

    private function store(): PdoPostgresIdempotencyStore
    {
        return new PdoPostgresIdempotencyStore(self::connect(), $this->policy());
    }

    private function policy(): DeduplicationPolicy
    {
        return new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'));
    }

    private function request(string $jcs, string $key = 'at1:abcdefgh'): IdempotencyRequest
    {
        return new IdempotencyRequest(
            new IdempotencyScope('tenant-1', 'payment_send', new IdempotencyKey($key)),
            ArgumentsFingerprint::fromJcs($jcs),
            'action-1',
        );
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        return new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
