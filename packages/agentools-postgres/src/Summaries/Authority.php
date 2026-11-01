<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Summaries;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\History\{SummaryVersion,WorkingState};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Required owner guard, also for direct repository calls and idempotent replays. */
interface Authority
{
    public function authorize(ExecutionContext $context,Ref $scope,string $operation): void;
    public function acceptsState(ExecutionContext $context,?WorkingState $before,WorkingState $next): void;
    public function acceptsSummary(ExecutionContext $context,SummaryVersion $summary): void;
}
