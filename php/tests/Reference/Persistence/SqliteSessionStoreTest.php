<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\ActionSnapshot;
use AgenTools\Action\Actor;
use AgenTools\Action\SessionStateProjection;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Reference\Persistence\SqliteEventLog;
use AgenTools\Reference\Persistence\SqliteSessionStore;
use PHPUnit\Framework\TestCase;

final class SqliteSessionStoreTest extends TestCase
{
    /** ALC-33; snapshot plus tail replay matches a full fold. */
    public function testSnapshotPlusTailMatchesFullFold(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        $log = new SqliteEventLog($database->pdo());
        $store = new SqliteSessionStore($database->pdo());
        $events = [
            $this->event(1, 0, 'action_created'),
            $this->event(2, 1, 'action_started'),
            $this->event(3, 2, 'action_completed'),
        ];
        foreach ($events as $event) {
            $log->append($event);
        }

        $reducer = new ActionReducer('2026-08');
        $head = $reducer->fold([$events[0], $events[1]]);
        $projection = SessionStateProjection::fromFoldedAction($head, '2026-08', '2026-08-03T09:14:22.118Z');
        $store->save($projection);

        $snapshot = new ActionSnapshot($head, '2026-08');
        $tail = $log->readFrom('action-1', $projection->seq + 1);
        $restored = ActionJournal::restore($snapshot, $tail, $reducer);
        $full = $reducer->fold($events);

        self::assertSame($full->status, $restored->status);
        self::assertSame($full->seq, $restored->seq);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $previous, string $kind, array $payload = []): ActionEvent
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
        );
    }
}
