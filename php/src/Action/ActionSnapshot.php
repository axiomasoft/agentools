<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** An optional projection; the journal remains the source of truth. */
final readonly class ActionSnapshot
{
    public function __construct(
        public FoldedAction $state,
        public string $specRevision,
        public string $contentHash = '',
    ) {
        if ($state->actionId === null || $state->status === null || $state->seq < 1) {
            throw new \InvalidArgumentException('A snapshot requires a folded action state.');
        }
        if ($specRevision === '') {
            throw new \InvalidArgumentException('A snapshot requires a spec revision.');
        }
    }

    public function matches(string $expectedHash): bool
    {
        return $this->contentHash !== '' && hash_equals($this->contentHash, $expectedHash);
    }

    /**
     * Mutated hash discards the snapshot. Events are never deleted.
     *
     * @param list<ActionEvent> $tail
     */
    public static function restoreOrDiscard(
        self $snapshot,
        string $expectedHash,
        ActionJournal $journal,
        array $tail,
        ?ActionReducer $reducer = null,
    ): FoldedAction {
        $events = $journal->events();
        $checkpoint = $events[$snapshot->state->seq - 1] ?? null;
        $expectedTail = array_slice($events, $snapshot->state->seq);
        $validCheckpoint = $checkpoint !== null
            && $checkpoint->seq === $snapshot->state->seq
            && $checkpoint->actionId === $snapshot->state->actionId
            && $checkpoint->specRevision === $snapshot->specRevision
            && array_map(static fn (ActionEvent $event): array => $event->toArray(), $expectedTail)
                === array_map(static fn (ActionEvent $event): array => $event->toArray(), $tail);
        if (!$snapshot->matches($expectedHash) || !$validCheckpoint) {
            return $journal->state($reducer);
        }

        return ActionJournal::restore($snapshot, $tail, $reducer);
    }
}
