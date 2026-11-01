<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Context\ContextBlock;

final readonly class HistorySelection
{
    /**
     * @param list<ContextBlock> $blocks
     * @param list<array{id:string,reason:string}> $exclusions
     */
    public function __construct(public SourceStatus $status,public array $blocks,public array $exclusions,public bool $hasMore) {}
}
