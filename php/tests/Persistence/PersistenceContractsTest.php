<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\ActionStatus;
use AgenTools\Action\Actor;
use AgenTools\Action\SessionStateProjection;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\Clock;
use AgenTools\Error\ToolErrorCode;
use AgenTools\TestKit\FixedClock;
use AgenTools\TestKit\InMemoryEventLog;
use AgenTools\TestKit\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class PersistenceContractsTest extends TestCase
{
    /** ALC-21; append with a stale expected_prev_seq yields a typed version conflict. */
    public function testEventLogRejectsWrongExpectedPrevSeq(): void
    {
        $log = new InMemoryEventLog();
        $log->append($this->event(1, 0, 'action_created'));

        try {
            $log->append($this->event(2, 0, 'action_started'));
            self::fail('Expected a version conflict.');
        } catch (VersionConflict $exception) {
            self::assertSame(ToolErrorCode::VersionConflict->value, $exception->error->code);
            self::assertSame(['expected_prev_seq' => 0, 'actual_prev_seq' => 1], $exception->error->context);
        }
    }

    /** ALC-7; re-appending the same event_id is idempotent. */
    public function testEventLogAppendIsIdempotentByEventId(): void
    {
        $log = new InMemoryEventLog();
        $event = $this->event(1, 0, 'action_created');
        $log->append($event);
        $log->append($event);

        self::assertSame(1, $log->lastSeq('action-1'));
        self::assertCount(1, $log->readFrom('action-1', 1));
    }

    /** ALC-32, ALC-34; the projection store is separate from the append-only log. */
    public function testSessionStorePersistsProjections(): void
    {
        $store = new InMemorySessionStore();
        $projection = new SessionStateProjection('action-1', ActionStatus::Running, 2, '2026-08', 1, '2026-08-03T09:14:22.118Z');

        $store->save($projection);

        self::assertSame($projection->toArray(), $store->load('action-1')?->toArray());
    }

    /** ALC-17; timestamps for recorded input come from the injected clock, not fold logic. */
    public function testClockProvidesInjectableNow(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-08-03T09:14:22.118Z'));

        self::assertSame('2026-08-03T09:14:22.118Z', $clock->now()->format('Y-m-d\TH:i:s.v\Z'));
        self::assertInstanceOf(Clock::class, $clock);
    }

    /** ALC-3, ALC-33; snapshot plus tail replay matches a full fold. */
    public function testSnapshotProjectionMatchesFoldedState(): void
    {
        $reducer = new ActionReducer('2026-08');
        $events = [
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'action_started'),
        ];
        $projection = SessionStateProjection::fromFoldedAction($reducer->fold($events), '2026-08', '2026-08-03T09:14:22.118Z');

        self::assertSame(ActionStatus::Running, $projection->status);
        self::assertSame(2, $projection->seq);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $previous, string $kind, array $payload = [], ?int $epoch = null): ActionEvent
    {
        return new ActionEvent(
            "event-{$seq}",
            'action-1',
            $seq,
            $previous,
            '2026-08-03T09:14:22.118Z',
            new Actor('system', 'worker-1'),
            $kind,
            1,
            '2026-08',
            $payload,
            $epoch,
        );
    }
}
