<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Receives exact messages only: no previous-summary text or authority assignment parameter. */
interface SummaryExtractor
{
    /** @param list<ExactMessage> $messages */
    public function extract(ExecutionContext $context,Ref $branch,array $messages,WorkingState $state): SummaryDraft;
}
