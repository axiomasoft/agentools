<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

interface SummaryStore
{
    public function state(ExecutionContext $context,Ref $branch): ?WorkingState;
    public function saveState(ExecutionContext $context,WorkingState $state,int $expectedVersion): void;
    public function head(ExecutionContext $context,Ref $branch): ?SummaryVersion;
    /** Atomic CAS + source tombstone checks + dependency registration. */
    public function append(ExecutionContext $context,SummaryVersion $summary,int $expectedVersion): void;
}
