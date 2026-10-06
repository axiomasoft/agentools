<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Action\ActionSnapshot;
use AgenTools\Action\Actor;
use AgenTools\Action\VersionConflict;
use AgenTools\Ops\JournalAppender;
use AgenTools\TestKit\FixedClock;
use AgenTools\TestKit\InMemoryEventStore;
use AgenTools\Persistence\PdoPostgresEventStore;
use AgenTools\Tests\Support\PostgresRequirement;
use PHPUnit\Framework\TestCase;

final class EventStoreGateTest extends TestCase
{
    public function testJournalAppenderFiftyAppendsOneWinner(): void
    {
        $store = $this->durableStore() ?? new InMemoryEventStore();
        $actionId = 'action-gate-' . bin2hex(random_bytes(8));
        $winner = 0;
        $conflicts = 0;
        for ($i = 0; $i < 50; $i++) {
            $appender = new JournalAppender(
                $store,
                'tenant-gate',
                $actionId,
                new Actor('user', 'u-1'),
                new FixedClock(new \DateTimeImmutable('2026-09-20T10:00:00+00:00')),
            );
            try {
                $appender->append('action_created');
                $winner++;
            } catch (VersionConflict) {
                $conflicts++;
            }
        }
        self::assertSame(1, $winner);
        self::assertSame(49, $conflicts);
    }

    public function testSnapshotHashMismatchDiscardsSnapshotNotEvents(): void
    {
        $store = new InMemoryEventStore();
        $appender = new JournalAppender(
            $store,
            'tenant-a',
            'action-1',
            new Actor('user', 'u-1'),
            new FixedClock(new \DateTimeImmutable('2026-09-20T10:00:00+00:00')),
        );
        $appender->append('action_created');
        $appender->append('action_started');
        $journal = $appender->journal();
        $hash = $store->contentHash(
            'tenant-a',
            'action-1',
            new \AgenTools\Persistence\UncommittedEvent(
                $journal->events()[0]->eventId,
                $journal->events()[0]->kind,
                1,
                '2026-08',
                new Actor('user', 'u-1'),
                '2026-09-20T10:00:00+00:00',
                [],
            ),
        );
        $snapshot = new ActionSnapshot($journal->state(), '2026-08', $hash);
        $mutated = substr($hash, 0, -1) . ($hash[-1] === 'a' ? 'b' : 'a');
        $restored = ActionSnapshot::restoreOrDiscard($snapshot, $mutated, $journal, []);
        self::assertSame($journal->state()->seq, $restored->seq);
        self::assertCount(2, $store->load('tenant-a', 'action-1')->events);
        self::assertFalse($snapshot->matches($mutated));
        self::assertTrue($snapshot->matches($hash));
    }

    public function testPostgresAppenderIsDurableWhenAvailable(): void
    {
        $store = $this->durableStore();
        if ($store === null) {
            PostgresRequirement::unavailable();
        }
        $actionId = 'action-pg-' . bin2hex(random_bytes(8));
        $appender = new JournalAppender(
            $store,
            'tenant-gate',
            $actionId,
            new Actor('user', 'u-1'),
            new FixedClock(new \DateTimeImmutable('2026-09-20T10:00:00+00:00')),
        );
        $appender->append('action_created');
        self::assertSame(1, $store->load('tenant-gate', $actionId)->events[0]->seq);
        self::assertGreaterThan(0, $store->append(
            'tenant-gate',
            $actionId,
            1,
            [new \AgenTools\Persistence\UncommittedEvent(
                bin2hex(random_bytes(16)),
                'action_started',
                1,
                '2026-08',
                new Actor('user', 'u-1'),
                '2026-09-20T10:00:01+00:00',
                [],
            )],
        )->lastGlobalPosition);
    }

    private function durableStore(): ?PdoPostgresEventStore
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        try {
            $pdo = new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            return null;
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/event-store.v1.sql');
        if (!is_string($sql)) {
            return null;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS agentools_event_streams (tenant_id TEXT NOT NULL, action_id TEXT NOT NULL, current_seq BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (tenant_id, action_id))');
        $pdo->exec($sql);

        return new PdoPostgresEventStore($pdo);
    }
}
