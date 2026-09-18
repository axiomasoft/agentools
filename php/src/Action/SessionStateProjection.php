<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** A cached fold result separate from the append-only journal. */
final readonly class SessionStateProjection
{
    public function __construct(
        public string $actionId,
        public ActionStatus $status,
        public int $seq,
        public string $specRevision,
        public int $epoch = 0,
        public ?string $projectedAt = null,
    ) {
        if ($actionId === '' || $specRevision === '' || $seq < 1) {
            throw new \InvalidArgumentException('A session projection requires action id, seq, and spec revision.');
        }
        if ($epoch < 0) {
            throw new \InvalidArgumentException('A session projection cannot carry a negative epoch.');
        }
    }

    public static function fromFoldedAction(FoldedAction $state, string $specRevision, ?string $projectedAt = null): self
    {
        if ($state->actionId === null || $state->status === null || $state->seq < 1) {
            throw new \InvalidArgumentException('A folded action must be materialized before projection.');
        }

        return new self(
            $state->actionId,
            $state->status,
            $state->seq,
            $specRevision,
            $state->epoch,
            $projectedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'action_id' => $this->actionId,
            'status' => $this->status->value,
            'seq' => $this->seq,
            'spec_rev' => $this->specRevision,
            'epoch' => $this->epoch > 0 ? $this->epoch : null,
            'projected_at' => $this->projectedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
