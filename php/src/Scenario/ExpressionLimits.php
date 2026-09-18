<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

/** SCN-16 through SCN-19: published, configurable, and never silently relaxed. */
final readonly class ExpressionLimits
{
    public function __construct(
        public int $maxDepth = 32,
        public int $maxLength = 512,
        public int $maxCollectionSize = 1000,
        public int $maxVisitedNodes = 10000,
    ) {
        foreach ([$maxDepth, $maxLength, $maxCollectionSize, $maxVisitedNodes] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Expression limits must be positive.');
            }
        }
    }
}
