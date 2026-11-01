<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

final readonly class RebuildResult
{
    public function __construct(public SourceStatus $status,public ?SummaryVersion $summary,public bool $revalidated)
    {
        if (($status===SourceStatus::Available)!==($summary!==null) || $revalidated!==($status===SourceStatus::Available)) { throw new \InvalidArgumentException('Unavailable rebuild is not revalidated'); }
    }
}
