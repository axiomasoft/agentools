<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Persistence\AppendResult;
use AgenTools\Persistence\EventStreamPage;
use AgenTools\Persistence\UncommittedEvent;

/** Storage-assigned atomic batch journal. Callers never supply seq or global_position. */
interface EventStore
{
    /**
     * @param list<UncommittedEvent> $events
     */
    public function append(string $tenantId, string $actionId, int $expectedPrevSeq, array $events): AppendResult;

    public function load(string $tenantId, string $actionId, int $afterSeq = 0, int $limit = 500): EventStreamPage;
}
