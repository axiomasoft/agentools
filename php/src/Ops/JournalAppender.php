<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionJournal;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\Actor;
use AgenTools\Contracts\Clock;

/** Appends journal events with optimistic sequence guards. */
final class JournalAppender
{
    private ActionJournal $journal;

    public function __construct(
        ActionJournal $journal,
        private readonly string $actionId,
        private readonly Actor $actor,
        private readonly Clock $clock,
        private readonly int $epoch = 1,
        private readonly string $specRevision = '2026-08',
        private readonly ?ActionReducer $reducer = null,
    ) {
        $this->journal = $journal;
    }

    public function journal(): ActionJournal
    {
        return $this->journal;
    }

    /** @param array<string, mixed> $payload */
    public function append(string $kind, array $payload = [], ?int $epoch = null): self
    {
        $state = $this->journal->state($this->reducer);
        $seq = $state->seq + 1;
        $event = new ActionEvent(
            eventId: sprintf('ev-%s-%04d', $this->actionId, $seq),
            actionId: $this->actionId,
            seq: $seq,
            expectedPrevSeq: $state->seq,
            timestamp: $this->clock->now()->format(DATE_ATOM),
            actor: $this->actor,
            kind: $kind,
            eventVersion: 1,
            specRevision: $this->specRevision,
            payload: $payload,
            epoch: $epoch ?? ($this->needsEpoch($kind) ? $this->epoch : null),
        );
        $this->journal = $this->journal->append($event, $this->reducer);

        return $this;
    }

    private function needsEpoch(string $kind): bool
    {
        return in_array($kind, ['effect_requested', 'effect_confirmed', 'effect_failed', 'epoch_started'], true);
    }
}
