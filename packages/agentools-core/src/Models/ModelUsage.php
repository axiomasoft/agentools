<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\Certainty;

/** Nullable observed facts. Unknown totals never become zero on cancellation or error. */
final readonly class ModelUsage
{
    public function __construct(public ?int $inputTokens = null, public ?int $outputTokens = null,
        public ?int $cacheReadTokens = null, public ?int $cacheWriteTokens = null)
    {
        foreach ([$inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens] as $value) {
            if ($value !== null && ($value < 0 || $value > 9007199254740991)) { throw new \InvalidArgumentException('Invalid usage fact'); }
        }
    }

    public function certainty(): Certainty
    {
        return $this->inputTokens === null || $this->outputTokens === null ? Certainty::Unknown : Certainty::Known;
    }

    public function mergedWith(self $update): self
    {
        return new ModelUsage($update->inputTokens ?? $this->inputTokens, $update->outputTokens ?? $this->outputTokens,
            $update->cacheReadTokens ?? $this->cacheReadTokens, $update->cacheWriteTokens ?? $this->cacheWriteTokens);
    }
}
