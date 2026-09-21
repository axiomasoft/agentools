<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

use AgenTools\Error\ToolError;

/** Observer-facing acquire result. `new` carries a lease; in-flight does not. */
final readonly class IdempotencyAcquisition
{
    public function __construct(
        public DeduplicationState $state,
        public ?IdempotencyLease $lease = null,
        public bool $takeover = false,
        public ?StoredToolResult $storedResult = null,
        public ?StoredResultDigest $storedResultDigest = null,
        public ?ToolError $error = null,
    ) {
        if (($state === DeduplicationState::New) !== ($lease !== null)) {
            throw new \InvalidArgumentException('A new acquisition must carry a lease.');
        }
        if ($takeover && $state !== DeduplicationState::New) {
            throw new \InvalidArgumentException('Only a new acquisition can be a takeover.');
        }
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
