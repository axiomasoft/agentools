<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\EventLog;

/** Process-local reference store. Production stores must enforce optimistic concurrency atomically. */
final class InMemoryEventLog implements EventLog
{
    /** @var array<string, list<ActionEvent>> */
    private array $eventsByAction = [];

    /** @var array<string, array<string, true>> */
    private array $eventIdsByAction = [];

    public function __construct(private readonly ?ActionReducer $reducer = null)
    {
    }

    public function append(ActionEvent $event): void
    {
        $actionId = $event->actionId;
        $known = $this->eventIdsByAction[$actionId] ?? [];
        if (isset($known[$event->eventId])) {
            return;
        }

        $lastSeq = $this->lastSeq($actionId);
        if ($event->expectedPrevSeq !== $lastSeq) {
            throw new VersionConflict($event->expectedPrevSeq, $lastSeq);
        }

        $events = [...($this->eventsByAction[$actionId] ?? []), $event];
        ($this->reducer ?? new ActionReducer())->fold($events);

        $this->eventsByAction[$actionId] = $events;
        $known[$event->eventId] = true;
        $this->eventIdsByAction[$actionId] = $known;
    }

    public function readFrom(string $actionId, int $fromSeq): array
    {
        $events = $this->eventsByAction[$actionId] ?? [];

        return array_values(array_filter(
            $events,
            static fn (ActionEvent $event): bool => $event->seq >= $fromSeq,
        ));
    }

    public function lastSeq(string $actionId): int
    {
        $events = $this->eventsByAction[$actionId] ?? [];
        if ($events === []) {
            return 0;
        }

        return $events[array_key_last($events)]->seq;
    }
}
