<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Context\History\SourceStatus;

final readonly class CandidatePage
{
    /** @param list<Candidate> $candidates */
    public function __construct(public SourceStatus $status,public array $candidates,public bool $hasMore)
    { if ($status!==SourceStatus::Available && $candidates!==[]) { throw new \InvalidArgumentException('Unavailable candidates are not successful empty query'); } }
}
