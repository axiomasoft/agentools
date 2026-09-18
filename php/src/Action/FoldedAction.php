<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** A derived projection. It is never used as the source for appending events. */
final readonly class FoldedAction
{
    /** @var array<string, mixed> */
    public array $inputs;

    /** @var array<string, EffectRecord> */
    public array $effects;

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, EffectRecord> $effects
     */
    public function __construct(
        public ?string $actionId = null,
        public ?ActionStatus $status = null,
        public int $seq = 0,
        public int $epoch = 0,
        array $inputs = [],
        array $effects = [],
        public bool $cancellationRequested = false,
        public ?string $previousHash = null,
    ) {
        $this->inputs = $inputs;
        $this->effects = $effects;
    }

    public function hasUnresolvedEffect(): bool
    {
        foreach ($this->effects as $effect) {
            if ($effect->status === EffectStatus::Requested) {
                return true;
            }
        }

        return false;
    }
}
