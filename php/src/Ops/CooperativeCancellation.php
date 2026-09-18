<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionStatus;
use AgenTools\Action\FoldedAction;

/** Cooperative cancellation derived from the journal, not a separate flag (LOP-20..LOP-25). */
final class CooperativeCancellation
{
    public function isInEffect(FoldedAction $state): bool
    {
        return $state->cancellationRequested;
    }

    /** LOP-23; a cancellation request does not synchronously return a terminal outcome. */
    public function requestOutcomeIsPending(): true
    {
        return true;
    }

    /** LOP-22; cancellation requests are not revocable. */
    public function isRevocable(): false
    {
        return false;
    }

    public function canRecordCancelled(FoldedAction $state): bool
    {
        if (!$state->cancellationRequested || $state->hasUnresolvedEffect()) {
            return false;
        }

        return in_array($state->status, [ActionStatus::Created, ActionStatus::Running, ActionStatus::AwaitingConfirmation], true);
    }

    /** LOP-24; cancellation does not forbid subsequent effect requests at the protocol level. */
    public function forbidsEffectRequests(FoldedAction $state): false
    {
        return false;
    }
}
