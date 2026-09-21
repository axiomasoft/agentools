<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Action\ActionEvent;

final readonly class EventStreamPage
{
    /** @param list<ActionEvent> $events */
    public function __construct(
        public array $events,
        public int $lastSeq,
        public bool $hasMore,
    ) {
        if ($lastSeq < 0) {
            throw new \InvalidArgumentException('A page lastSeq cannot be negative.');
        }
    }
}
