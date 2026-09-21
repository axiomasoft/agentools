<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

final class ToolIdempotencyCatalog
{
    /**
     * @param array<string, ToolIdempotencyCapability> $capabilities
     * @param array<string, string> $sideEffects
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly array $sideEffects = [],
    ) {
    }

    public function capability(string $tool): ToolIdempotencyCapability
    {
        return $this->capabilities[$tool] ?? ToolIdempotencyCapability::Unknown;
    }

    public function isHighRisk(string $tool): bool
    {
        $effect = $this->sideEffects[$tool] ?? 'write';

        return !in_array($effect, ['read', 'draft'], true);
    }
}
