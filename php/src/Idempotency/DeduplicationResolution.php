<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ToolError;

final readonly class DeduplicationResolution
{
    public function __construct(
        public DeduplicationState $state,
        public ?StoredToolResult $storedResult = null,
        public ?StoredResultDigest $storedResultDigest = null,
        public ?ToolError $error = null,
    ) {
        if ($state === DeduplicationState::DuplicateCompleted && ($storedResult === null || $storedResultDigest === null)) {
            throw new \InvalidArgumentException('A completed duplicate must carry its stored result and digest.');
        }
        if ($state !== DeduplicationState::DuplicateCompleted && ($storedResult !== null || $storedResultDigest !== null)) {
            throw new \InvalidArgumentException('Only a completed duplicate carries a result.');
        }
        if (($state === DeduplicationState::ConcurrentInFlight) !== ($error !== null)) {
            throw new \InvalidArgumentException('Only an in-flight resolution carries its immediate tool error.');
        }
    }
}
