<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

final readonly class AppendResult
{
    public const APPENDED = 'appended';
    public const IDEMPOTENT_REPLAY = 'idempotent_replay';

    public function __construct(
        public int $firstSeq,
        public int $lastSeq,
        public int $lastGlobalPosition,
        public string $outcome,
    ) {
        if (!in_array($outcome, [self::APPENDED, self::IDEMPOTENT_REPLAY], true)) {
            throw new \InvalidArgumentException('Unknown append outcome.');
        }
        if ($firstSeq < 1 || $lastSeq < $firstSeq || $lastGlobalPosition < 1) {
            throw new \InvalidArgumentException('Append result sequences must be positive and ordered.');
        }
    }

    public function appended(): bool
    {
        return $this->outcome === self::APPENDED;
    }
}
