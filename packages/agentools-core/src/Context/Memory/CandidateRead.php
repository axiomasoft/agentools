<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Context\History\SourceStatus;

final readonly class CandidateRead
{
    public function __construct(public SourceStatus $status,public ?Candidate $candidate=null)
    { if (($status===SourceStatus::Available)!==($candidate!==null)) { throw new \InvalidArgumentException('Explicit candidate availability required'); } }
}
