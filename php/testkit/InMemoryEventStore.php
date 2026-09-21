<?php

declare(strict_types=1);

namespace AgenTools\TestKit;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\JournalHashChain;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\EventStore;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Persistence\AppendResult;
use AgenTools\Persistence\EventStoreSupport;
use AgenTools\Persistence\EventStreamPage;
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Schema\Canonicalizer;

/**
 * Process-local EventStore. Production stores must classify under a stream lock.
 * This adapter is testkit/reference-only and MUST NOT be a production default.
 */
final class InMemoryEventStore implements EventStore
{
    public const DEFAULT_PAGE = EventStoreSupport::DEFAULT_PAGE;
    public const MAX_PAGE = EventStoreSupport::MAX_PAGE;
    public const MAX_BATCH = EventStoreSupport::MAX_BATCH;

    /** @var array<string, array<string, list<ActionEvent>>> */
    private array $events = [];

    /** @var array<string, array<string, array{actionId: string, seq: int, hash: string, position: int}>> */
    private array $byEventId = [];

    private int $globalPosition = 0;

    public function __construct(
        private readonly Canonicalizer $canonicalizer = new Canonicalizer(),
        private readonly ?ActionReducer $reducer = null,
    ) {
    }

    /** @param list<UncommittedEvent> $events */
    public function assertAppendable(string $tenantId, string $actionId, array $events): void
    {
        (new EventStoreSupport($this->canonicalizer))->assertAppendable($tenantId, $actionId, $events);
    }

    /** @param list<UncommittedEvent> $events */
    public function append(string $tenantId, string $actionId, int $expectedPrevSeq, array $events): AppendResult
    {
        $this->assertAppendable($tenantId, $actionId, $events);

        $actual = $this->currentSeq($tenantId, $actionId);
        $hashes = [];
        foreach ($events as $event) {
            $hashes[] = $this->contentHash($tenantId, $actionId, $event);
        }

        $existing = [];
        foreach ($events as $index => $event) {
            $row = $this->byEventId[$tenantId][$event->eventId] ?? null;
            if ($row !== null) {
                $existing[$index] = $row;
            }
        }

        if ($existing === []) {
            if ($expectedPrevSeq !== $actual) {
                throw new VersionConflict($expectedPrevSeq, $actual);
            }

            return $this->insertAll($tenantId, $actionId, $actual, $events, $hashes);
        }

        if (count($existing) !== count($events)) {
            foreach ($existing as $index => $row) {
                if ($row['hash'] !== $hashes[$index]) {
                    throw new EventIdCollision($events[$index]->eventId);
                }
            }
            throw EventStoreFailure::partialOverlap();
        }

        foreach ($events as $index => $event) {
            $row = $existing[$index];
            if ($row['hash'] !== $hashes[$index]) {
                throw new EventIdCollision($event->eventId);
            }
            if ($row['actionId'] !== $actionId) {
                throw new EventIdCollision($event->eventId);
            }
        }

        $firstSeq = $existing[0]['seq'];
        foreach ($events as $index => $event) {
            if ($existing[$index]['seq'] !== $firstSeq + $index) {
                throw EventStoreFailure::partialOverlap();
            }
        }
        if ($firstSeq !== $expectedPrevSeq + 1) {
            throw new VersionConflict($expectedPrevSeq, $actual);
        }

        $lastEvent = $events[array_key_last($events)];

        return new AppendResult(
            $firstSeq,
            $firstSeq + count($events) - 1,
            $this->byEventId[$tenantId][$lastEvent->eventId]['position'],
            AppendResult::IDEMPOTENT_REPLAY,
        );
    }

    public function load(string $tenantId, string $actionId, int $afterSeq = 0, int $limit = self::DEFAULT_PAGE): EventStreamPage
    {
        if ($tenantId === '' || $actionId === '') {
            throw EventStoreFailure::streamIdentity();
        }
        if ($limit < 1 || $limit > self::MAX_PAGE) {
            throw EventStoreFailure::pageLimit($limit);
        }
        $all = $this->events[$tenantId][$actionId] ?? [];
        $matching = array_values(array_filter(
            $all,
            static fn (ActionEvent $event): bool => $event->seq > $afterSeq,
        ));
        $page = array_slice($matching, 0, $limit);
        $lastSeq = $page === [] ? $afterSeq : $page[array_key_last($page)]->seq;

        return new EventStreamPage($page, $lastSeq, count($matching) > $limit);
    }

    public function contentHash(string $tenantId, string $actionId, UncommittedEvent $event): string
    {
        return (new EventStoreSupport($this->canonicalizer))->contentHash($tenantId, $actionId, $event);
    }

    /**
     * @param list<UncommittedEvent> $events
     * @param list<string> $hashes
     */
    private function insertAll(
        string $tenantId,
        string $actionId,
        int $actual,
        array $events,
        array $hashes,
    ): AppendResult {
        $committed = $this->events[$tenantId][$actionId] ?? [];
        $chain = new JournalHashChain($this->canonicalizer);
        $firstSeq = $actual + 1;
        $lastPosition = 0;
        foreach ($events as $index => $event) {
            $seq = $actual + $index + 1;
            $previous = $committed === [] ? null : $committed[array_key_last($committed)];
            $row = new ActionEvent(
                eventId: $event->eventId,
                actionId: $actionId,
                seq: $seq,
                expectedPrevSeq: $actual + $index,
                timestamp: $event->timestamp,
                actor: $event->actor,
                kind: $event->kind,
                eventVersion: $event->eventVersion,
                specRevision: $event->specRevision,
                payload: $event->payload,
                epoch: $event->epoch,
                previousHash: $previous === null ? null : $chain->hash($previous),
            );
            $committed[] = $row;
            $this->globalPosition++;
            $lastPosition = $this->globalPosition;
            $this->byEventId[$tenantId][$event->eventId] = [
                'actionId' => $actionId,
                'seq' => $seq,
                'hash' => $hashes[$index],
                'position' => $lastPosition,
            ];
        }
        $this->reducer?->fold($committed);
        $this->events[$tenantId][$actionId] = $committed;

        return new AppendResult($firstSeq, $actual + count($events), $lastPosition, AppendResult::APPENDED);
    }

    private function currentSeq(string $tenantId, string $actionId): int
    {
        $events = $this->events[$tenantId][$actionId] ?? [];
        if ($events === []) {
            return 0;
        }

        return $events[array_key_last($events)]->seq;
    }
}
