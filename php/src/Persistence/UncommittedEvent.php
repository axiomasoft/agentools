<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Action\Actor;

/** Write DTO. Storage assigns seq, expected_prev_seq, prev_hash and global_position. */
final readonly class UncommittedEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $kind,
        public int $eventVersion,
        public string $specRevision,
        public Actor $actor,
        public string $timestamp,
        public array $payload,
        public ?int $epoch = null,
    ) {
        if ($eventId === '' || $kind === '' || $specRevision === '' || $timestamp === '') {
            throw new \InvalidArgumentException('The uncommitted event has required empty fields.');
        }
        if ($eventVersion < 1 || ($epoch !== null && $epoch < 1)) {
            throw new \InvalidArgumentException('The uncommitted event contains an invalid numeric value.');
        }
    }
}
