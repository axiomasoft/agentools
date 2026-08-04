<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Error\ToolExecutionResult;

/** The scenario runtime invokes tools through this narrow, network-agnostic port. */
interface ToolPort
{
    /** @param array<string, mixed> $arguments */
    public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult;
}
