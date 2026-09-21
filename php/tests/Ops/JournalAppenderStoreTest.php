<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Action\Actor;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\AuditSink;
use AgenTools\Ops\JournalAppender;
use AgenTools\TestKit\FixedClock;
use AgenTools\TestKit\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

final class JournalAppenderStoreTest extends TestCase
{
    public function testOmittingStoreIsImpossibleAndIdsAreNotSeqDerived(): void
    {
        $appender = $this->appender();
        $appender->append('action_created');
        $event = $appender->journal()->events()[0];
        self::assertSame(1, $event->seq);
        self::assertDoesNotMatchRegularExpression('/^ev-.+-\d+$/', $event->eventId);
        self::assertSame(32, strlen($event->eventId));
    }

    public function testFiftyAppendersYieldOneWinner(): void
    {
        $store = new InMemoryEventStore();
        $winner = 0;
        $conflicts = 0;
        for ($i = 0; $i < 50; $i++) {
            $appender = new JournalAppender(
                $store,
                'tenant-a',
                'action-1',
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
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    public function testCollisionDoesNotOverwrite(): void
    {
        $store = new InMemoryEventStore();
        $first = $this->appender($store);
        $first->append('action_created', ['v' => 1]);
        $id = $first->journal()->events()[0]->eventId;
        $this->expectException(EventIdCollision::class);
        $store->append('tenant-a', 'action-1', 0, [
            new \AgenTools\Persistence\UncommittedEvent(
                $id,
                'action_created',
                1,
                '2026-08',
                new Actor('user', 'u-1'),
                '2026-09-20T10:00:00+00:00',
                ['v' => 2],
            ),
        ]);
    }

    public function testAuditSinkFailureDoesNotRollback(): void
    {
        $sink = new class implements AuditSink {
            public function export(array $auditEvent): void
            {
                throw new \RuntimeException('sink failed');
            }
        };
        $store = new InMemoryEventStore();
        $appender = new JournalAppender(
            $store,
            'tenant-a',
            'action-1',
            new Actor('user', 'u-1'),
            new FixedClock(new \DateTimeImmutable('2026-09-20T10:00:00+00:00')),
            auditSink: $sink,
        );
        $appender->append('audit.policy_evaluated', [
            'tool_name' => 'pay',
            'side_effect' => 'write',
            'verdict' => 'allow',
        ]);
        self::assertCount(1, $store->load('tenant-a', 'action-1')->events);
    }

    public function testMigratedConsumersDoNotCallEventLogAppend(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Ops/JournalAppender.php');
        self::assertStringNotContainsString('EventLog', $source);
        self::assertStringNotContainsString('ev-%s', $source);
        self::assertStringContainsString('EventStore', $source);
        self::assertStringContainsString('newEventId', $source);
    }

    public function testItNormalizesTimestampToUtc(): void
    {
        $appender = new JournalAppender(
            new InMemoryEventStore(),
            'tenant-a',
            'action-1',
            new Actor('user', 'u-1'),
            new FixedClock(new \DateTimeImmutable('2026-09-20T13:00:00+03:00')),
        );

        $appender->append('action_created');

        self::assertSame('2026-09-20T10:00:00+00:00', $appender->journal()->events()[0]->timestamp);
    }

    private function appender(?InMemoryEventStore $store = null): JournalAppender
    {
        return new JournalAppender(
            $store ?? new InMemoryEventStore(),
            'tenant-a',
            'action-1',
            new Actor('user', 'u-1'),
            new FixedClock(new \DateTimeImmutable('2026-09-20T10:00:00+00:00')),
        );
    }
}
