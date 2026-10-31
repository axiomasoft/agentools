<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

final readonly class GenerationOptions
{
    public function __construct(public int $maxOutputTokens = 1024, public ?float $temperature = null,
        public ?float $topP = null)
    {
        if ($maxOutputTokens < 1 || $maxOutputTokens > 9007199254740991
            || ($temperature !== null && (!is_finite($temperature) || $temperature < 0 || $temperature > 2))
            || ($topP !== null && (!is_finite($topP) || $topP < 0 || $topP > 1))) {
            throw new \InvalidArgumentException('Invalid generation limits');
        }
    }
}
