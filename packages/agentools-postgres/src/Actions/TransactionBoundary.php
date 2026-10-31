<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenTools\Persistence\UncommittedEvent;
use AgenToolsPlatform\Core\Common\ActionId;
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Claims\RunClaim;

/** Storage composition only: a returned projection means the common transaction committed. */
interface TransactionBoundary
{
    /**
     * @param list<UncommittedEvent> $events
     * @param list<EffectIntent> $intents
     */
    public function append(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim,
        ActionId $action, int $expectedPrevSeq, array $events, array $intents = []): Projection;
}
