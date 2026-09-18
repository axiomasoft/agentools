<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** An optional projection; the journal remains the source of truth. */
final readonly class ActionSnapshot
{
    public function __construct(public FoldedAction $state, public string $specRevision)
    {
        if ($state->actionId === null || $state->status === null || $state->seq < 1) {
            throw new \InvalidArgumentException('A snapshot requires a folded action state.');
        }
    }
}
