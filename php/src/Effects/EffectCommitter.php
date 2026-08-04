<?php

declare(strict_types=1);

namespace AgenTools\Effects;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\EffectStatus;

/**
 * The external call is reachable only after the action's effect registry has
 * been read and a fresh effect_requested event has been appended.
 */
final class EffectCommitter
{
    public function commit(ActionJournal $journal, ActionEvent $request, EffectApplier $applier): ActionJournal
    {
        if ($request->kind !== 'effect_requested') {
            throw new \InvalidArgumentException('EffectCommitter accepts only effect_requested events.');
        }
        $effectId = $request->payload['effect_id'] ?? null;
        if (!is_string($effectId) || $effectId === '') {
            throw new \InvalidArgumentException('An effect_requested event requires effect_id.');
        }

        $existing = $journal->state()->effects[$effectId] ?? null;
        if ($existing?->status === EffectStatus::Confirmed) {
            return $journal;
        }
        if ($existing?->status === EffectStatus::Requested) {
            throw new EffectReconciliationRequired('The requested effect has no recorded outcome.');
        }

        $journal = $journal->append($request);
        $effect = $journal->state()->effects[$effectId] ?? null;
        if ($effect === null) {
            throw new \LogicException('The effect registry did not record the request.');
        }
        $applier->apply($effect);

        return $journal;
    }
}
