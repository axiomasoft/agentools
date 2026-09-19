<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\Actor;
use AgenTools\Action\JournalHashChain;
use AgenTools\Action\VersionConflict;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Reference\Persistence\SqliteEventLog;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class SqliteEventLogTest extends TestCase
{
    /** ALC-22; concurrent writers with the same expected_prev_seq produce a typed conflict. */
    public function testParallelAppendRejectsStaleExpectedPrevSeq(): void
    {
        $path = $this->tempDatabasePath();
        $first = ReferenceSliceDatabase::open($path);
        $second = ReferenceSliceDatabase::open($path);
        $logA = new SqliteEventLog($first->pdo());
        $logB = new SqliteEventLog($second->pdo());
        $logA->append($this->event(1, 0, 'action_created'));

        try {
            $logB->append($this->event(2, 0, 'action_started'));
            self::fail('Expected a version conflict from the second writer.');
        } catch (VersionConflict $exception) {
            self::assertSame(['expected_prev_seq' => 0, 'actual_prev_seq' => 1], $exception->error->context);
        }
    }

    /** ALC-36; prev_hash chain is preserved across SQLite reads. */
    public function testHashChainRoundTripsThroughStorage(): void
    {
        $database = ReferenceSliceDatabase::open(':memory:');
        $log = new SqliteEventLog($database->pdo());
        $hashChain = new JournalHashChain(new Canonicalizer());
        $first = $this->event(1, 0, 'action_created');
        $log->append($first);
        $secondHash = $hashChain->hash($first);
        $second = $this->event(2, 1, 'action_started')->withPreviousHash($secondHash);
        $log->append($second);

        $events = $log->readFrom('action-1', 1);
        self::assertCount(2, $events);
        self::assertNull($events[0]->previousHash);
        self::assertSame($secondHash, $events[1]->previousHash);
        (new ActionReducer())->fold($events);
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

    private function tempDatabasePath(): string
    {
        $path = sys_get_temp_dir() . '/agentools-reference-' . bin2hex(random_bytes(8)) . '.sqlite';
        if (file_exists($path)) {
            unlink($path);
        }
        register_shutdown_function(static function () use ($path): void {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        return $path;
    }
}
