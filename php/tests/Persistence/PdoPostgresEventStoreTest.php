<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Action\Actor;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\EventStore;
use AgenTools\Persistence\AppendResult;
use AgenTools\Persistence\PdoPostgresEventStore;
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Reference\Persistence\SqliteEventLog;
use PHPUnit\Framework\TestCase;

final class PdoPostgresEventStoreTest extends TestCase
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
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/event-store.v1.sql');
        self::assertIsString($sql);
        self::$pdo->exec('DROP TABLE IF EXISTS agentools_events');
        self::$pdo->exec('DROP TABLE IF EXISTS agentools_event_streams');
        self::$pdo->exec($sql);
    }

    protected function setUp(): void
    {
        if ($this->name() === 'testSqliteEventLogIsNotTheDurableArbiter') {
            return;
        }
        if (self::$pdo === null) {
            $this->markTestSkipped('PostgreSQL test database is not available.');
        }
        self::$pdo->exec('TRUNCATE agentools_events, agentools_event_streams RESTART IDENTITY');
    }

    public function testImplementsEventStore(): void
    {
        self::assertInstanceOf(EventStore::class, $this->store());
    }

    public function testSqliteEventLogIsNotTheDurableArbiter(): void
    {
        $path = (new \ReflectionClass(SqliteEventLog::class))->getFileName();
        self::assertNotFalse($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('Insufficient for P5.3 durable close', $source);
        self::assertStringNotContainsString('implements EventStore', $source);
    }

    public function testFiftyAppendsYieldOneWinner(): void
    {
        $store = $this->store();
        $winner = 0;
        $conflicts = 0;
        for ($i = 0; $i < 50; $i++) {
            try {
                $store->append('tenant-a', 'action-1', 0, [$this->event(sprintf('aa%030d', $i))]);
                $winner++;
            } catch (VersionConflict) {
                $conflicts++;
            }
        }
        self::assertSame(1, $winner);
        self::assertSame(49, $conflicts);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    public function testTwoConnectionsOneWinner(): void
    {
        $storeA = new PdoPostgresEventStore(self::connect());
        $storeB = new PdoPostgresEventStore(self::connect());
        $storeA->append('tenant-a', 'action-1', 0, [$this->event('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')]);
        $this->expectException(VersionConflict::class);
        $storeB->append('tenant-a', 'action-1', 0, [$this->event('cccccccccccccccccccccccccccccccc')]);
    }

    public function testAtomicTwoEventBatchRollback(): void
    {
        $store = $this->store();
        $store->append('tenant-a', 'action-1', 0, [
            $this->event('dddddddddddddddddddddddddddddddd'),
            $this->event('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'),
        ]);
        try {
            $store->append('tenant-a', 'action-1', 0, [
                $this->event('ffffffffffffffffffffffffffffffff'),
                $this->event('11111111111111111111111111111111'),
            ]);
            self::fail('expected version conflict');
        } catch (VersionConflict) {
        }
        self::assertCount(2, $store->load('tenant-a', 'action-1')->events);
    }

    public function testIdenticalReplayInsertsZeroRows(): void
    {
        $store = $this->store();
        $batch = [$this->event('22222222222222222222222222222222')];
        $store->append('tenant-a', 'action-1', 0, $batch);
        $replay = $store->append('tenant-a', 'action-1', 0, $batch);
        self::assertSame(AppendResult::IDEMPOTENT_REPLAY, $replay->outcome);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
        $query = $this->storePdo()->query('SELECT COUNT(*) FROM agentools_events');
        self::assertNotFalse($query);
        self::assertSame(1, (int) $query->fetchColumn());
    }

    public function testPayloadCollisionDoesNotOverwrite(): void
    {
        $store = $this->store();
        $id = '33333333333333333333333333333333';
        $store->append('tenant-a', 'action-1', 0, [$this->event($id, payload: ['v' => 1])]);
        try {
            $store->append('tenant-a', 'action-1', 0, [$this->event($id, payload: ['v' => 2])]);
            self::fail('expected collision');
        } catch (EventIdCollision) {
        }
        $page = $store->load('tenant-a', 'action-1');
        self::assertSame(['v' => 1], $page->events[0]->payload);
    }

    public function testPagesAbutWithoutGaps(): void
    {
        $store = $this->store();
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->event(sprintf('%032d', $i + 40));
        }
        $store->append('tenant-a', 'action-1', 0, $batch);
        $first = $store->load('tenant-a', 'action-1', 0, 2);
        $second = $store->load('tenant-a', 'action-1', $first->lastSeq, 2);
        self::assertSame([1, 2], array_map(static fn ($event) => $event->seq, $first->events));
        self::assertSame([3, 4], array_map(static fn ($event) => $event->seq, $second->events));
        self::assertSame($first->lastSeq + 1, $second->events[0]->seq);
        self::assertGreaterThan(0, $first->events[0]->seq);
    }

    public function testIdentityPositionAssigned(): void
    {
        $store = $this->store();
        $result = $store->append('tenant-a', 'action-1', 0, [$this->event('44444444444444444444444444444444')]);
        self::assertGreaterThan(0, $result->lastGlobalPosition);
        $query = $this->storePdo()->query('SELECT global_position FROM agentools_events LIMIT 1');
        self::assertNotFalse($query);
        self::assertSame($result->lastGlobalPosition, (int) $query->fetchColumn());
    }

    private function store(): PdoPostgresEventStore
    {
        return new PdoPostgresEventStore(self::connect());
    }

    private function storePdo(): \PDO
    {
        return self::connect();
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';

        return new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** @param array<string, mixed> $payload */
    private function event(string $eventId, array $payload = []): UncommittedEvent
    {
        return new UncommittedEvent(
            eventId: $eventId,
            kind: 'action_created',
            eventVersion: 1,
            specRevision: '2026-08',
            actor: new Actor('user', 'u-1'),
            timestamp: '2026-09-20T10:00:00+00:00',
            payload: $payload,
        );
    }
}
