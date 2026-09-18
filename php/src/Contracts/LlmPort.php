<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

/**
 * Boundary for model input. Production adapters may call a model; conformance
 * and unit tests provide a deterministic script instead.
 */
interface LlmPort
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function respond(array $input): array;
}
