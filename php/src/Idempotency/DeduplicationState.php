<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

enum DeduplicationState: string
{
    case New = 'new';
    case DuplicateCompleted = 'duplicate_completed';
    case ConcurrentInFlight = 'concurrent_in_flight';
}
