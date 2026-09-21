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
        if (!$snapshot->matches($expectedHash)) {
            return $journal->state($reducer);
        }

        return ActionJournal::restore($snapshot, $tail, $reducer);
    }
}
