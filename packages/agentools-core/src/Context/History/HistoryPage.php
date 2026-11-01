<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

final readonly class HistoryPage
{
    /** @param list<ExactMessage> $messages */
    public function __construct(public SourceStatus $status,public array $messages,public bool $hasMore)
    {
        if ($status!==SourceStatus::Available && $messages!==[]) { throw new \InvalidArgumentException('Unavailable history cannot contain usable messages'); }
    }
}
